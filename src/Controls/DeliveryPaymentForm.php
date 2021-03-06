<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\DeliveryType;
use Eshop\DB\PaymentType;
use Eshop\Shopper;
use Nette;
use StORM\Collection;

class DeliveryPaymentForm extends Nette\Application\UI\Form
{
	private CheckoutManager $checkoutManager;
	
	private Shopper $shopper;
	
	public function __construct(Shopper $shopper, CheckoutManager $checkoutManager)
	{
		parent::__construct();
		
		$this->checkoutManager = $checkoutManager;
		$this->shopper = $shopper;
		
		$deliveriesList = $this->addRadioList('deliveries', 'deliveryPaymentForm.payments', $checkoutManager->getDeliveryTypes()->toArrayOf('name'));
		$paymentsList = $this->addRadioList('payments', 'deliveryPaymentForm.payments', $checkoutManager->getPaymentTypes()->toArrayOf('name'));
		$deliveriesList->setRequired();
		$paymentsList->setRequired();
		
		$this->addCombinationRules($deliveriesList, $paymentsList, $checkoutManager->getDeliveryTypes());
		
		// @TODO: overload toggle (https://pla.nette.org/cs/forms-toggle#toc-jak-pridat-animaci)
		
		$this->addSubmit('submit');
		$this->onSuccess[] = [$this, 'success'];
		
		
		$deliveriesList->setDefaultValue($this->getSelectedDeliveryType());
		$paymentsList->setDefaultValue($this->getSelectedPaymentType());
	}
	
	public function success(DeliveryPaymentForm $form): void
	{
		$values = $form->getValues();
		
		$this->checkoutManager->syncPurchase(['deliveryType' => $values->deliveries, 'paymentType' => $values->payments]);
	}
	
	private function addCombinationRules(Nette\Forms\Controls\RadioList $deliveriesList, Nette\Forms\Controls\RadioList $paymentsList, Collection $deliveryTypes): void
	{
		/**
		 * @var string $deliveryId
		 * @var \Eshop\DB\DeliveryType $deliveryType
		 */
		foreach ($deliveryTypes as $deliveryId => $deliveryType) {
			$deliveriesCondition = $deliveriesList->addCondition($this::EQUAL, $deliveryId);
			$paymentsCondition = $paymentsList->addConditionOn($this['deliveries'], $this::EQUAL, $deliveryId);
			
			$allowedPaymentTypes = \array_keys($deliveryType->allowedPaymentTypes->toArray());
			
			foreach ($allowedPaymentTypes as $paymentId) {
				$deliveriesCondition->toggle($paymentId);
			}
			
			if (!$allowedPaymentTypes) {
				return;
			}
			
			$paymentsCondition->addRule($this::IS_IN, 'Nesprávná kombinace dopravy a platby. Vyberte prosím jinou platbu.', $allowedPaymentTypes);
		}
	}
	
	private function getSelectedDeliveryType(): ?DeliveryType
	{
		$purchase = $this->checkoutManager->getPurchase(true);
		$shopper = $this->shopper;
		
		if (!$purchase->deliveryType && $shopper->getCustomer() && $shopper->getCustomer()->preferredDeliveryType) {
			return $shopper->getCustomer()->preferredDeliveryType;
		}
		
		return $purchase->deliveryType;
	}
	
	private function getSelectedPaymentType(): ?PaymentType
	{
		$purchase = $this->checkoutManager->getPurchase(true);
		$shopper = $this->shopper;
		
		if (!$purchase->deliveryType && $shopper->getCustomer() && $shopper->getCustomer()->preferredPaymentType) {
			return $shopper->getCustomer()->preferredPaymentType;
		}
		
		return $purchase->paymentType;
	}
}
