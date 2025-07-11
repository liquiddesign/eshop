<?php

declare(strict_types=1);

namespace Eshop\DTO;

use Nette\Utils\Strings;

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

	public function __construct(
		string $catalogPermission,
		bool $buyAllowed,
		bool $orderAllowed,
		bool $viewAllOrders,
		bool $showPricesWithoutVat,
		bool $showPricesWithVat,
		string|null $priorityPrice,
		string $additionalEmailText,
		/** @var array{0: string|null, 1: string|null, 2: string|null} */
		private readonly array $displayedTransactionEmailBlocks,
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
	}

	/**
	 * @return array<string, bool>
	 */
	public function getDisplayedTransactionEmailBlocks(): array
	{
		$allEmailBlocks = [];

		foreach ($this->displayedTransactionEmailBlocks as $displayedTransactionEmailBlock) {
			if (!$displayedTransactionEmailBlock) {
				continue;
			}

			$currentEmailBlocksSetting = Strings::split($displayedTransactionEmailBlock, '/;/', skipEmpty: true);

			foreach ($currentEmailBlocksSetting as $key => $value) {
				$exploded = \explode(':', $value);

				unset($currentEmailBlocksSetting[$key]);

				if (\count($exploded) !== 2) {
					continue;
				}

				$currentEmailBlocksSetting[$exploded[0]] = $exploded[1] === '1';
			}

			foreach (\array_keys($this->emailBlocks) as $emailBlockKey) {
				if (!isset($allEmailBlocks[$emailBlockKey]) && isset($currentEmailBlocksSetting[$emailBlockKey])) {
					$allEmailBlocks[$emailBlockKey] = $currentEmailBlocksSetting[$emailBlockKey];
				}
			}
		}

		return $allEmailBlocks;
	}
}
