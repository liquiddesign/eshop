<?php

declare(strict_types=1);

namespace Eshop\DB;

use Security\DB\Account;
use StORM\ICollection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Watcher>
 */
class WatcherRepository extends \StORM\Repository
{
	public function getWatchersByAccount(Account $account): ICollection
	{
		return $this->many()
			->join(['products' => 'eshop_product'], 'this.fk_product=products.uuid')
			->where('this.fk_account', $account->getPK());
	}

	/**
	 * Return two arrays.
	 * First array (active): changed watchers in positive way, for example: watchedPrice=40, beforePrice=42, currentPrice=38
	 * Second array (nonActive): changed watchers in negative way, for example: watchedPrice=40, beforePrice=38, currentPrice=42
	 * Watchers without change will not be returned.
	 * @return array[] [Watcher[], Watcher[]]
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getChangedWatchers(): array
	{
		/** @var ProductRepository $productRepo */
		$productRepo = $this->getConnection()->findRepository(Product::class);

		/** @var PricelistRepository $pricelistRepo */
		$pricelistRepo = $this->getConnection()->findRepository(Pricelist::class);

		/** @var Watcher[] $activeWatchers */
		$activeWatchers = [];
		/** @var Watcher[] $nonActiveWatchers */
		$nonActiveWatchers = [];

		$pricelistsByAccounts = [];

		$watchers = $this->many();

		/** @var Watcher $watcher */
		while ($watcher = $watchers->fetch()) {
			if (!isset($pricelistsByAccounts[$watcher->getValue('account')])) {
				$pricelistsByAccounts[$watcher->getValue('account')] = $pricelistRepo->getCollection()
					->join(['nxnCustomer' => 'eshop_customer_nxn_eshop_pricelist'], 'this.uuid = nxnCustomer.fk_pricelist')
					->join(['nxnCatalog' => 'eshop_catalogpermission'], 'nxnCustomer.fk_customer = nxnCatalog.fk_customer')
					->where('nxnCatalog.fk_account', $watcher->getValue('account'))
					->toArray();
			}

			/** @var Product $product */
			$product = $productRepo->getProducts($pricelistsByAccounts[$watcher->getValue('account')])
				->where('this.uuid', $watcher->getValue('product'))
				->first();

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
