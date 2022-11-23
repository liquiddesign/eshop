<?php

namespace Eshop\Integration;

use Eshop\Admin\IntegrationPresenter;
use Soukicz\Zbozicz\CartItem;
use Soukicz\Zbozicz\Client;
use Soukicz\Zbozicz\Order;
use Web\DB\SettingRepository;

class Zbozi
{
	public ?string $apiKey = null;

	public ?string $storeId = null;

	public bool $sandbox;

	private SettingRepository $settingRepository;

	public function __construct(SettingRepository $settingRepository, bool $sandbox = false)
	{
		$this->settingRepository = $settingRepository;
		$this->sandbox = $sandbox;

		$this->init();
	}

	public function isInitialized(): bool
	{
		return $this->apiKey && $this->storeId;
	}

	public function sendOrder(\Eshop\DB\Order $order): void
	{
		if (!$this->isInitialized()) {
			throw new \Exception('Integration "Zbozi" is not initialized!');
		}

		if ($order->zboziConversionSent) {
			return;
		}

		$client = new Client($this->storeId, $this->apiKey, $this->sandbox);

		$zboziOrder = new Order($order->code);

		$purchase = $order->purchase;

		$zboziOrder
			->setEmail($purchase->email);

		if (($deliveryType = $purchase->deliveryType) && $deliveryType->externalIdZbozi) {
			$zboziOrder->setDeliveryType($deliveryType->externalIdZbozi);
		}

		$zboziOrder->setDeliveryPrice($order->getDeliveryPriceVatSum());

		foreach ($purchase->getItems() as $item) {
			$zboziOrder->addCartItem((new CartItem())
				->setId($item->getFullCode())
				->setName($item->productName)
				->setUnitPrice($item->priceVat)
				->setQuantity($item->amount));
		}

		if ($paymentType = $purchase->paymentType) {
			$zboziOrder->setPaymentType($paymentType->code);
		}

		$zboziOrder->setOtherCosts($order->getPaymentPriceVatSum() - $order->getDiscountPriceVat());

		try {
			$client->sendOrder($zboziOrder);

			$order->update(['zboziConversionSent' => true]);
		} catch (\Throwable $e) {
			throw new \Exception("Unable to send Zbozi order '$order->code' with error: {$e->getMessage()}");
		}
	}

	private function init(): void
	{
		$this->apiKey = $this->settingRepository->getValueByName(IntegrationPresenter::ZBOZI_API_KEY);
		$this->storeId = $this->settingRepository->getValueByName(IntegrationPresenter::ZBOZI_STORE_ID);
	}
}
