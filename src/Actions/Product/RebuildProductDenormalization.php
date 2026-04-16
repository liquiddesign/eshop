<?php

declare(strict_types=1);

namespace Eshop\Actions\Product;

use Base\Bridges\AutoWireAction;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ProductRepository;
use Nette\Utils\Strings;
use StORM\DIConnection;
use Tracy\Debugger;

/**
 * Přebuduje denormalizované sloupce na produktech používané LiveProductsProvider:
 *   - `denormalizedAttributeValues` — CSV UUIDů hodnot atributů (přímé vazby z eshop_attributeassign).
 *   - `denormalizedCategories` — CSV UUIDů kategorií, ve kterých se produkt má zobrazit.
 *     Zahrnuje:
 *       a) přímé kategorie produktu (z eshop_product_nxn_eshop_category),
 *       b) ancestors přímých kategorií, pokud
 *          přímá kategorie má `showProductsInAncestors = 1`
 *          AND ancestor má `showDescendantProducts = 1`.
 *     Stejná logika jako ProductsCacheGetterService::getProductsFromCacheTable používá pro
 *     descendant resolution, jen aplikovaná inverzně při denormalizaci.
 */
readonly class RebuildProductDenormalization implements AutoWireAction
{
	public function __construct(
		private DIConnection $connection,
		private ProductRepository $productRepository,
		private CategoryRepository $categoryRepository,
	) {
	}

	/**
	 * @param int $batchSize Počet produktů zpracovaných v jedné transakci (default 2000).
	 * @return array{attributesUpdated: int, categoriesUpdated: int, timeMs: float}
	 */
	public function execute(int $batchSize = 2000): array
	{
		Debugger::timer('rebuildProductDenormalization');

		$attributesUpdated = $this->rebuildAttributeValues($batchSize);
		$categoriesUpdated = $this->rebuildCategories($batchSize);
		$ribbonsUpdated = $this->rebuildRibbons($batchSize);
		$internalRibbonsUpdated = $this->rebuildInternalRibbons($batchSize);

		$timeMs = (float) Debugger::timer('rebuildProductDenormalization') * 1000;

		return [
			'attributesUpdated' => $attributesUpdated,
			'categoriesUpdated' => $categoriesUpdated,
			'ribbonsUpdated' => $ribbonsUpdated,
			'internalRibbonsUpdated' => $internalRibbonsUpdated,
			'timeMs' => $timeMs,
		];
	}

	/**
	 * Spočítá CSV UUIDů attribute values per produkt a uloží do denormalizedAttributeValues.
	 * Single-pass SQL via GROUP_CONCAT + batch UPDATE.
	 */
	private function rebuildAttributeValues(int $batchSize): int
	{
		$rows = $this->connection->rows(['eshop_attributeassign'])
			->setSelect([
				'product' => 'fk_product',
				'attributeValuesCsv' => 'GROUP_CONCAT(fk_value)',
			])
			->setGroupBy(['fk_product'])
			->fetchArray(\stdClass::class);

		$newValues = [];

		foreach ($rows as $row) {
			$newValues[$row->product] = $row->attributeValuesCsv;
		}

		$currentValues = $this->productRepository->many()
			->setSelect(['this.uuid', 'this.denormalizedAttributeValues'])
			->setIndex('this.uuid')
			->toArrayOf('denormalizedAttributeValues');

		$updated = 0;

		foreach (\array_chunk(\array_keys($currentValues + $newValues), \max($batchSize, 1)) as $chunk) {
			$this->connection->beginTransaction();

			foreach ($chunk as $productPK) {
				$newCsv = $newValues[$productPK] ?? null;
				$oldCsv = $currentValues[$productPK] ?? null;

				if ($newCsv === $oldCsv) {
					continue;
				}

				$this->productRepository->many()
					->where('this.uuid', $productPK)
					->update(['denormalizedAttributeValues' => $newCsv]);

				$updated++;
			}

			$this->connection->commit();
		}

		return $updated;
	}

	/**
	 * Spočítá CSV UUIDů kategorií per produkt (direct + applicable ancestors) a uloží do denormalizedCategories.
	 */
	private function rebuildCategories(int $batchSize): int
	{
		/** @var array<string, \Eshop\DB\Category> $allCategories */
		$allCategories = $this->categoryRepository->many()
			->setSelect([
				'this.uuid',
				'this.path',
				'this.showDescendantProducts',
				'this.showProductsInAncestors',
			])
			->setIndex('this.uuid')
			->toArray();

		$expandedByDirect = $this->buildDirectCategoryExpansionMap($allCategories);

		$rows = $this->connection->rows(['eshop_product_nxn_eshop_category'])
			->setSelect([
				'product' => 'fk_product',
				'categories' => 'GROUP_CONCAT(fk_category)',
			])
			->setGroupBy(['fk_product'])
			->fetchArray(\stdClass::class);

		$newValues = [];

		foreach ($rows as $row) {
			$direct = \explode(',', (string) $row->categories);
			$resolved = [];

			foreach ($direct as $directCategory) {
				if (!isset($expandedByDirect[$directCategory])) {
					continue;
				}

				foreach ($expandedByDirect[$directCategory] as $uuid) {
					$resolved[$uuid] = true;
				}
			}

			$newValues[$row->product] = $resolved ? \implode(',', \array_keys($resolved)) : null;
		}

		$currentValues = $this->productRepository->many()
			->setSelect(['this.uuid', 'this.denormalizedCategories'])
			->setIndex('this.uuid')
			->toArrayOf('denormalizedCategories');

		$updated = 0;

		foreach (\array_chunk(\array_keys($currentValues + $newValues), \max($batchSize, 1)) as $chunk) {
			$this->connection->beginTransaction();

			foreach ($chunk as $productPK) {
				$newCsv = $newValues[$productPK] ?? null;
				$oldCsv = $currentValues[$productPK] ?? null;

				if ($newCsv === $oldCsv) {
					continue;
				}

				$this->productRepository->many()
					->where('this.uuid', $productPK)
					->update(['denormalizedCategories' => $newCsv]);

				$updated++;
			}

			$this->connection->commit();
		}

		return $updated;
	}

	/**
	 * Pro každou kategorii vrátí seznam UUIDů, které mají být v denormalizedCategories produktu,
	 * pokud je produkt přímo přiřazen k této kategorii.
	 * Pravidlo: direct + ancestors, kde (direct.showProductsInAncestors = 1 AND ancestor.showDescendantProducts = 1).
	 * @param array<string, \Eshop\DB\Category> $allCategories Index podle UUID.
	 * @return array<string, list<string>>
	 */
	private function buildDirectCategoryExpansionMap(array $allCategories): array
	{
		$pathByUuid = [];

		foreach ($allCategories as $uuid => $category) {
			$pathByUuid[$uuid] = $category->path;
		}

		$uuidByPath = \array_flip($pathByUuid);

		$expansion = [];

		foreach ($allCategories as $directUuid => $directCategory) {
			$uuids = [$directUuid];

			if ($directCategory->showProductsInAncestors) {
				$path = $directCategory->path;

				// Cesta je řetězec segmentů o pevné délce (např. '00000100020003').
				// Ancestors získáme odříznutím posledního segmentu postupně.
				$segmentLength = 4;

				while (Strings::length($path) > $segmentLength) {
					$path = Strings::substring($path, 0, -$segmentLength);

					if (!isset($uuidByPath[$path])) {
						continue;
					}

					$ancestorUuid = $uuidByPath[$path];
					$ancestor = $allCategories[$ancestorUuid] ?? null;

					if ($ancestor === null || !$ancestor->showDescendantProducts) {
						continue;
					}

					$uuids[] = $ancestorUuid;
				}
			}

			$expansion[$directUuid] = $uuids;
		}

		return $expansion;
	}

	/**
	 * Spočítá CSV UUIDů ribbonů per produkt a uloží do denormalizedRibbons.
	 */
	private function rebuildRibbons(int $batchSize): int
	{
		return $this->rebuildNxnDenormalization(
			'eshop_product_nxn_eshop_ribbon',
			'fk_ribbon',
			'denormalizedRibbons',
			$batchSize,
		);
	}

	/**
	 * Spočítá CSV UUIDů interních ribbonů per produkt a uloží do denormalizedInternalRibbons.
	 */
	private function rebuildInternalRibbons(int $batchSize): int
	{
		return $this->rebuildNxnDenormalization(
			'eshop_product_nxn_eshop_internalribbon',
			'fk_internalribbon',
			'denormalizedInternalRibbons',
			$batchSize,
		);
	}

	/**
	 * Generická metoda pro denormalizaci NxN tabulky do CSV sloupce na produktu.
	 */
	private function rebuildNxnDenormalization(string $nxnTable, string $fkColumn, string $productColumn, int $batchSize): int
	{
		$rows = $this->connection->rows([$nxnTable])
			->setSelect([
				'product' => 'fk_product',
				'csv' => 'GROUP_CONCAT(' . $fkColumn . ')',
			])
			->setGroupBy(['fk_product'])
			->fetchArray(\stdClass::class);

		$newValues = [];

		foreach ($rows as $row) {
			$newValues[$row->product] = $row->csv;
		}

		$currentValues = $this->productRepository->many()
			->setSelect(['this.uuid', 'this.' . $productColumn])
			->setIndex('this.uuid')
			->toArrayOf($productColumn);

		$updated = 0;

		foreach (\array_chunk(\array_keys($currentValues + $newValues), \max($batchSize, 1)) as $chunk) {
			$this->connection->beginTransaction();

			foreach ($chunk as $productPK) {
				$newCsv = $newValues[$productPK] ?? null;
				$oldCsv = $currentValues[$productPK] ?? null;

				if ($newCsv === $oldCsv) {
					continue;
				}

				$this->productRepository->many()
					->where('this.uuid', $productPK)
					->update([$productColumn => $newCsv]);

				$updated++;
			}

			$this->connection->commit();
		}

		return $updated;
	}
}
