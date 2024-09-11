<?php

namespace Eshop\Actions\Product\Image;

use Base\Bridges\AutoWireAction;
use Eshop\DB\Category;
use Eshop\DB\CategoryType;
use Eshop\DB\Product;
use Eshop\ShopperUser;
use Nette\Application\ApplicationException;
use Nette\Utils\Arrays;

readonly class GetPrimaryFileName implements AutoWireAction
{
	public function __construct(protected ShopperUser $shopperUser)
	{
	}

	public function handle(Product $product, string $size = 'detail', ?CategoryType $categoryType = null): string|null
	{
		if (!Arrays::contains(['origin', 'detail', 'thumb'], $size)) {
			throw new ApplicationException('Invalid product image size: ' . $size);
		}

		try {
			$fallbackImage = $product->getValue('fallbackImage');
		} catch (\Exception) {
			if (!$categoryType) {
				$categoryType = $this->shopperUser->getMainCategoryType();
			}

			$primaryCategory = $product->getPrimaryCategory($categoryType);
			$fallbackImage = $primaryCategory?->productFallbackImageFileName;
		}

		$image = $product->imageFileName ?: $fallbackImage;
		$dir = $product->imageFileName ? Product::GALLERY_DIR : Category::IMAGE_DIR;

		return $image ? "userfiles/$dir/$size/$image" : null;
	}
}
