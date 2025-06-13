<?php

declare(strict_types=1);

namespace Eshop\DTO;

use Eshop\DB\Customer;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use Security\DB\Account;

class CurrentContextCatalogPermissions
{
	public string $catalogPermission;

	public bool $buyAllowed;

	public bool $orderAllowed;

	public bool $viewAllOrders;

	public bool $showPricesWithoutVat;

	public bool $showPricesWithVat;

	public string|null $priorityPrice;

	public string $additionalEmailText;

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
		string|null $priorityPrice,
		string $additionalEmailText,
		private readonly string|null $displayedTransactionEmailBlocks,
		/** @var array<string> */
		private readonly array $emailBlocks,
	) {
		$this->catalogPermission = $catalogPermission;
		$this->buyAllowed = $buyAllowed;
		$this->orderAllowed = $orderAllowed;
		$this->viewAllOrders = $viewAllOrders;
		$this->showPricesWithoutVat = $showPricesWithoutVat;
		$this->showPricesWithVat = $showPricesWithVat;
		$this->priorityPrice = $priorityPrice;
		$this->additionalEmailText = $additionalEmailText;
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

	/**
	 * @return array<string, bool>
	 */
	public function getDisplayedTransactionEmailBlocks(): array
	{
		$displayedTransactionEmailBlocksDataDefault = $this->displayedTransactionEmailBlocks ?
			Strings::split($this->displayedTransactionEmailBlocks, '/;/', skipEmpty: true) :
			[];

		foreach ($displayedTransactionEmailBlocksDataDefault as $key => $value) {
			$exploded = \explode(':', $value);

			unset($displayedTransactionEmailBlocksDataDefault[$key]);

			if (\count($exploded) !== 2) {
				continue;
			}

			$displayedTransactionEmailBlocksDataDefault[$exploded[0]] = $exploded[1] === '1';
		}

		$customerDisplayBlocks = $this->getCustomer()->displayedTransactionEmailBlocks ? \explode(';', $this->getCustomer()->displayedTransactionEmailBlocks) : [];

		foreach ($this->emailBlocks as $emailBlock) {
			if (!isset($displayedTransactionEmailBlocksDataDefault[$emailBlock])) {
				$displayedTransactionEmailBlocksDataDefault[$emailBlock] = Arrays::contains($customerDisplayBlocks, $emailBlock);
			}
		}

		return $displayedTransactionEmailBlocksDataDefault;
	}
}
