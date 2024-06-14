<?php

namespace Eshop\Services\ProductsCache;

use Base\Bridges\AutoWireService;
use Eshop\DB\Customer;
use Eshop\DB\PriceRepository;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\ShopperUser;
use StORM\DIConnection;
use Tracy\Debugger;
use Tracy\ILogger;

readonly class ProductsCacheUpdateService implements AutoWireService
{
	public function __construct(
		protected DIConnection $connection,
		protected ProductsCacheWarmUpService $productsCacheWarmUpService,
		protected ProductsCacheGetterService $productsCacheGetter,
		protected VisibilityListItemRepository $visibilityListItemRepository,
		protected PriceRepository $priceRepository,
		protected ShopperUser $shopperUser,
	) {
	}

	public function updateCustomerVisibilitiesAndPrices(Customer $customer, bool $useTransaction = true): void
	{
		$usedCacheIndex = $this->productsCacheGetter->getCacheIndexToBeUsed();

		if ($usedCacheIndex === 0) {
			return;
		}

		$link = $this->connection->getLink();
		[$visibilityPriceListsOptions, $allVisibilityLists, $allPriceLists] = $this->productsCacheWarmUpService->getAllPossibleVisibilityAndPriceListOptions([$customer->getPK()], []);

		$canStartTransaction = $useTransaction && $this->waitForTransaction($link);

		if ($canStartTransaction) {
			$link->beginTransaction();
		}

		try {
			foreach (\array_keys($visibilityPriceListsOptions) as $option) {
				$this->updateIndex($option, $usedCacheIndex, $allVisibilityLists, $allPriceLists);
			}

			if ($canStartTransaction) {
				$link->commit();
			}
		} catch (\Exception $e) {
			Debugger::barDump($e);
			Debugger::log($e, ILogger::EXCEPTION);

			/** @phpstan-ignore-next-line */
			if ($canStartTransaction) {
				$link->rollBack();
			}
		}
	}

	/**
	 * @param string $index
	 * @param int $usedCacheIndex
	 * @param array<int> $allVisibilityLists
	 * @param array<int> $allPriceLists
	 */
	public function updateIndex(string $index, int $usedCacheIndex, array $allVisibilityLists, array $allPriceLists): void
	{
		$visibilityPricesCacheTableName = $this->productsCacheWarmUpService->getPricesTableName($usedCacheIndex);

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

		$this->connection->rows([$visibilityPricesCacheTableName])->where('visibilityPriceIndex', $index)->delete();

		$explodedIndex = \explode('-', $index);

		if (\count($explodedIndex) !== 2) {
			throw new \Exception("Invalid index '$index'");
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

					$price = $priceItems[$priceListId];

					if (!$this->shopperUser->getShowZeroPrices() && !$price->price > 0) {
						continue;
					}

					$this->connection->syncRow($visibilityPricesCacheTableName, [
						'visibilityPriceIndex' => $index,
						'product' => $product,
						'price' => $price->price,
						'priceVat' => $price->priceVat,
						'priceBefore' => $price->priceBefore,
						'priceVatBefore' => $price->priceVatBefore,
						'priceList' => $priceListId,
						'hidden' => $visibilityListItem->hidden,
						'hiddenInMenu' => $visibilityListItem->hiddenInMenu,
						'priority' => $visibilityListItem->priority,
						'unavailable' => $visibilityListItem->unavailable,
						'recommended' => $visibilityListItem->recommended,
					], [
						'price',
						'priceVat',
						'priceBefore',
						'priceVatBefore',
						'priceList',
						'hidden',
						'hiddenInMenu',
						'priority',
						'unavailable',
						'recommended',
					]);

					break 2;
				}
			}
		}
	}

	protected function waitForTransaction(\PDO $link): bool
	{
		$i = 0;

		while ($link->inTransaction()) {
			if ($i === 1) {
				return false;
			}

			\sleep(1);

			$i++;
		}

		return true;
	}
}
