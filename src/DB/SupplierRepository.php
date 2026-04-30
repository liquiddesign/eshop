<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\DB\Shop;
use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\DIConnection;
use StORM\Repository;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Supplier>
 */
class SupplierRepository extends Repository implements IGeneralRepository
{
	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		private readonly SupplierProductRepository $supplierProductRepository,
		private readonly PriceRepository $priceRepository,
		private readonly SupplierCategoryRepository $supplierCategoryRepository,
		private readonly ImportResultRepository $importResultRepository
	) {
		parent::__construct($connection, $schemaManager);
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		unset($includeHidden);

		return $this->many()->orderBy(['name'])->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('hidden', false);
		}

		return $collection->orderBy(['priority', 'name']);
	}

	public function catalogEntry(Supplier $supplier, $logDirectory, bool $onlyNew = false, bool $importImages = true): void
	{
		$this->importResultRepository->createLog($supplier, $logDirectory, 'entry');

		$currency = 'CZK';
		$mutation = 'cs';
		$country = 'CZ';

		$result = $this->supplierProductRepository->syncProducts($supplier, $mutation, $country, !$onlyNew, $importImages);

		$this->importResultRepository->log("Products entered: inserted: $result[inserted], updated: $result[updated], locked: $result[locked]");

		$this->priceRepository->many()
			->join(['pricelist' => 'eshop_pricelist'], 'pricelist.uuid=this.fk_pricelist')
			->where('pricelist.fk_supplier', $supplier)
			->delete();

		$counts = $this->syncPricelistsAndPricesForRange(
			$supplier,
			$currency,
			$country,
			fn (Collection $c): Collection => $c->where('fk_supplier', $supplier),
		);
		$availablePriceCount = $counts['availablePriceCount'];
		$unavailablePriceCount = $counts['unavailablePriceCount'];

		if (!$this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('purchasePrice IS NOT NULL')->isEmpty()) {
			$pricelist = $this->syncPricelist($supplier, $currency, $country, '3', 3, false, 'Nákupní');
			$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('purchasePrice IS NOT NULL'), $supplier, $pricelist);
		}

		$this->importResultRepository->log("Pricelist entered: available: $availablePriceCount, unavailable: $unavailablePriceCount");

		$total = 0;

		$store = $this->syncStore($supplier, $mutation);

		if (!$this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('amount IS NOT NULL')->isEmpty()) {
			$total = $this->supplierProductRepository->syncAmounts($this->supplierProductRepository->many()
				->where('fk_supplier', $supplier)
				->where('amount IS NOT NULL')
				->where('fk_product IS NOT NULL'), $store);
		}

		$this->importResultRepository->log("Store entered: total: $total");

		$this->supplierCategoryRepository->syncAttributeCategoryAssigns($supplier);

		$this->importResultRepository->markAsEntered($result['inserted'], $result['updated'], $result['locked'], $result['images']);
	}

	/**
	 * Vytvoří/aktualizuje pricelisty a syncne ceny pro zadaný rozsah SupplierProduct záznamů.
	 * Respektuje 3 režimy: shop-split (importPriceRatioAbel/Rt → per-shop pricelisty),
	 * splitPricelists (2 = dostupné, 1 = nedostupné) a simple (0).
	 * @param \Closure(\StORM\Collection): \StORM\Collection $applyBaseFilter aplikuje filter na `supplierproduct` range (např. fk_supplier + whitelist UUID)
	 * @return array{availablePriceCount: int|null, unavailablePriceCount: int|null}
	 */
	public function syncPricelistsAndPricesForRange(
		Supplier $supplier,
		string $currency,
		string $country,
		\Closure $applyBaseFilter,
	): array {
		$availablePriceCount = null;
		$unavailablePriceCount = null;
		$splitByShop = $supplier->importPriceRatioAbel !== null || $supplier->importPriceRatioRt !== null;

		if ($splitByShop) {
			$ratioAbel = $supplier->importPriceRatioAbel ?? $supplier->importPriceRatio;
			$ratioRt = $supplier->importPriceRatioRt ?? $supplier->importPriceRatio;

			$applyBaseFilter($this->supplierProductRepository->many())
				->update([
					'priceAbel' => new \StORM\Literal("ROUND(price * $ratioAbel / 100, 2)"),
					'priceAbelVat' => new \StORM\Literal("ROUND(priceVat * $ratioAbel / 100, 2)"),
					'priceRt' => new \StORM\Literal("ROUND(price * $ratioRt / 100, 2)"),
					'priceRtVat' => new \StORM\Literal("ROUND(priceVat * $ratioRt / 100, 2)"),
				]);

			$shopRepository = $this->getConnection()->findRepository(Shop::class);
			$abelShop = $shopRepository->one('abel', false);
			$rtShop = $shopRepository->one('rt', false);

			$shopTargets = [];

			if ($abelShop !== null) {
				$shopTargets[] = ['abel', $abelShop, 'priceAbel'];
			}

			if ($rtShop !== null) {
				$shopTargets[] = ['rt', $rtShop, 'priceRt'];
			}

			foreach ($shopTargets as [$shopCode, $shop, $priceProperty]) {
				if ($supplier->splitPricelists) {
					$pricelist = $this->syncPricelist($supplier, $currency, $country, "$shopCode-2", 3, true, null, $shop);
					$count = $this->supplierProductRepository->syncPrices(
						$applyBaseFilter($this->supplierProductRepository->many())->where('amount IS NULL OR amount > 0'),
						$supplier,
						$pricelist,
						$priceProperty,
						2,
						100,
					);

					if ($shopCode === 'abel') {
						$availablePriceCount = $count;
					}

					$pricelist = $this->syncPricelist($supplier, $currency, $country, "$shopCode-1", 4, true, 'Nedostupné', $shop);
					$count = $this->supplierProductRepository->syncPrices(
						$applyBaseFilter($this->supplierProductRepository->many())->where('amount = 0'),
						$supplier,
						$pricelist,
						$priceProperty,
						2,
						100,
					);

					if ($shopCode === 'abel') {
						$unavailablePriceCount = $count;
					}
				} else {
					$pricelist = $this->syncPricelist($supplier, $currency, $country, "$shopCode-0", 3, true, null, $shop);
					$this->supplierProductRepository->syncPrices(
						$applyBaseFilter($this->supplierProductRepository->many()),
						$supplier,
						$pricelist,
						$priceProperty,
						2,
						100,
					);
				}
			}
		} elseif ($supplier->splitPricelists) {
			$pricelist = $this->syncPricelist($supplier, $currency, $country, '2', 3, true);
			$availablePriceCount = $this->supplierProductRepository->syncPrices(
				$applyBaseFilter($this->supplierProductRepository->many())->where('amount IS NULL OR amount > 0'),
				$supplier,
				$pricelist,
			);

			$pricelist = $this->syncPricelist($supplier, $currency, $country, '1', 4, true, 'Nedostupné');
			$unavailablePriceCount = $this->supplierProductRepository->syncPrices(
				$applyBaseFilter($this->supplierProductRepository->many())->where('amount = 0'),
				$supplier,
				$pricelist,
			);
		} else {
			$pricelist = $this->syncPricelist($supplier, $currency, $country, '0', 3, true);
			$this->supplierProductRepository->syncPrices(
				$applyBaseFilter($this->supplierProductRepository->many()),
				$supplier,
				$pricelist,
			);
		}

		return ['availablePriceCount' => $availablePriceCount, 'unavailablePriceCount' => $unavailablePriceCount];
	}

	public function syncPricelist(
		Supplier $supplier,
		string $currency,
		string $country,
		string $id,
		int $priority,
		bool $active,
		string|null $label = null,
		Shop|null $shop = null,
	): Pricelist {
		/** @var \Eshop\DB\PricelistRepository $pricelistRepository */
		$pricelistRepository = $this->getConnection()->findRepository(Pricelist::class);

		return $pricelistRepository->syncOne([
			'uuid' => DIConnection::generateUuid($supplier->getPK(), $id),
			'code' => "$supplier->code-$id",
			'name' => $supplier->name . ($label === null ? '' : " ($label)"),
			'isActive' => $active,
			'isReadonly' => true,
			'currency' => $currency,
			'country' => $country,
			'supplier' => $supplier,
			'shop' => $shop,
			'priority' => $priority,
		], ['currency', 'country']);
	}

	public function syncStore(Supplier $supplier, string $mutation, string $id = '1', ?string $label = null): Store
	{
		/** @var \Eshop\DB\StoreRepository $storeRepository */
		$storeRepository = $this->getConnection()->findRepository(Store::class);

		return $storeRepository->syncOne([
			'uuid' => DIConnection::generateUuid($supplier->getPK(), $id),
			'code' => "$supplier->code-$id",
			'name' => [$mutation => $supplier->name . ($label === null ? '' : " ($label)")],
			'supplier' => $supplier,
		], []);
	}
}
