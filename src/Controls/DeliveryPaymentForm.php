<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\DeliveryType;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\PaymentType;
use Eshop\DB\PickupPointRepository;
use Eshop\ShopperUser;
use InvalidArgumentException;
use Nette;
use StORM\Collection;

class DeliveryPaymentForm extends Nette\Application\UI\Form
{
	/**
	 * @var array<callable(self, array|object): void|callable(array|object): void>
	 */
	public array $onValidate = [];
	
	/**
	 * @var array<callable(self, array|object): void|callable(array|object): void>
	 */
	public array $onSuccess = [];

	/** @var array<callable(string, \Eshop\DB\DeliveryType, \Nette\Forms\Rules): void> */
	public array $onTogglePaymentId = [];

	public function __construct(
		public readonly ShopperUser $shopperUser,
		private readonly DeliveryTypeRepository $deliveryTypeRepository,
		private readonly Nette\Localization\Translator $translator,
		private readonly PickupPointRepository $pickupPointRepository
	) {
		parent::__construct();

		$vat = $this->shopperUser->getShowPrice() === 'withVat';
		
		$deliveriesList = $this->addRadioList('deliveries', 'deliveryPaymentForm.payments', $this->shopperUser->getCheckoutManager()->getDeliveryTypes($vat)->toArrayOf('name'))
			->setHtmlAttribute('onChange=updatePoints(this)');
		$paymentsList = $this->addRadioList('payments', 'deliveryPaymentForm.payments', $this->shopperUser->getCheckoutManager()->getPaymentTypes()->toArrayOf('name'));
		
		$pickupPoint = $this->addSelect('pickupPoint');
		
		$allPoints = [];
		$typesWithPoints = [];
		
		/** @var \Eshop\DB\DeliveryType $deliveryType */
		foreach ($this->shopperUser->getCheckoutManager()->getDeliveryTypes($vat)->toArray() as $deliveryType) {
			$pickupPoints = $this->pickupPointRepository->many()
				->join(['type' => 'eshop_pickuppointtype'], 'this.fk_pickupPointType = type.uuid')
				->join(['delivery' => 'eshop_deliverytype'], 'delivery.fk_pickupPointType = type.uuid')
				->where('delivery.uuid', $deliveryType->getPK())
				->toArrayOf('name');
			
			if (\count($pickupPoints) > 0) {
				$typesWithPoints[] = $deliveryType->getPK();
			}
			
			$allPoints += $pickupPoints;
			
			$pickupPoint->setHtmlAttribute('data-' . $deliveryType->getPK(), Nette\Utils\Json::encode($pickupPoints));
		}
		
		/** @var \Nette\Forms\Control $deliveries */
		$deliveries = $this['deliveries'];
		
		$pickupPoint->setItems($allPoints)->setPrompt('Vyberte výdejní místo')->addConditionOn($deliveries, $this::IS_IN, $typesWithPoints)->addRule($this::REQUIRED);
		
		$zasilkovnaIdInput = $this->addHidden('zasilkovnaId')->setNullable();
		$pickupPointIdInput = $this->addHidden('pickupPointId')->setNullable();
		$pickupPointNameInput = $this->addHidden('pickupPointName')->setNullable();

		$deliveriesList->setRequired();
		$paymentsList->setRequired();
		
		$this->addCombinationRules($deliveriesList, $paymentsList, $this->shopperUser->getCheckoutManager()->getDeliveryTypes($vat));
		
		// @TODO: overload toggle (https://pla.nette.org/cs/forms-toggle#toc-jak-pridat-animaci)
		
		$this->addSubmit('submit');
		$this->onValidate[] = [$this, 'validateForm'];
		$this->onSuccess[] = [$this, 'success'];
		
		try {
			$deliveriesList->setDefaultValue($this->getSelectedDeliveryType());
		} catch (InvalidArgumentException $e) {
		}
		
		try {
			$paymentsList->setDefaultValue($this->getSelectedPaymentType());
		} catch (InvalidArgumentException $e) {
		}

		$purchase = $this->shopperUser->getCheckoutManager()->getPurchase(true);

		if (isset($purchase->pickupPointId)) {
			$pickupPointIdInput->setDefaultValue($purchase->pickupPointId);
			$pickupPointNameInput->setDefaultValue($purchase->pickupPointName);

			return;
		}

		if (!isset($purchase->zasilkovnaId) || !isset($purchase->pickupPointName)) {
			return;
		}

		if (!$purchase->zasilkovnaId || !$purchase->pickupPointName) {
			return;
		}

		$zasilkovnaIdInput->setDefaultValue($purchase->zasilkovnaId);
		$pickupPointNameInput->setDefaultValue($purchase->pickupPointName);
	}
	
