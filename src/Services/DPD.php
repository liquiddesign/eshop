<?php

declare(strict_types=1);

namespace Eshop\Services;

use Eshop\DB\Order;
use Eshop\Providers\Helpers;

class DPD
{
	private string $url;

	private string $login;

	private string $password;

	public function __construct(string $url, string $login, string $password)
	{
		$this->url = $url;
		$this->login = $login;
		$this->password = $password;
	}

	public function createShipment(Order $order): void
	{
		$client = Helpers::createSoapClient($this->url, $this->login, $this->password);

		unset($order);
		unset($client);
	}

	public function getLabel(Order $order): void
	{
		$client = Helpers::createSoapClient($this->url, $this->login, $this->password);

		unset($order);
		unset($client);
	}
}
