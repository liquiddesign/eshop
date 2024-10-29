<?php

namespace Eshop\Actions\Product\Ribbon;

use Base\BaseAction;
use Eshop\DB\Product;
use Eshop\DB\RibbonRepository;

class GetProductRibbons extends BaseAction
{
	public function __construct(private readonly RibbonRepository $ribbonRepository)
	{
	}

	/**
	 * @param \Eshop\DB\Product $product
	 * @param \Eshop\Actions\Product\Ribbon\RibbonType $type
	 * @return array<\Eshop\DB\Ribbon>
	 */
	public function execute(Product $product, RibbonType $type = RibbonType::TEXT): array
	{
		return $this->getLocalCachedOutput($product->getPK() . '-' . $type->value, function () use ($product, $type): array {
			$ribbons = $type === RibbonType::IMAGE ? $this->ribbonRepository->getImageRibbons() : $this->ribbonRepository->getTextRibbons();

			return $ribbons->where('this.uuid', $product->ribbons->toArrayOf('uuid', toArrayValues: true))->toArray();
		});
	}
}
