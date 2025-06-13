<?php

namespace Eshop\Actions\Customer;

use Base\BaseAction;
use Eshop\DB\Customer;
use Eshop\DTO\CurrentContextCatalogPermissions;
use Eshop\Services\TemplateNamesService;

class GetCurrentContextCatalogPermissionByCustomer extends BaseAction
{
	public function __construct(private readonly TemplateNamesService $templateNamesService)
	{
	}

	public function execute(Customer $customer): CurrentContextCatalogPermissions
	{
		return $this->getLocalCachedOutput($customer->getPK(), function () use ($customer) {
			$prefilledCatalogPermission = $customer->getCatalogPermission();

			$catalogPermission = $prefilledCatalogPermission->catalogPermission ?? $customer->catalogPermissionSetting;
			$showPricesWithoutVat = false;
			$showPricesWithVat = false;
			$priorityPrice = null;

			if ($catalogPermission === 'price') {
				$showPricesWithoutVat = $prefilledCatalogPermission->showPricesWithoutVat ?? $customer->showPricesWithoutVat;
				$showPricesWithVat = $prefilledCatalogPermission->showPricesWithVat ?? $customer->showPricesWithVat;

				if ($showPricesWithoutVat && $showPricesWithVat) {
					$priorityPrice = $prefilledCatalogPermission->priorityPrice ?? $customer->priorityPrice;
				} else {
					if ($showPricesWithVat) {
						$priorityPrice = 'withVat';
					}

					if ($showPricesWithoutVat) {
						$priorityPrice = 'withoutVat';
					}
				}
			}

			return new CurrentContextCatalogPermissions(
				$customer,
				$customer->account,
				$catalogPermission,
				$prefilledCatalogPermission->buyAllowed ?? $customer->buyAllowed,
				$prefilledCatalogPermission->orderAllowed ?? $customer->orderAllowed,
				$prefilledCatalogPermission->viewAllOrders ?? $customer->viewAllOrders,
				$showPricesWithoutVat,
				$showPricesWithVat,
				$priorityPrice,
				$prefilledCatalogPermission->additionalEmailText ?? $customer->additionalEmailText,
				$prefilledCatalogPermission?->displayedTransactionEmailBlocks,
				$this->templateNamesService->getOrderEmailBlocks(),
			);
		});
	}
}
