<?php

namespace Eshop\Services\BalikobotApi\Actions;

use Base\BaseAction;
use Base\DB\Shop;
use Base\ShopsConfig;
use Eshop\Admin\IntegrationPresenter;
use StORM\Exception\NotFoundException;
use Web\DB\SettingRepository;

class ResolveCcEmailForShop extends BaseAction
{
	public function __construct(
		private readonly ShopsConfig $shopsConfig,
		private readonly SettingRepository $settingRepository,
	) {
	}

	public function execute(?Shop $shop = null): ?string
	{
		$shop ??= $this->shopsConfig->getSelectedShop();

		if ($shop === null) {
			return null;
		}

		try {
			return $this->settingRepository->getValueByName(IntegrationPresenter::BALIKOBOT_CC_ADDRESS, shops: [$shop]);
		} catch (NotFoundException) {
			return null;
		}
	}
}
