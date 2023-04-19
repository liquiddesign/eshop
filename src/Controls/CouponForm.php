<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\DiscountCouponRepository;
use Eshop\Exceptions\InvalidCouponException;
use Eshop\ShopperUser;
use Nette;

class CouponForm extends \Nette\Application\UI\Form
{
	/**
	 * @var array<callable(\Eshop\DB\DiscountCoupon): void>
	 */
	public array $onSet = [];
	
	/**
	 * @var array<callable(): void>
	 */
	public array $onRemove = [];

	public function __construct(private readonly ShopperUser $shopperUser, private readonly DiscountCouponRepository $discountCouponRepository, Nette\Localization\Translator $translator)
	{
		parent::__construct();

		$discountCoupon = $this->shopperUser->getCheckoutManager()->getDiscountCoupon();

		$this->addCheckbox('active');
		// phpcs:ignore
		$this->addText('code')->setDisabled((bool)$discountCoupon)->setDefaultValue($discountCoupon?->code);

		$this->onValidate[] = function (CouponForm $form) use ($shopperUser, $translator): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues();

			/** @var \Nette\Forms\Controls\TextInput $input */
			$input = $form['code'];

			try {
				$this->discountCouponRepository->getValidCouponByCart($values['code'], $this->shopperUser->getCheckoutManager()->getCart(), $shopperUser->getCustomer(), true);
			} catch (InvalidCouponException $e) {
				$errorMsg = match ($e->getCode()) {
					InvalidCouponException::NOT_FOUND => $translator->translate('couponFormICE.notFound', 'Slevový kupón není platný'),
					InvalidCouponException::NOT_ACTIVE => $translator->translate('couponFormICE.notActive', 'Slevový kupón není platný'),
					InvalidCouponException::INVALID_CONDITIONS => $translator->translate('couponFormICE.invalidConditions', 'Slevový kupón není platný'),
					InvalidCouponException::MAX_USAGE => $translator->translate('couponFormICE.maxUsage', 'Slevový kupón není platný'),
					InvalidCouponException::LIMITED_TO_EXCLUSIVE_CUSTOMER => $translator->translate('couponFormICE.exclusiveCustomer', 'Slevový kupón není platný'),
					InvalidCouponException::LOW_CART_PRICE => $translator->translate('couponFormICE.lowPrice', 'Slevový kupón není platný'),
					InvalidCouponException::HIGH_CART_PRICE => $translator->translate('couponFormICE.highPrice', 'Slevový kupón není platný'),
					InvalidCouponException::INVALID_CURRENCY => $translator->translate('couponFormICE.invalidCurrency', 'Slevový kupón není platný'),
					InvalidCouponException::INVALID_CONDITIONS_CATEGORY => $translator->translate('couponFormICE.invalidCondCat', 'Slevový kupón není platný'),
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

		$shopper = $this->shopperUser;
		$code = (string) $values['code'];
		
		if (!$coupon = $this->discountCouponRepository->getValidCouponByCart($code, $this->shopperUser->getCheckoutManager()->getCart(), $shopper->getCustomer())) {
			return;
		}

		$this->shopperUser->getCheckoutManager()->setDiscountCoupon($coupon);

		Nette\Utils\Arrays::invoke($this->onSet, $coupon);
	}
	
	public function removeCoupon(Nette\Forms\Controls\SubmitButton $submit): void
	{
		unset($submit);

		$this->shopperUser->getCheckoutManager()->setDiscountCoupon(null);

		Nette\Utils\Arrays::invoke($this->onRemove);
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
