<?php

declare(strict_types=1);

namespace Eshop\Actions\PackageItem;

use Base\BaseAction;
use Eshop\DB\PackageItem;
use Eshop\DB\PackageItemRepository;
use Nette\Utils\Arrays;

class TogglePackageItemDropShipping extends BaseAction
{
	/** @var array<callable(\Eshop\DB\PackageItem): void> */
	public array $onDropShippingChangeToTrue = [];

	/** @var array<callable(\Eshop\DB\PackageItem): void> */
	public array $onDropShippingChangeToFalse = [];

	public function __construct(private readonly PackageItemRepository $packageItemRepository)
	{
	}

	public function execute(PackageItem|string $packageItem): void
	{
		$packageItem = $packageItem instanceof PackageItem ? $packageItem : $this->packageItemRepository->one($packageItem, true);

		$dropShippingToTrue = $packageItem->dropShipping === false;
		$dropShippingToFalse = $packageItem->dropShipping === true;

		$packageItem->update(['dropShipping' => !$packageItem->dropShipping]);

		if ($dropShippingToTrue) {
			Arrays::invoke($this->onDropShippingChangeToTrue, $packageItem);
		}

		if (!$dropShippingToFalse) {
			return;
		}

		Arrays::invoke($this->onDropShippingChangeToFalse, $packageItem);
	}
}
