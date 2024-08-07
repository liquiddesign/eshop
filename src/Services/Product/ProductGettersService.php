<?php

namespace Eshop\Services\Product;

use Base\Bridges\AutoWireService;
use Base\ShopsConfig;
use Eshop\Admin\SettingsPresenter;
use Eshop\DB\Category;
use Eshop\DB\CategoryType;
use Eshop\DB\DisplayAmount;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\ShopperUser;
use Nette\Application\ApplicationException;
use Nette\Utils\Arrays;
use Web\DB\SettingRepository;

class ProductGettersService implements AutoWireService
{
	protected string|null|false $defaultDisplayAmount = false;

	protected string|null|false $defaultUnavailableDisplayAmount = false;

	/**
	 * @var array<string, \Eshop\DB\DisplayAmount>|false
	 */
	protected array|false $allDisplayAmounts = false;

	public function __construct(
		protected readonly ProductRepository $productRepository,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly ShopperUser $shopperUser,
		protected readonly SettingRepository $settingRepository,
		protected readonly DisplayAmountRepository $displayAmountRepository,
	) {
	}

	/**
	 * @return array<\Eshop\DB\DisplayAmount>
	 */
	public function getAllDisplayAmounts(): array
	{
		return $this->allDisplayAmounts !== false ? $this->allDisplayAmounts : $this->allDisplayAmounts = $this->displayAmountRepository->many()->toArray();
	}

	public function getDefaultDisplayAmount(): string|null
	{
		if ($this->defaultDisplayAmount !== false) {
			return $this->defaultDisplayAmount;
		}

		return $this->defaultDisplayAmount = $this->settingRepository->getValueByName(SettingsPresenter::DEFAULT_DISPLAY_AMOUNT);
	}

	public function getDefaultUnavailableDisplayAmount(): string|null
	{
		if ($this->defaultUnavailableDisplayAmount !== false) {
			return $this->defaultUnavailableDisplayAmount;
		}

		return $this->defaultUnavailableDisplayAmount = $this->settingRepository->getValueByName(SettingsPresenter::DEFAULT_UNAVAILABLE_DISPLAY_AMOUNT);
	}

	/**
	 * Get product primary category based on category tree. If no tree supplied, use main category tree based on current shop.
	 * If no shops available, act like basic primary category getter.
	 */
	public function getPrimaryCategory(Product $product, CategoryType|null $categoryType = null): Category|null
	{
		$categoryType ??= $this->shopperUser->getMainCategoryType();

		return $product->getPrimaryCategory($categoryType);
	}

	/**
	 * @param string $basePath
	 * @param string $size
	 * @param bool $fallbackImageSupplied If true, it is expected that property fallbackImage is set on object, otherwise it is selected manually
	 * @throws \Nette\Application\ApplicationException
	 */
	public function getPreviewImage(Product $product, string $basePath, string $size = 'detail', bool $fallbackImageSupplied = true, ?CategoryType $categoryType = null): string
	{
		if (!Arrays::contains(['origin', 'detail', 'thumb'], $size)) {
			throw new ApplicationException('Invalid product image size: ' . $size);
		}

		$fallbackImage = $fallbackImageSupplied ?
			($product->__isset('fallbackImage') ? $product->getValue('fallbackImage') : null) :
			(($primaryCategory = $this->getPrimaryCategory($product, $categoryType)) ? $primaryCategory->productFallbackImageFileName : null);

		$image = $product->imageFileName ?: $fallbackImage;
		$dir = $product->imageFileName ? Product::GALLERY_DIR : Category::IMAGE_DIR;

		return $image ? "$basePath/userfiles/$dir/$size/$image" : "$basePath/public/img/no-image.png";
	}

	public function inStock(Product $product): bool
	{
		$displayAmount = $this->getDisplayAmount($product);

		return $displayAmount === null || !$displayAmount->isSold;
	}

	public function getDisplayAmount(Product $product): ?DisplayAmount
	{
		$defaultDisplayAmount = $this->getDefaultDisplayAmount();
		$defaultUnavailableDisplayAmount = $this->getDefaultUnavailableDisplayAmount();
		$productDisplayAmount = $product->getValue('displayAmount') ? $this->getAllDisplayAmounts()[$product->getValue('displayAmount')] : null;

		if ($defaultDisplayAmount && $defaultUnavailableDisplayAmount) {
			$defaultDisplayAmount = $this->getAllDisplayAmounts()[$defaultDisplayAmount];
			$defaultUnavailableDisplayAmount = $this->getAllDisplayAmounts()[$defaultUnavailableDisplayAmount];

			return $product->isUnavailable() === false ? $defaultDisplayAmount : $defaultUnavailableDisplayAmount;
		}

		if ($defaultDisplayAmount) {
			$defaultDisplayAmount = $this->getAllDisplayAmounts()[$defaultDisplayAmount];

			return $product->isUnavailable() === false ? $defaultDisplayAmount : $productDisplayAmount;
		}

		if ($defaultUnavailableDisplayAmount) {
			$defaultUnavailableDisplayAmount = $this->getAllDisplayAmounts()[$defaultUnavailableDisplayAmount];

			return $product->isUnavailable() === false ? $productDisplayAmount : $defaultUnavailableDisplayAmount;
		}

		return $productDisplayAmount;
	}

	/**
	 * @return array<string, array<int, array<string, string>>>
	 */
	public function getPreviewAttributes(Product $product): array
	{
		if (!$product->getValue('parameters')) {
			return [];
		}

		$attributePriorities = [];
		$parameters = [];

		foreach (\explode(';', $product->getValue('parameters')) as $parameterSerialized) {
			$parameter = \explode('|', $parameterSerialized);

			if (!isset($parameter[3])) {
				continue;
			}

			if (!isset($parameters[$parameter[1]])) {
				$parameters[$parameter[1]] = [];
			}

			$parameters[$parameter[1]][] = [
				'uuid' => $parameter[0],
				'fk_parameter' => $parameter[1],
				'label' => $parameter[2],
				'metaValue' => $parameter[3],
				'attributeName' => $parameter[4] ?? null,
				'imageFileName' => $parameter[5] ?? null,
				'number' => $parameter[6] ?? null,
				'note' => $parameter[7] ?? null,
				'attributeNote' => $parameter[8] ?? null,
				'attributePriority' => $parameter[9] ?? null,
				'valuePriority' => $parameter[10] ?? null,
			];

			\usort($parameters[$parameter[1]], function ($a, $b) {
				if ($a['valuePriority'] === $b['valuePriority']) {
					return 0;
				}

				return $a['valuePriority'] < $b['valuePriority'] ? -1 : 1;
			});

			if (!isset($parameter[9])) {
				continue;
			}

			$attributePriorities[$parameter[1]] = $parameter[9];
		}

		\uksort($parameters, function ($a, $b) use ($attributePriorities) {
			if (!isset($attributePriorities[$a]) || !isset($attributePriorities[$b])) {
				return 0;
			}

			$a = (int) $attributePriorities[$a];
			$b = (int) $attributePriorities[$b];

			if ($a === $b) {
				return 0;
			}

			return $a < $b ? -1 : 1;
		});

		return $parameters;
	}
}
