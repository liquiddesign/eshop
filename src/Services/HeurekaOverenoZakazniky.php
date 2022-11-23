<?php

namespace Eshop\Services;

use Eshop\DB\Order;
use Heureka\ShopCertification;
use Tracy\Debugger;
use Tracy\ILogger;

class HeurekaOverenoZakazniky
{
	public string $apiKey;

	public ShopCertification $shopCertification;

	public function __construct(string $apiKey)
	{
		$this->apiKey = $apiKey;
	}

	public function getShopCertification(): ShopCertification
	{
		return $this->shopCertification ??= new \Heureka\ShopCertification($this->apiKey);
	}

	public function sendOrder(Order $order): void
	{
		$shopCertification = $this->getShopCertification();
		$shopCertification->setEmail($order->purchase->email);
		$shopCertification->setOrderId((int) $order->code);

		foreach ($order->purchase->getItems() as $item) {
			$shopCertification->addProductItemId($item->getFullCode());
		}

		try {
			$shopCertification->logOrder();
		} catch (\Throwable $e) {
			Debugger::log($e->getMessage(), ILogger::ERROR);
		}
	}
}
