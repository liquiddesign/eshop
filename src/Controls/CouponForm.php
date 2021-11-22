<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\DiscountCouponRepository;
use Eshop\Shopper;
use Nette;

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
	
	public function __construct(Shopper $shopper, CheckoutManager $checkoutManager, DiscountCouponRepository $discountCouponRepository)
	{
		parent::__construct();

		$this->discountCouponRepository = $discountCouponRepository;
		$this->shopper = $shopper;
		$this->checkoutManager = $checkoutManager;
		
		$discountCoupon = $checkoutManager->getDiscountCoupon();

		$this->addCheckbox('active');
		$this->addText('code')
			->setDefaultValue($discountCoupon ? $discountCoupon->code : null)
			->addRule([$this, 'validateCoupon'], 'Slevový kupón není platný', [$shopper->getCurrency(), $shopper->getCustomer(), $discountCouponRepository]);
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
		
		if (!$coupon = $this->discountCouponRepository->getValidCoupon($code, $shopper->getCurrency(), $shopper->getCustomer())) {
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

	public static function validateCoupon(Nette\Forms\Controls\TextInput $input, array $args): bool
	{
		[$currency, $customer, $repository] = $args;
		
		return (bool) $repository->getValidCoupon((string) $input->getValue(), $currency, $customer);
	}
}
