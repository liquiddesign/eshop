<?php

namespace Eshop\Actions\Checkout;

use Base\BaseAction;
use Eshop\DTO\Checkout\DeliveryTypeWithPaymentTypes;
use Eshop\ShopperUser;

class GetDeliveryTypesWithPaymentTypes extends BaseAction
{
	public function __construct(private readonly ShopperUser $shopperUser)
	{
	}

	/**
	 * Get delivery types with payment types based on current checkout environment.
	 * @return array<\Eshop\DTO\Checkout\DeliveryTypeWithPaymentTypes>
	 */
	public function execute(): array
	{
		return $this->getLocalCachedOutput($this::class, function (): array {
			$checkoutManager = $this->shopperUser->getCheckoutManager();
			$withVat = $this->shopperUser->getMainPriceType() === 'withVat';
			/** @var \StORM\Collection<\Eshop\DB\DeliveryType> $deliveryTypes */
			$deliveryTypes = $checkoutManager->getDeliveryTypes($withVat);
			/** @var array<\Eshop\DB\PaymentType> $paymentTypes */
			$paymentTypes = $checkoutManager->getPaymentTypes()->toArray();

			$result = [];

			foreach ($deliveryTypes as $deliveryType) {
				$allowedPaymentTypes = \array_keys($deliveryType->allowedPaymentTypes->toArray());

				foreach ($allowedPaymentTypes as $paymentId) {
					if (isset($paymentTypes[$paymentId])) {
						continue;
					}

					unset($allowedPaymentTypes[$paymentId]);
				}

				if (!$allowedPaymentTypes) {
					continue;
				}

				$result[$deliveryType->getPK()] = new DeliveryTypeWithPaymentTypes($deliveryType, $paymentTypes);
			}

			return $result;
		});
	}
}
