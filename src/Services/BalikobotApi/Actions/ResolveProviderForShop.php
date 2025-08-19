<?php

namespace Eshop\Services\BalikobotApi\Actions;

use Base\BaseAction;
use Base\DB\Shop;
use Base\ShopsConfig;
use Eshop\Admin\IntegrationPresenter;
use Eshop\Services\BalikobotApi\DeliveryProviderInterface;
use Eshop\Services\BalikobotApi\DeliveryProviders;
use Eshop\Services\BalikobotApi\Providers\GLSApi;
use Eshop\Services\BalikobotApi\Providers\PPLApi;
use StORM\Exception\NotFoundException;
use Web\DB\SettingRepository;

class ResolveProviderForShop extends BaseAction
{
	public function __construct(
		private readonly ShopsConfig $shopsConfig,
		private readonly SettingRepository $settingRepository,
		private readonly GLSApi $GLSApi,
		private readonly PPLApi $PPLApi,
	) {
	}

	public function execute(?Shop $shop = null): ?DeliveryProviderInterface
	{
		$shop ??= $this->shopsConfig->getSelectedShop();

		if ($shop === null) {
			return null;
		}

		try {
			$balikobotProviderCode = $this->settingRepository->getValueByName(IntegrationPresenter::BALIKOBOT_PROVIDER_ID, shops: [$shop]);
		} catch (NotFoundException) {
			return null;
		}

		return match ($balikobotProviderCode) {
			DeliveryProviders::GLS->value => $this->GLSApi,
			DeliveryProviders::PPL->value => $this->PPLApi,
			default => null
		};
	}
}
