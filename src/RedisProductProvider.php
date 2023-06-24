<?php

namespace Eshop;

use Eshop\DB\Category;
use Eshop\DB\CategoryRepository;
use Eshop\DB\Price;
use Eshop\DB\Pricelist;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProductRepository;
use Nette\DI\Container;
use StORM\Connection;
use Tracy\Debugger;

readonly class RedisProductProvider
{
	public function __construct(
		protected ProductRepository $productRepository,
		protected CategoryRepository $categoryRepository,
		protected PriceRepository $priceRepository,
		protected PricelistRepository $pricelistRepository,
		protected Container $container,
		protected Connection $connection,
	) {
	}

	/**
	 * @param array<string, array<string, string>> $orderExpressions
	 */
	public function warmUpRedisCache(array $orderExpressions = []): void
	{
		/** @var \Predis\Client|null $redis */
		$redis = $this->container->getService('redis.connection.default.client');

		if (!$redis) {
			return;
		}

		$redis->set('cacheReady', 0);

		$allPriceLists = $this->pricelistRepository->many()->toArray();
		$allPrices = $this->priceRepository->many()->toArray();

		$allCategories = [];

		$this->connection->getLink()->exec('SET SESSION group_concat_max_len=4294967295');

		foreach ($orderExpressions as $orderExpressionName => $orderExpression) {
			foreach (['ASC', 'DESC'] as $direction) {
				$productsCollection = $this->productRepository->many()
					->join(['price' => 'eshop_price'], 'this.uuid = price.fk_product')
					->join(['eshop_product_nxn_eshop_category'], 'this.uuid = eshop_product_nxn_eshop_category.fk_product')
					->select([
						'prices' => 'GROUP_CONCAT(DISTINCT price.uuid)',
						'categoriesPKs' => 'GROUP_CONCAT(DISTINCT eshop_product_nxn_eshop_category.fk_category)',
					])
					->setGroupBy(['this.uuid'])
					->setOrderBy(\str_replace(':direction', $direction, $orderExpression));

				$products = [];

				while ($product = $productsCollection->fetch()) {
					if (!$product->getValue('prices')) {
						continue;
					}

					$products[$product->getPK()] = [
						'uuid' => $product->getPK(),
						'name' => $product->name,
					];

					if ($categories = $product->getValue('categoriesPKs')) {
						$categories = \explode(',', $categories);

						foreach ($categories as $category) {
							$categoryCategories = $allCategories[$category] ?? null;

							if ($categoryCategories === null) {
								$categoryCategories = $allCategories[$category] = \array_merge($this->getChildrenOfCategory($category), [$category]);
							}

							$products[$product->getPK()]['categories'] = \array_unique(\array_merge($products[$product->getPK()]['categories'] ?? [], $categoryCategories));
						}
					}


					$prices = \explode(',', $product->getValue('prices'));
					$productPrices = [];

					foreach ($prices as $price) {
						$price = $allPrices[$price];
						$productPrices[] = $price->toArray();
					}

					$products[$product->getPK()]['prices'] = $productPrices;
				}

				$productsCollection->__destruct();

				$redis->jsonset("products_$orderExpressionName$direction", '.', \json_encode($products));
			}
		}

		$redis->set('cacheReady', 1);
	}

	/**
	 * @param array<mixed> $filters
	 * @param string|null $orderExpressionName
	 * @param string|null $orderDirection
	 * @return array<\Eshop\DB\Product>|false
	 */
	public function getProductsFromRedis(array $filters, string|null $orderExpressionName = null, string|null $orderDirection = null): array|false
	{
		/** @var \Predis\Client|null $redis */
		$redis = $this->container->getService('redis.connection.default.client');

		if (!$redis) {
			return false;
		}

		$cacheReady = $redis->get('cacheReady');

		if ($cacheReady !== '1') {
			return false;
		}

		if ($orderExpressionName && !$orderDirection) {
			$orderDirection = 'ASC';
		}

		$index = "products_$orderExpressionName$orderDirection";

		$exists = $redis->exists($index);

		if (!$exists) {
			return false;
		}

		\bdump(Debugger::timer());
		$products = \json_decode($redis->jsonget($index), true);
		\bdump(Debugger::timer());
		die();

		\bdump(\count($products));

		return $products;
	}

	protected function getChildrenOfCategory(string $categoryPK): array
	{
		$category = $this->categoryRepository->one($categoryPK, true);

		return $this->categoryRepository->many()
			->where('this.path LIKE :s', ['s' => "$category->path%"])
			->toArrayOf('uuid', toArrayValues: true,);
	}
}