	public function success(DeliveryPaymentForm $form): void
	{
		$values = $form->getValues('array');

		$deliveryType = $this->deliveryTypeRepository->one($values['deliveries'], true);

		$newValues = [
			'deliveryType' => $values['deliveries'],
			'deliveryPackagesNo' => \count($deliveryType->getBoxesForItems($this->shopperUser->getCheckoutManager()->getTopLevelItems()->toArray())),
			'paymentType' => $values['payments'],
			'zasilkovnaId' => $deliveryType->code === 'zasilkovna' ? $values['zasilkovnaId'] : null,
			'pickupPointId' => $deliveryType->code !== 'zasilkovna' ? $values['pickupPointId'] : null,
			'pickupPointName' => $deliveryType->code === 'zasilkovna' || isset($values['pickupPointId']) ? $values['pickupPointName'] : null,
		];
		
		if (isset($values['pickupPoint']) && !isset($values['pickupPointId'])) {
			/** @var \Eshop\DB\PickupPoint $pickupPoint */
			$pickupPoint = $this->pickupPointRepository->one($values['pickupPoint']);

			$newValues['pickupPointId'] = $pickupPoint->code;
			$newValues['pickupPointName'] = $pickupPoint->name;
			$newValues['pickupPoint'] = $pickupPoint->getPK();
		} else {
			$this->shopperUser->getCheckoutManager()->getPurchase()->update(['pickupPoint' => null]);
		}
		
		$this->shopperUser->getCheckoutManager()->syncPurchase($newValues);
	}
	
	public function validateForm(DeliveryPaymentForm $form): void
	{
		if (!$form->isValid()) {
			return;
		}
		
		$values = $form->getValues('array');
		
		/** @var \Eshop\DB\DeliveryType|null $deliveryType */
		$deliveryType = $this->deliveryTypeRepository->one($values['deliveries']);
		
		if (!$deliveryType || $deliveryType->code !== 'zasilkovna' || $values['zasilkovnaId']) {
			return;
		}
		
		/** @var \Nette\Forms\Controls\RadioList $deliveries */
		$deliveries = $form['deliveries'];
		
		$deliveries->addError($this->translator->translate('deliveryPaymentForm.missingZasil', 'Pro dopravu Zásilkovna je nutné zvolit výdejní místo.'));
	}
	
	private function addCombinationRules(Nette\Forms\Controls\RadioList $deliveriesList, Nette\Forms\Controls\RadioList $paymentsList, Collection $deliveryTypes): void
	{
		/**
		 * @var string $deliveryId
		 * @var \Eshop\DB\DeliveryType $deliveryType
		 */
		foreach ($deliveryTypes as $deliveryId => $deliveryType) {
			$deliveriesCondition = $deliveriesList->addCondition($this::EQUAL, $deliveryId);
			
			/** @var \Nette\Forms\Control $deliveries */
			$deliveries = $this['deliveries'];
			
			$paymentsCondition = $paymentsList->addConditionOn($deliveries, $this::EQUAL, $deliveryId);
			
			$allowedPaymentTypes = \array_keys($deliveryType->allowedPaymentTypes->toArray());
			
			foreach ($allowedPaymentTypes as $paymentId) {
				if ($this->onTogglePaymentId) {
					Nette\Utils\Arrays::invoke($this->onTogglePaymentId, $paymentId, $deliveryType, $deliveriesCondition);
				} else {
					$deliveriesCondition->toggle($paymentId);
				}
			}
			
			if (!$allowedPaymentTypes) {
				continue;
			}
			
			$paymentsCondition->addRule(
				$this::IS_IN,
				$this->translator->translate('deliveryPaymentForm.badCombo', 'Nesprávná kombinace dopravy a platby. Vyberte prosím jinou platbu.'),
				$allowedPaymentTypes,
			);
		}
	}
	
	private function getSelectedDeliveryType(): ?DeliveryType
	{
		$purchase = $this->shopperUser->getCheckoutManager()->getPurchase(true);
		$shopper = $this->shopperUser;
		
		if (!$purchase->deliveryType && $shopper->getCustomer() && $shopper->getCustomer()->preferredDeliveryType) {
			return $shopper->getCustomer()->preferredDeliveryType;
		}
		
		return $purchase->deliveryType;
	}
	
	private function getSelectedPaymentType(): ?PaymentType
	{
		$purchase = $this->shopperUser->getCheckoutManager()->getPurchase(true);
		$shopper = $this->shopperUser;
		
		if (!$purchase->deliveryType && $shopper->getCustomer() && $shopper->getCustomer()->preferredPaymentType) {
			return $shopper->getCustomer()->preferredPaymentType;
		}
		
		return $purchase->paymentType;
	}
}
