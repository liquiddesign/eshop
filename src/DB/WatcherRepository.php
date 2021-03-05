<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\ICollection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Watcher>
 */
class WatcherRepository extends \StORM\Repository
{
	public function getWatchersByCustomer(Customer $customer): ICollection
	{
		return $this->many()
			->join(['products' => 'eshop_product'], 'this.fk_product=products.uuid')
			->where('this.fk_customer', $customer->getPK());
	}

	/**
	 * Return two arrays.
	 * First array (active): changed watchers in positive way, for example: watchedPrice=40, beforePrice=42, currentPrice=38
	 * Second array (nonActive): changed watchers in negative way, for example: watchedPrice=40, beforePrice=38, currentPrice=42
	 * Watchers without change will not be returned.
	 * @return array[] [Watcher[], Watcher[]]
	 */
	public function getChangedWatchers(): array
	{
		/** @var Watcher[] $watchers */
		$watchers = $this->many();

		/** @var ProductRepository $productRepo */
		$productRepo = $this->getConnection()->findRepository(Product::class);

		/** @var Watcher[] $activeWatchers */
		$activeWatchers = [];
		/** @var Watcher[] $nonActiveWatchers */
		$nonActiveWatchers = [];

		foreach ($watchers as $watcher) {
			/** @var Product $product */
			$product = $productRepo->getProducts($watcher->customer->pricelists->toArray(), $watcher->customer)->where('this.uuid', $watcher->product->getPK())->fetch();

			if (!$product) {
				continue;
			}

			if ($product->displayAmount && $product->displayAmount->amountFrom && $watcher->amountFrom) {
				if ($product->displayAmount->amountFrom >= $watcher->amountFrom && $watcher->amountFrom >= $watcher->beforeAmountFrom) {
					$activeWatchers[] = $watcher;

					if (!$watcher->keepAfterNotify) {
						$watcher->delete();
						continue;
					}

					$watcher->update([
						'beforeAmountFrom' => $product->displayAmount->amountFrom
					]);
				}

				if ($product->displayAmount->amountFrom < $watcher->amountFrom && $product->displayAmount->amountFrom < $watcher->beforeAmountFrom) {
					$nonActiveWatchers[] = $watcher;
					$watcher->update([
						'beforeAmountFrom' => $product->displayAmount->amountFrom
					]);
				}
			}

			if ($watcher->priceFrom) {
				if ($watcher->priceFrom <= $watcher->beforePriceFrom && $product->price <= $watcher->priceFrom) {
					$activeWatchers[] = $watcher;

					if (!$watcher->keepAfterNotify) {
						$watcher->delete();
						continue;
					}

					$watcher->update([
						'beforePriceFrom' => (float)$product->price
					]);
				}

				if ($product->price > $watcher->beforePriceFrom && $product->price > $watcher->priceFrom) {
					$nonActiveWatchers[] = $watcher;
					$watcher->update([
						'beforePriceFrom' => (float)$product->price
					]);
				}
			}
		}

		return [
			'active' => $activeWatchers,
			'nonActive' => $nonActiveWatchers
		];
	}

}
