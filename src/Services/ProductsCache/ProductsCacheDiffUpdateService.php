<?php

namespace Eshop\Services\ProductsCache;

use Base\Bridges\AutoWireService;
use Eshop\DevelTools;
use Tracy\Debugger;
use Tracy\ILogger;

class ProductsCacheDiffUpdateService extends ProductsCacheBaseWarmUpService implements AutoWireService
{
	/**
	 * Works like warmUpCacheTable, but don't erase all data and updates only current index.
	 */
	public function warmUpCacheTableDiff(): void
	{
		$cacheIndexToBeWarmedUp = $this->getCacheIndexToBeUsed();

		if ($cacheIndexToBeWarmedUp === 0) {
			return;
		}

		try {
			$link = $this->getLink();
			$link->exec('SET SESSION group_concat_max_len=4294967295');

			$productsCacheTableName = $this->getProductsTableName($cacheIndexToBeWarmedUp);
			$visibilityPricesCacheTableName = $this->getPricesTableName($cacheIndexToBeWarmedUp);
			$categoriesTableName = $this->getCategoriesTableName($cacheIndexToBeWarmedUp);

			// Start tracking
			Debugger::timer();

			[
				$allCategoryTypes,
				$allDisplayAmounts,
				$allCategories,
				$allProductPrimaryCategories,
				$productPrimaryCategories,
				$productAttributeValues,
				$productCategories,
			] = $this->getPrefetchedArrays();
			Debugger::dump('Prefetch before main table: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage());

			// update products table - compare cache vs live data
			Debugger::timer();
			$productsByCategories = $this->diffUpdateMainTable(
				$productsCacheTableName,
				$allCategoryTypes,
				$allDisplayAmounts,
				$allCategories,
				$allProductPrimaryCategories,
				$productPrimaryCategories,
				$productAttributeValues,
				$productCategories,
			);
			Debugger::dump('diffUpdateMainTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage());

			$this->diffUpdateRelations($cacheIndexToBeWarmedUp);
			Debugger::dump('diffUpdateRelations: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage());

			$this->createCategoriesTable($categoriesTableName);
			Debugger::dump('createCategoriesTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage());

			$this->insertCategoriesTable($categoriesTableName, $productsByCategories, $allCategories);
			Debugger::dump('insertCategoriesTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage());

			$this->indexCategoriesTable($categoriesTableName, $productsCacheTableName);
			Debugger::dump('indexCategoriesTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage());

			$this->diffUpdateVisibilityPriceTable($visibilityPricesCacheTableName);
			Debugger::dump('diffUpdateVisibilityPriceTable: ' . Debugger::timer() . ', ' . DevelTools::getPeakMemoryUsage());

			$this->cleanProductsProviderCache();
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::EXCEPTION);
			Debugger::dump($e);

			throw $e;
		}
	}

	/**
	 * @param string $productsCacheTableName
	 * @param array<mixed> $allCategoryTypes
	 * @param array<mixed> $allDisplayAmounts
	 * @param array<mixed> $allCategories
	 * @param array<mixed> $allProductPrimaryCategories
	 * @param array<mixed> $productPrimaryCategories
	 * @param array<mixed> $productAttributeValues
	 * @param array<mixed> $productCategories
	 * @return array<mixed>
	 */
	protected function diffUpdateMainTable(
		string $productsCacheTableName,
		array $allCategoryTypes,
		array $allDisplayAmounts,
		array $allCategories,
		array $allProductPrimaryCategories,
		array $productPrimaryCategories,
		array $productAttributeValues,
		array $productCategories,
	): array {
		$currentColumns = $this->getLink()->query("SHOW COLUMNS FROM `$productsCacheTableName` LIKE 'primaryCategory_%'")->fetchAll(\PDO::FETCH_COLUMN);

		foreach ($allCategoryTypes as $categoryType) {
			if (($key = \array_search("primaryCategory_$categoryType->id", $currentColumns)) !== false) {
				unset($currentColumns[$key]);

				continue;
			}

			$this->getLink()->exec("ALTER TABLE $productsCacheTableName ADD primaryCategory_{$categoryType->id} INT UNSIGNED ALGORITHM=INSTANT;");
			$this->getLink()->exec("ALTER TABLE `$productsCacheTableName` ADD INDEX idx_primaryCategory_{$categoryType->id} (primaryCategory_{$categoryType->id}) ALGORITHM=INPLACE;");
		}

		foreach ($currentColumns as $currentColumn) {
			$this->getLink()->exec("ALTER TABLE `$productsCacheTableName` DROP INDEX `idx_$currentColumn` ALGORITHM=INSTANT;");
			$this->getLink()->exec("ALTER TABLE `$productsCacheTableName` DROP COLUMN `$currentColumn` ALGORITHM=INPLACE;");
		}

		$mutationSuffix = $this->getMutationSuffix();

		$productsCollection = $this->productRepository->many()
			->join(['masterProduct' => 'eshop_product'], 'this.fk_masterProduct = masterProduct.uuid')
			->join(['price' => 'eshop_price'], 'this.uuid = price.fk_product', type: 'INNER')
			->join(['eshop_displayamount'], 'this.fk_displayAmount = eshop_displayamount.uuid')
			->join(['eshop_displaydelivery'], 'this.fk_displayDelivery = eshop_displaydelivery.uuid')
			->join(['eshop_producer'], 'this.fk_producer = eshop_producer.uuid')
			->setSelect([
				'id' => 'this.id',
				'fkDisplayAmount' => 'eshop_displayamount.id',
				'fkDisplayDelivery' => 'eshop_displaydelivery.id',
				'fkProducer' => 'eshop_producer.id',
				'fkMasterProduct' => 'masterProduct.id',
				'name' => "this.name$mutationSuffix",
				'code' => 'this.code',
				'subCode' => 'this.subCode',
				'externalCode' => 'this.externalCode',
				'ean' => 'COALESCE(this.secondaryEan, this.ean)',
			])
			->setGroupBy(['this.id']);

		$productsCacheArray = $this->connection->rows([$productsCacheTableName])
			->setIndex('product')
			->toArray();

		$productsCacheArray = \array_map(function ($item) {
			return (array) $item;
		}, $productsCacheArray);

		$productsToBeInCache = [];
		$productsToCreate = [];
		$productsToUpdate = [];
		$allCategoriesByCategory = [];
		$productsByCategories = [];

		while ($product = $productsCollection->fetch(\stdClass::class)) {
			/** @var \stdClass $product */

			$productData = [
				'product' => $product->id,
				'producer' => $product->fkProducer,
				'displayAmount' => $product->fkDisplayAmount,
				'displayDelivery' => $product->fkDisplayDelivery,
				'displayAmount_isSold' => $product->fkDisplayAmount ? ((int) $allDisplayAmounts[$product->fkDisplayAmount]->isSold) : null,
				'attributeValues' => $productAttributeValues[$product->id] ?? null,
				'name' => $product->name,
				'code' => $product->code ? (string) $product->code : null,
				'subCode' => $product->subCode ? (string) $product->subCode : null,
				'externalCode' => $product->externalCode ? (string) $product->externalCode : null,
				'ean' => $product->ean ? (string) $product->ean : null,
				'masterProduct' => $product->fkMasterProduct,
			];

			$primaryCategories = isset($productPrimaryCategories[$product->id]) ? \explode(',', $productPrimaryCategories[$product->id]) : [];

			foreach ($primaryCategories as $primaryCategory) {
				$primaryCategory = $allProductPrimaryCategories[$primaryCategory];

				$productData["primaryCategory_$primaryCategory->categoryType"] = $primaryCategory->category;
			}

			foreach ($allCategoryTypes as $categoryType) {
				$primaryCategoryTypeIndex = "primaryCategory_$categoryType->id";

				if (isset($productData[$primaryCategoryTypeIndex])) {
					continue;
				}

				$productData[$primaryCategoryTypeIndex] = null;
			}

			// @TODO Add control of Category showDescendantProducts
			if ($categories = ($productCategories[$product->id] ?? null)) {
				$categories = \explode(',', $categories);

				foreach ($categories as $category) {
					$categoryCategories = $allCategoriesByCategory[$category] ?? null;

					if ($categoryCategories === null) {
						$categoryCategories = $allCategoriesByCategory[$category] = \array_merge($this->getAncestorsOfCategory($category, $allCategories), [$category]);
					}

					$productData['categories'] = \array_unique(\array_merge($productData['categories'] ?? [], $categoryCategories));

					foreach ($productData['categories'] as $productCategory) {
						$productsByCategories[$productCategory][$product->id] = true;
					}
				}
			}

			unset($productData['categories']);
			$productsToBeInCache[] = $product->id;

			$cacheProductData = $productsCacheArray[$product->id] ?? null;

			if ($cacheProductData) {
				$diff = \array_diff_assoc($productData, $cacheProductData);

				if ($diff) {
					$productsToUpdate[$product->id] = $diff;
				}
			} else {
				$productsToCreate[$product->id] = $productData;
			}
		}

		Debugger::dump('diffUpdateMainTable -- created: ' . $this->connection->createRows($productsCacheTableName, \array_values($productsToCreate), chunkSize: 1000)->getRowCount());

		$updatedCount = 0;

		foreach (\array_chunk($productsToUpdate, 1000, true) as $chunk) {
			$this->getLink()->beginTransaction();

			foreach ($chunk as $product => $row) {
				$updatedCount += $this->connection->rows([$productsCacheTableName])->where('product', $product)->update($row);
			}

			$this->getLink()->commit();
		}

		Debugger::dump('diffUpdateMainTable -- updated: ' . $updatedCount);
		\sort($productsToBeInCache);
		\ksort($productsCacheArray);

		$productsToDelete = \array_diff(\array_keys($productsCacheArray), $productsToBeInCache);

		if ($productsToDelete) {
			Debugger::dump('diffUpdateMainTable -- deleted: ' . $this->connection->rows([$productsCacheTableName])
					->where('product', \array_values($productsToDelete))
					->delete());
		}

		return $productsByCategories;
	}

	protected function diffUpdateVisibilityPriceTable(string $pricesCacheTableName): void
	{
		Debugger::timer('getAllPossibleVisibilityAndPriceListOptions');
		[$visibilityPriceListsOptions, $allVisibilityLists, $allPriceLists] = $this->getAllPossibleVisibilityAndPriceListOptions();
		Debugger::dump(
			'insertVisibilityPriceTable -- getAllPossibleVisibilityAndPriceListOptions: ' . Debugger::timer('getAllPossibleVisibilityAndPriceListOptions') .
			', ' . DevelTools::getPeakMemoryUsage()
		);

		Debugger::timer('insertVisibilityPriceTable -- prefetch');

		/** @var array<int, array<int, \stdClass>> $allProductsWithVLI */
		$allProductsWithVLI = [];
		$allProductsWithVLIQuery = $this->visibilityListItemRepository->many()
			->join(['visibilityList' => 'eshop_visibilitylist'], 'this.fk_visibilityList = visibilityList.uuid', type: 'INNER')
			->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid', type: 'INNER')
			->where('visibilityList.id', $allVisibilityLists)
			->setSelect([
				'this.hidden',
				'this.hiddenInMenu',
				'this.priority',
				'this.unavailable',
				'this.recommended',
				'productId' => 'product.id',
				'visibilityListId' => 'visibilityList.id',
			])
			->setIndex('product.id')
			->orderBy(['product.id' => 'ASC', 'visibilityList.priority' => 'ASC']);

		while ($item = $allProductsWithVLIQuery->fetch(\stdClass::class)) {
			/** @var \stdClass $item */

			$allProductsWithVLI[$item->productId][$item->visibilityListId] = $item;
		}

		$allProductsWithVLIQuery->__destruct();
		unset($allProductsWithVLIQuery);

		/** @var array<int, array<int, \stdClass>> $allProductsWithPrice */
		$allProductsWithPrice = [];
		$allProductsWithPriceQuery = $this->priceRepository->many()
			->join(['priceList' => 'eshop_pricelist'], 'this.fk_pricelist = priceList.uuid', type: 'INNER')
			->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid', type: 'INNER')
			->where('priceList.id', $allPriceLists)
			->where('this.hidden', false)
			->setSelect([
				'this.price',
				'this.priceVat',
				'this.priceBefore',
				'this.priceVatBefore',
				'productId' => 'product.id',
				'priceListId' => 'priceList.id',
			])
			->setIndex('product.id')
			->orderBy(['product.id' => 'ASC', 'priceList.priority' => 'ASC']);

		while ($item = $allProductsWithPriceQuery->fetch(\stdClass::class)) {
			/** @var \stdClass $item */

			$allProductsWithPrice[$item->productId][$item->priceListId] = $item;
		}

		$allProductsWithPriceQuery->__destruct();
		unset($allProductsWithPriceQuery);

		Debugger::dump('insertVisibilityPriceTable -- prefetch: ' . Debugger::timer('insertVisibilityPriceTable -- prefetch') . ', ' . DevelTools::getPeakMemoryUsage());

		Debugger::timer('insertVisibilityPriceTable -- main while');

		$cacheSelectTime = 0;

		foreach (\array_keys($visibilityPriceListsOptions) as $index) {
			$pricesToCreate = [];
			$pricesToUpdate = [];
			$productsToBeInCache = [];

			Debugger::timer('cacheSelectTime');
			$cachePrices = $this->connection->rows([$pricesCacheTableName])
				->setIndex('product')
				->where('visibilityPriceIndex', $index)
				->toArray();
			$cacheSelectTime += Debugger::timer('cacheSelectTime');

			$explodedIndex = \explode('-', $index);

			if (\count($explodedIndex) !== 2) {
				continue;
			}

			[$visibilityListsString, $priceListsString] = $explodedIndex;
			/** @var array<int> $visibilityLists */
			$visibilityLists = \explode(',', $visibilityListsString);
			/** @var array<int> $priceLists */
			$priceLists = \explode(',', $priceListsString);

			foreach ($allProductsWithVLI as $product => $vliItems) {
				foreach ($visibilityLists as $visibilityListId) {
					if (!isset($vliItems[$visibilityListId])) {
						continue;
					}

					$visibilityListItem = $vliItems[$visibilityListId];

					if (!isset($allProductsWithPrice[$product])) {
						continue;
					}

					$priceItems = $allProductsWithPrice[$product];

					foreach ($priceLists as $priceListId) {
						if (!isset($priceItems[$priceListId])) {
							continue;
						}

						$cachePrice = $cachePrices[$product] ?? null;
						$price = $priceItems[$priceListId];

						$newPrice = [
							'visibilityPriceIndex' => $index,
							'product' => $product,
							'price' => $price->price,
							'priceVat' => $price->priceVat,
							'priceBefore' => $price->priceBefore ?: null,
							'priceVatBefore' => $price->priceVatBefore ?: null,
							'priceList' => $priceListId,
							'hidden' => $visibilityListItem->hidden,
							'hiddenInMenu' => $visibilityListItem->hiddenInMenu,
							'priority' => $visibilityListItem->priority,
							'unavailable' => $visibilityListItem->unavailable,
							'recommended' => $visibilityListItem->recommended,
						];

						$productsToBeInCache[] = $product;

						if ($cachePrice) {
							$diff = \array_diff_assoc($newPrice, (array) $cachePrice);

							if ($diff) {
								$pricesToUpdate[$product] = $diff;
							}
						} else {
							$pricesToCreate[$product] = $newPrice;
						}

						break 2;
					}
				}
			}

			foreach (\array_chunk($pricesToUpdate, 1000, true) as $chunk) {
				$this->getLink()->beginTransaction();

				foreach ($chunk as $product => $row) {
					$this->connection->rows([$pricesCacheTableName])
						->where('visibilityPriceIndex', $index)
						->where('product', $product)
						->update($row);
				}

				$this->getLink()->commit();
			}

			if ($pricesToCreate) {
				$this->connection->createRows($pricesCacheTableName, $pricesToCreate, chunkSize: 1000);
			}

			\sort($productsToBeInCache);
			\ksort($cachePrices);

			$productsToDelete = \array_diff(\array_keys($cachePrices), $productsToBeInCache);

			if (!$productsToDelete) {
				continue;
			}

			$this->connection->rows([$pricesCacheTableName])
					->where('visibilityPriceIndex', $index)
					->where('product', \array_values($productsToDelete))
					->delete();
		}

		Debugger::dump($cacheSelectTime);

		Debugger::dump('insertVisibilityPriceTable -- main while: ' . Debugger::timer('insertVisibilityPriceTable -- main while'));
	}

	protected function diffUpdateRelations(int $cacheIndexToBeWarmedUp): void
	{
		$link = $this->getLink();
		$relationsCacheTableName = "eshop_products_relations_cache_$cacheIndexToBeWarmedUp";

		$relations = $this->relatedRepository->many()
			->join(['type' => 'eshop_relatedtype'], 'this.fk_type = type.uuid')
			->join(['masterProduct' => 'eshop_product'], 'this.fk_master = masterProduct.uuid')
			->join(['slaveProduct' => 'eshop_product'], 'this.fk_slave = slaveProduct.uuid')
			->select([
				'typeId' => 'type.id',
				'masterId' => 'masterProduct.id',
				'slaveId' => 'slaveProduct.id',
			]);

		$rowsToInsert = [];
		$chunkCounter = 0;

		foreach ($relations as $relation) {
			$rowsToInsert[] = [
				'master' => $relation->getValue('masterId'),
				'slave' => $relation->getValue('slaveId'),
				'type' => $relation->getValue('typeId'),
				'priority' => $relation->priority,
				'amount' => $relation->amount,
				'hidden' => $relation->hidden,
				'systemic' => $relation->isSystemic(),
				'discountPct' => $relation->discountPct,
				'masterPct' => $relation->masterPct,
			];

			$chunkCounter++;

			if ($chunkCounter !== 1000) {
				continue;
			}

			$chunkCounter = 0;

			$this->connection->createRows($relationsCacheTableName, $rowsToInsert, chunkSize: 1000);
			$rowsToInsert = [];
		}

		$this->connection->createRows($relationsCacheTableName, $rowsToInsert, chunkSize: 1000);
		unset($rowsToInsert);

//      $link->exec("CREATE INDEX idx_master ON `$relationsCacheTableName` (master);");
//      $link->exec("CREATE INDEX idx_slave ON `$relationsCacheTableName` (slave);");
//      $link->exec("CREATE INDEX idx_type ON `$relationsCacheTableName` (type);");
		$link->exec("CREATE INDEX idx_related_master ON `$relationsCacheTableName` (master, type);");
		$link->exec("CREATE INDEX idx_related_slave ON `$relationsCacheTableName` (slave, type);");
		$link->exec("CREATE INDEX idx_products_related_unique ON `$relationsCacheTableName` (master, slave);");
		$link->exec("CREATE UNIQUE INDEX idx_related_code ON `$relationsCacheTableName` (master, slave, amount, discountPct, masterPct);");
	}
}
