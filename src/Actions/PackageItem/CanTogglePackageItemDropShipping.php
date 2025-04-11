<?php

declare(strict_types=1);

namespace Eshop\Actions\PackageItem;

use Base\BaseAction;
use Eshop\DB\PackageItem;

class CanTogglePackageItemDropShipping extends BaseAction
{
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
