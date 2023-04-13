<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Tax>
 */
class TaxRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		return $collection->orderBy(["name$suffix"]);
	}

	/**
	 * @param \Eshop\DB\Product $product
	 * @param \Eshop\DB\Currency $currency
	 * @return array<\Eshop\DB\Tax>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getTaxesForProduct(Product $product, Currency $currency): array
	{
		/** @var \Eshop\DB\ProductRepository $productRepository */
		$productRepository = $this->getConnection()->findRepository(Product::class);

		if (!$product = $productRepository->get($product)) {
			return [];
		}

		return $this->many()
			->join(['nxn' => 'eshop_product_nxn_eshop_tax'], 'this.uuid = nxn.fk_tax')
			->where('nxn.fk_product', $product->getPK())
			->where('this.fk_currency', $currency)
			->toArray();
	}
}
