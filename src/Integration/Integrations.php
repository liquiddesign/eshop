<?php

namespace Eshop\Integration;

use Eshop\Admin\SettingsPresenter;
use Nette\DI\Container;
use Nette\DI\MissingServiceException;
use Web\DB\SettingRepository;

final class Integrations
{
	public const DPD = 'dpd';
	public const PPL = 'ppl';
	public const GO_PAY = 'goPay';
	public const ZBOZI = 'zbozi';

	public const SERVICES = [
		self::DPD => 'integrations.dpd',
		self::PPL => 'integrations.ppl',
		self::GO_PAY => 'integrations.goPay',
		self::ZBOZI => 'integrations.zbozi',
	];

	public const SERVICES_SETTINGS = [
		SettingsPresenter::DPD_DELIVERY_TYPE => self::DPD,
		SettingsPresenter::PPL_DELIVERY_TYPE => self::PPL,
		SettingsPresenter::GO_PAY_PAYMENT_TYPE => self::GO_PAY,
	];

	protected Container $container;

	protected SettingRepository $settingRepository;

	public function __construct(Container $container, SettingRepository $settingRepository)
	{
		$this->container = $container;
		$this->settingRepository = $settingRepository;
	}

	public function getService(string $name): ?object
	{
		if (!isset($this::SERVICES[$name])) {
			return null;
		}

		try {
			return $this->container->getByName($this::SERVICES[$name]);
		} catch (MissingServiceException $e) {
			return null;
		}
	}

	public function getServiceBySetting(string $settingName): ?object
	{
		$setting = $this->settingRepository->getValueByName($settingName);

		if (!$setting || !isset(self::SERVICES_SETTINGS[$settingName])) {
			return null;
		}

		return $this->getService(self::SERVICES_SETTINGS[$settingName]);
	}
}
