<?php

namespace Eshop\Services\BalikobotApi\Actions;

use Base\BaseAction;
use Base\DB\Shop;
use Base\ShopsConfig;
use Eshop\Admin\IntegrationPresenter;
use Eshop\Services\BalikobotApi\DeliveryProviderInterface;
use Eshop\Services\BalikobotApi\DeliveryProviders;
use Eshop\Services\BalikobotApi\Providers\GLSApiService;
use Eshop\Services\BalikobotApi\Providers\PPLApiService;
use Tracy\Debugger;
use Web\DB\SettingRepository;

class ResolveProviderForShop extends BaseAction
{
	public function __construct(
		private readonly ShopsConfig $shopsConfig,
		private readonly SettingRepository $settingRepository,
		private readonly GLSApiService $GLSApi,
		private readonly PPLApiService $PPLApi,
	) {
	}

	public function execute(Shop|null $shop = null): DeliveryProviderInterface
	{
		$shop ??= $this->shopsConfig->getSelectedShop();

		$balikobotProviderCode = $this->settingRepository->getValueByName(IntegrationPresenter::BALIKOBOT_PROVIDER_ID, shops: $shop ? [$shop] : null);

		if ($balikobotProviderCode === null) {
			Debugger::log('Balikobot API: Not found setting for provider. Defaulting to GLS.');

			return $this->GLSApi;
		}

		return match ($balikobotProviderCode) {
			DeliveryProviders::GLS->value => $this->GLSApi,
			DeliveryProviders::PPL->value => $this->PPLApi,
			default => null
		};
	}
}
