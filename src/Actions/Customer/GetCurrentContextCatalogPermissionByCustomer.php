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

			return new CurrentContextCatalogPermissions(
				$customer,
				$customer->account,
				$catalogPermission,
				$prefilledCatalogPermission->buyAllowed ?? $customer->buyAllowed,
				$prefilledCatalogPermission->orderAllowed ?? $customer->orderAllowed,
				$prefilledCatalogPermission->viewAllOrders ?? $customer->viewAllOrders,
				$catalogPermission === 'price' && (($prefilledCatalogPermission->showPricesWithoutVat ?? $customer->showPricesWithoutVat)),
				$catalogPermission === 'price' && (($prefilledCatalogPermission->showPricesWithVat ?? $customer->showPricesWithVat)),
				$prefilledCatalogPermission->priorityPrice ?? $customer->priorityPrice,
				$prefilledCatalogPermission->additionalEmailText ?? $customer->additionalEmailText,
				$prefilledCatalogPermission?->displayedTransactionEmailBlocks,
				$this->templateNamesService->getOrderEmailBlocks(),
			);
		});
	}
}
