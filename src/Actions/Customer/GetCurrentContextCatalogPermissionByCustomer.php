<?php

namespace Eshop\Actions\Customer;

use Base\BaseAction;
use Eshop\DB\Customer;
use Eshop\DTO\CurrentContextCatalogPermissions;
use Eshop\Services\SettingsService;

class GetCurrentContextCatalogPermissionByCustomer extends BaseAction
{
	public function __construct(private readonly SettingsService $settingsService)
	{
	}

	public function execute(Customer $customer): CurrentContextCatalogPermissions
	{
		return $this->getLocalCachedOutput($customer->getPK(), function () use ($customer) {
			$prefilledCatalogPermission = $customer->getCatalogPermission();

			return new CurrentContextCatalogPermissions(
				$customer,
				$customer->account,
				$prefilledCatalogPermission->catalogPermission ?? $customer->catalogPermissionSetting,
				$prefilledCatalogPermission->buyAllowed ?? $customer->buyAllowed,
				$prefilledCatalogPermission->orderAllowed ?? $customer->orderAllowed,
				$prefilledCatalogPermission->viewAllOrders ?? $customer->viewAllOrders,
				$prefilledCatalogPermission->showPricesWithoutVat ?? $customer->showPricesWithoutVat,
				$prefilledCatalogPermission->showPricesWithVat ?? $customer->showPricesWithVat,
				$prefilledCatalogPermission->priorityPrice ?? $customer->priorityPrice,
				$prefilledCatalogPermission->additionalEmailText ?? $customer->additionalEmailText,
				$prefilledCatalogPermission?->displayedTransactionEmailBlocks,
				$this->settingsService->getOrderEmailBlocks(),
			);
		});
	}
}
