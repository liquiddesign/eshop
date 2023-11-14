<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\DeliveryType;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\PaymentType;
use Eshop\DB\PickupPointRepository;
use Eshop\Shopper;
use InvalidArgumentException;
use Nette;
use StORM\Collection;

class DeliveryPaymentForm extends Nette\Application\UI\Form
{
	/**
	 * @var array<callable(self, array|object): void|callable(array|object): void>
	 */
	public $onValidate = [];
	
	/**
	 * @var array<callable(self, array|object): void|callable(array|object): void>
	 */
	public $onSuccess = [];
	
	public Shopper $shopper;

	protected Nette\Localization\Translator $translator;

	protected CheckoutManager $checkoutManager;

	protected DeliveryTypeRepository $deliveryTypeRepository;

	protected PickupPointRepository $pickupPointRepository;
	
	public function __construct(
		Shopper $shopper,
		CheckoutManager $checkoutManager,
		DeliveryTypeRepository $deliveryTypeRepository,
		Nette\Localization\Translator $translator,
		PickupPointRepository $pickupPointRepository
	) {
		parent::__construct();
		
		$this->checkoutManager = $checkoutManager;
		$this->shopper = $shopper;
		$this->deliveryTypeRepository = $deliveryTypeRepository;
		$this->translator = $translator;
		$this->pickupPointRepository = $pickupPointRepository;
		
		$vat = $this->shopper->getShowPrice() === 'withVat';
		
		$deliveriesList = $this->addRadioList('deliveries', 'deliveryPaymentForm.payments', $checkoutManager->getDeliveryTypes($vat)->toArrayOf('name'))
			->setHtmlAttribute('onChange=updatePoints(this)');
		$paymentsList = $this->addRadioList('payments', 'deliveryPaymentForm.payments', $checkoutManager->getPaymentTypes()->toArrayOf('name'));
		
		$pickupPoint = $this->addSelect('pickupPoint');
		
		$allPoints = [];
		$typesWithPoints = [];
		
		/** @var \Eshop\DB\DeliveryType $deliveryType */
		foreach ($checkoutManager->getDeliveryTypes($vat)->toArray() as $deliveryType) {
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
		
		$this->addCombinationRules($deliveriesList, $paymentsList, $checkoutManager->getDeliveryTypes($vat));
		
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

		$purchase = $this->checkoutManager->getPurchase(true);

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
			'deliveryPackagesNo' => \count($deliveryType->getBoxesForItems($this->checkoutManager->getTopLevelItems()->toArray())),
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
			$this->checkoutManager->getPurchase()->update(['pickupPoint' => null]);
		}
		
		$this->checkoutManager->syncPurchase($newValues);
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
	
	protected function addCombinationRules(Nette\Forms\Controls\RadioList $deliveriesList, Nette\Forms\Controls\RadioList $paymentsList, Collection $deliveryTypes): void
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
				$deliveriesCondition->toggle($paymentId);
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
