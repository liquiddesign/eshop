<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\DiscountCouponRepository;
use Eshop\Exceptions\InvalidCouponException;
use Eshop\Shopper;
use Nette;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * @method onSet(\Eshop\DB\DiscountCoupon $coupon)
 * @method onRemove()
 */
class CouponForm extends \Nette\Application\UI\Form
{
	/**
	 * @var callable[]&callable(\Eshop\DB\DiscountCoupon): void
	 */
	public $onSet;
	
	/**
	 * @var callable[]&callable(): void
	 */
	public $onRemove;

	private DiscountCouponRepository $discountCouponRepository;
	
	private Shopper $shopper;
	
	private CheckoutManager $checkoutManager;
	
	public function __construct(Shopper $shopper, CheckoutManager $checkoutManager, DiscountCouponRepository $discountCouponRepository, Nette\Localization\Translator $translator)
	{
		parent::__construct();

		$this->discountCouponRepository = $discountCouponRepository;
		$this->shopper = $shopper;
		$this->checkoutManager = $checkoutManager;
		
		$discountCoupon = $checkoutManager->getDiscountCoupon();

		$this->addCheckbox('active');
		// phpcs:ignore
		$this->addText('code')->setDefaultValue($discountCoupon?->code);

		$this->onValidate[] = function (CouponForm $form) use ($checkoutManager, $shopper, $translator): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues();

			/** @var \Nette\Forms\Controls\TextInput $input */
			$input = $form['code'];

			try {
				$this->discountCouponRepository->getValidCouponByCart($values['code'], $checkoutManager->getCart(), $shopper->getCustomer(), true);
			} catch (InvalidCouponException $e) {
				Debugger::log($e->getMessage(), ILogger::DEBUG);

				$errorMsg = match ($e->getCode()) {
					InvalidCouponException::NOT_FOUND => $translator->translate('couponFormICE.notFound', 'Slevový kupón není platný'),
					InvalidCouponException::NOT_ACTIVE => $translator->translate('couponFormICE.notActive', 'Slevový kupón není platný'),
					InvalidCouponException::INVALID_CONDITIONS => $translator->translate('couponFormICE.invalidConditions', 'Slevový kupón není platný'),
					InvalidCouponException::MAX_USAGE => $translator->translate('couponFormICE.maxUsage', 'Slevový kupón není platný'),
					InvalidCouponException::LIMITED_TO_EXCLUSIVE_CUSTOMER => $translator->translate('couponFormICE.exclusiveCustomer', 'Slevový kupón není platný'),
					InvalidCouponException::LOW_CART_PRICE => $translator->translate('couponFormICE.lowPrice', 'Slevový kupón není platný'),
					InvalidCouponException::HIGH_CART_PRICE => $translator->translate('couponFormICE.highPrice', 'Slevový kupón není platný'),
					InvalidCouponException::INVALID_CURRENCY => $translator->translate('couponFormICE.invalidCurrency', 'Slevový kupón není platný'),
					default => 'unknown',
				// phpcs:ignore
				};

				$input->addError($errorMsg);
			}
		};

		$this->addSubmit('submit')->onClick[] = [$this, 'setCoupon'];
		
		if (!$discountCoupon) {
			return;
		}

		$this->addSubmit('remove')->setValidationScope([])->onClick[] = [$this, 'removeCoupon'];
	}
	
	public function setCoupon(Nette\Forms\Controls\SubmitButton $submit): void
	{
		unset($submit);

		$values = $this->getValues('array');

		$shopper = $this->shopper;
		$code = (string) $values['code'];
		
		if (!$coupon = $this->discountCouponRepository->getValidCouponByCart($code, $this->checkoutManager->getCart(), $shopper->getCustomer())) {
			return;
		}

		$this->checkoutManager->setDiscountCoupon($coupon);
		
		$this->onSet($coupon);
	}
	
	public function removeCoupon(Nette\Forms\Controls\SubmitButton $submit): void
	{
		unset($submit);

		$this->checkoutManager->setDiscountCoupon(null);
		
		$this->onRemove();
	}

	/**
	 * @deprecated Checked in onValidate, just don't use this.
	 */
	public static function validateCoupon(Nette\Forms\Controls\TextInput $input, array $args): bool
	{
		[$cart, $customer, $repository] = $args;
		
		return (bool) $repository->getValidCouponByCart((string) $input->getValue(), $cart, $customer);
	}
}
