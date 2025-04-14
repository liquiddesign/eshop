<?php

declare(strict_types=1);

namespace Eshop\Actions\PackageItem;

use Base\BaseAction;
use Eshop\DB\PackageItem;

class CanTogglePackageItemDropShipping extends BaseAction
{
	public function __construct()
	{
		// Constructor logic can be added here if needed
	}

	/**
	 * @param \Eshop\DB\PackageItem $packageItem
	 * @throws \Nette\InvalidStateException
	 * @return array<string>
	 */
	public function execute(PackageItem $packageItem): array
	{
		unset($packageItem);

		return [];
	}
}
