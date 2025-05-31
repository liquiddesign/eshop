<?php

declare(strict_types=1);

namespace Eshop\DTO;

use Eshop\DB\Customer;
use Security\DB\Account;

class CurrentContextCatalogPermissions
{
	public string $catalogPermission;

	public bool $buyAllowed;

	public bool $orderAllowed;

	public bool $viewAllOrders;

	public bool $showPricesWithoutVat;

	public bool $showPricesWithVat;

	public string $priorityPrice;

	public string $additionalEmailText;

	/** @var array<string> */
	public array $displayedTransactionEmailBlocks;

	private Customer $customer;

	private ?Account $account;

	public function __construct(
		Customer $customer,
		?Account $account,
		string $catalogPermission,
		bool $buyAllowed,
		bool $orderAllowed,
		bool $viewAllOrders,
		bool $showPricesWithoutVat,
		bool $showPricesWithVat,
		string $priorityPrice,
		string $additionalEmailText,
		array $displayedTransactionEmailBlocks
	) {
		$this->catalogPermission = $catalogPermission;
		$this->buyAllowed = $buyAllowed;
		$this->orderAllowed = $orderAllowed;
		$this->viewAllOrders = $viewAllOrders;
		$this->showPricesWithoutVat = $showPricesWithoutVat;
		$this->showPricesWithVat = $showPricesWithVat;
		$this->priorityPrice = $priorityPrice;
		$this->additionalEmailText = $additionalEmailText;
		$this->displayedTransactionEmailBlocks = $displayedTransactionEmailBlocks;
		$this->customer = $customer;
		$this->account = $account;
	}

	public function getCustomer(): Customer
	{
		return $this->customer;
	}

	public function getAccount(): ?Account
	{
		return $this->account;
	}
}
