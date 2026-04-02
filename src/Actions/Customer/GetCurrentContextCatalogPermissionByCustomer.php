<?php

namespace Eshop\Actions\Customer;

use Base\BaseAction;
use Base\DB\Shop;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DTO\CurrentContextCatalogPermissions;
use Eshop\Services\TemplateNamesService;

class GetCurrentContextCatalogPermissionByCustomer extends BaseAction
{
	public function __construct(private readonly TemplateNamesService $templateNamesService, private readonly CustomerGroupRepository $customerGroupRepository)
	{
	}

	public function execute(Customer|null $customer, Shop|null $shop = null): CurrentContextCatalogPermissions
	{
		return $this->getLocalCachedOutput($customer?->getPK() . '_' . $shop?->getPK(), function () use ($customer, $shop) {
			$defaultGroup = $this->customerGroupRepository->getUnregisteredGroup($shop);
			$prefilledCatalogPermission = $customer?->getCatalogPermission();

			$catalogPermission = $prefilledCatalogPermission->catalogPermission ?? $customer->catalogPermissionSetting ?? $defaultGroup->defaultCatalogPermission;
			$showPricesWithoutVat = false;
			$showPricesWithVat = false;
			$priorityPrice = null;

			if ($catalogPermission === 'price') {
				$showPricesWithoutVat = $prefilledCatalogPermission->showPricesWithoutVat ?? $customer->showPricesWithoutVat ?? $defaultGroup->defaultPricesWithoutVat;
				$showPricesWithVat = $prefilledCatalogPermission->showPricesWithVat ?? $customer->showPricesWithVat ?? $defaultGroup->defaultPricesWithVat;

				if ($showPricesWithoutVat && $showPricesWithVat) {
					$priorityPrice = $prefilledCatalogPermission->priorityPrice ?? $customer->priorityPrice ?? $defaultGroup->defaultPriorityPrice;
				} else {
					if ($showPricesWithVat) {
						$priorityPrice = 'withVat';
					}

					if ($showPricesWithoutVat) {
						$priorityPrice = 'withoutVat';
					}
				}
			}

			$additionalEmailText = null;

			if ($prefilledCatalogPermission?->additionalEmailText) {
				$additionalEmailText = $prefilledCatalogPermission->additionalEmailText;
			} elseif ($customer?->additionalEmailText) {
				$additionalEmailText = $customer->additionalEmailText;
			} elseif ($defaultGroup->defaultAdditionalEmailText) {
				$additionalEmailText = $defaultGroup->defaultAdditionalEmailText;
			}

			return new CurrentContextCatalogPermissions(
				$catalogPermission,
				$prefilledCatalogPermission->buyAllowed ?? $customer->buyAllowed ?? $defaultGroup->defaultBuyAllowed,
				$prefilledCatalogPermission->orderAllowed ?? $customer->orderAllowed ?? true,
				$prefilledCatalogPermission->viewAllOrders ?? $customer->viewAllOrders ?? $defaultGroup->defaultViewAllOrders,
				$showPricesWithoutVat,
				$showPricesWithVat,
				$priorityPrice,
				$additionalEmailText ?? '',
				[$prefilledCatalogPermission?->displayedTransactionEmailBlocks, $customer?->displayedTransactionEmailBlocks, $defaultGroup->defaultDisplayedTransactionEmailBlocks],
				$this->templateNamesService->getOrderEmailBlocks(),
			);
		});
	}
}
