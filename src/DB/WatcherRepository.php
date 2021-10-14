<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\DIConnection;
use StORM\ICollection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Watcher>
 */
class WatcherRepository extends \StORM\Repository
{
	private ProductRepository $productRepository;

	private PricelistRepository $pricelistRepository;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, ProductRepository $productRepository, PricelistRepository $pricelistRepository)
	{
		parent::__construct($connection, $schemaManager);

		$this->productRepository = $productRepository;
		$this->pricelistRepository = $pricelistRepository;
	}

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
	 * @return \Eshop\DB\Watcher[][]
	 */
	public function getChangedAmountWatchers(): array
	{
		/** @var \Eshop\DB\Watcher[] $activeWatchers */
		$activeWatchers = [];
		/** @var \Eshop\DB\Watcher[] $nonActiveWatchers */
		$nonActiveWatchers = [];

		$watchers = $this->many()
			->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid')
			->join(['displayAmount' => 'eshop_displayamount'], 'product.fk_displayAmount = displayAmount.uuid')
			->where('product.fk_displayAmount IS NOT NULL AND displayAmount.amountFrom IS NOT NULL AND this.amountFrom IS NOT NULL');

		while ($watcher = $watchers->fetch()) {
			/** @var \Eshop\DB\Watcher $watcher */
			if ($watcher->product->displayAmount->amountFrom >= $watcher->amountFrom && $watcher->amountFrom > $watcher->beforeAmountFrom) {
				$activeWatchers[] = $watcher;

				if (!$watcher->keepAfterNotify) {
					$watcher->delete();
					continue;
				}
			}

			if ($watcher->product->displayAmount->amountFrom < $watcher->amountFrom && $watcher->product->displayAmount->amountFrom < $watcher->beforeAmountFrom) {
				$nonActiveWatchers[] = $watcher;
			}

			$watcher->update([
				'beforeAmountFrom' => $watcher->product->displayAmount->amountFrom
			]);
		}

		return [
			'active' => $activeWatchers,
			'nonActive' => $nonActiveWatchers
		];
	}

	/**
	 * Return two arrays.
	 * First array (active): changed watchers in positive way, for example: watchedPrice=40, beforePrice=42, currentPrice=38
	 * Second array (nonActive): changed watchers in negative way, for example: watchedPrice=40, beforePrice=38, currentPrice=42
	 * Watchers without change will not be returned.
	 * @return \Eshop\DB\Watcher[][]
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getChangedPriceWatchers(): array
	{
		/** @var \Eshop\DB\Watcher[] $activeWatchers */
		$activeWatchers = [];
		/** @var \Eshop\DB\Watcher[] $nonActiveWatchers */
		$nonActiveWatchers = [];
		/** @var \Eshop\DB\Pricelist[][] $pricelistsByCustomers */
		$pricelistsByCustomers = [];

		$watchers = $this->many()->where('priceFrom IS NOT NULL AND beforePriceFrom IS NOT NULL');

		while ($watcher = $watchers->fetch()) {
			/** @var \Eshop\DB\Watcher $watcher */

			if (!isset($pricelistsByCustomers[$watcher->getValue('customer')])) {
				$pricelistsByCustomers[$watcher->getValue('customer')] = $this->pricelistRepository->getCollection()
					->join(['nxnCustomer' => 'eshop_customer_nxn_eshop_pricelist'], 'this.uuid = nxnCustomer.fk_pricelist')
					->where('nxnCustomer.fk_customer', $watcher->getValue('customer'))
					->toArray();
			}

			/** @var \Eshop\DB\Product $product */
			if (!$product = $this->productRepository->getProducts($pricelistsByCustomers[$watcher->getValue('customer')])->where('this.uuid', $watcher->getValue('product'))->first()) {
				continue;
			}

			if ($watcher->priceFrom < $watcher->beforePriceFrom && $product->getPrice() <= $watcher->priceFrom) {
				$activeWatchers[] = $watcher;

				if (!$watcher->keepAfterNotify) {
					$watcher->delete();
					continue;
				}
			}

			if ($product->getPrice() > $watcher->beforePriceFrom && $product->getPrice() > $watcher->priceFrom) {
				$nonActiveWatchers[] = $watcher;
			}

			$watcher->update([
				'beforePriceFrom' => $product->getPrice()
			]);
		}

		return [
			'active' => $activeWatchers,
			'nonActive' => $nonActiveWatchers
		];
	}

}
