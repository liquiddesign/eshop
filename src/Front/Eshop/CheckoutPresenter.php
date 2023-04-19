<?php

declare(strict_types=1);

namespace Eshop\Front\Eshop;

use Eshop\Controls\AddressesForm;
use Eshop\Controls\CartChecker;
use Eshop\Controls\CartImportForm;
use Eshop\Controls\CartItemList;
use Eshop\Controls\CouponForm;
use Eshop\Controls\DeliveryPaymentForm;
use Eshop\Controls\IAddressesFormFactory;
use Eshop\Controls\ICartCheckerFactory;
use Eshop\Controls\ICartImportFactory;
use Eshop\Controls\ICartItemListFactory;
use Eshop\Controls\ICouponFormFactory;
use Eshop\Controls\IDeliveryPaymentFormFactory;
use Eshop\Controls\INoteFormFactory;
use Eshop\Controls\IOrderFormFactory;
use Eshop\Controls\NoteForm;
use Eshop\Controls\OrderForm;
use Eshop\DB\CartRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DiscountCoupon;
use Eshop\DB\DiscountCouponRepository;
use Eshop\DB\MerchantRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Nette;
use Web\DB\SettingRepository;

abstract class CheckoutPresenter extends \Eshop\Front\FrontendPresenter
{
	/** @inject */
	public ICartItemListFactory $cartItemListFactory;

	/** @inject */
	public ICartImportFactory $cartImportFactory;

	/** @inject */
	public INoteFormFactory $noteFormFactory;

	/** @inject */
	public ICartCheckerFactory $cartCheckerFactory;

	/** @inject */
	public IDeliveryPaymentFormFactory $deliveryPaymentFormFactory;

	/** @inject */
	public IAddressesFormFactory $addressesFormFactory;

	/** @inject */
	public ICouponFormFactory $couponFormFactory;

	/** @inject */
	public IOrderFormFactory $orderFormFactory;

	/** @inject */
	public OrderRepository $orderRepository;

	/** @inject */
	public CustomerRepository $customerRepository;

	/** @inject */
	public SettingRepository $settingRepository;

	/** @inject */
	public MerchantRepository $merchantRepository;

	/** @inject */
	public CartRepository $cartRepository;

	/** @inject */
	public DiscountCouponRepository $discountCouponRepository;

	public function startup(): void
	{
		parent::startup();

		$this->shopperUser->getCheckoutManager()->autoFixCart();

		$this->shopperUser->getCheckoutManager()->onOrderCreate[] = function (Order $order): void {
			/** @var \Eshop\DB\Order $order */
			$order = $this->orderRepository->one($order->getPK(), true);

			$emailVariables = $this->orderRepository->getEmailVariables($order);

			$mail = $this->templateRepository->createMessage('order.created', $emailVariables, $order->purchase->email, $order->purchase->ccEmails);
			$this->mailer->send($mail);

			if ($order->purchase->customer) {
				$merchants = $this->merchantRepository->getMerchantsByCustomer($order->purchase->customer);

				$emailVariables += ['customer' => $order->purchase->customer->toArray()];

				foreach ($merchants as $merchant) {
					if ($merchant->customerEmailNotification && $merchant->email) {
						$mail = $this->templateRepository->createMessage('order.created.merchantInfo', $emailVariables, $merchant->email);
						$this->mailer->send($mail);
					}
				}
			}

			$mail = $this->templateRepository->createMessage('order.createdAdmin', $emailVariables);
			$this->mailer->send($mail);
		};

		$this->shopperUser->getCheckoutManager()->onCustomerCreate[] = function (Customer $customer): void {
			$params = [
				'email' => $customer->email,
				'link' => $customer->account && $customer->account->confirmationToken ? $this->link('//:Eshop:User:confirmEmailToken', $customer->account->confirmationToken) : '#',
			];

			if (!Nette\Utils\Validators::isEmail($customer->email)) {
				return;
			}

			$registerConfirmation = $this->templateRepository->createMessage('register.confirmation', $params, $customer->email);
			$registerSuccess = $this->templateRepository->createMessage('register.success', $params, $customer->email);

			$mail = $this->shopperUser->getRegistrationConfiguration()['emailAuthorization'] ? $registerConfirmation : $registerSuccess;
			$this->mailer->send($mail);
		};
	}

	public function createComponentAddressesForm(): AddressesForm
	{
		$form = $this->addressesFormFactory->create();
		$form->onSuccess[] = function (): void {
			$this->redirect('deliveryPayment');
		};

		return $form;
	}

	public function createComponentNoteForm(): NoteForm
	{
		$form = $this->noteFormFactory->create();
		$form->onSuccess[] = function (): void {
			$this->redirect('addresses');
		};

		return $form;
	}

	public function createComponentOrderForm(): OrderForm
	{
		$form = $this->orderFormFactory->create();

		$form->onSuccess[] = function (): void {
			$this->redirect('order');
		};

		$form->onBuyError[] = function (int $errorCode): void {
			$this->flashMessage($this->translator->translate('CH.cantBuy', 'Nemůžete provést objednávku!'), 'danger');
			$this->redirect('this');
		};

		return $form;
	}

	public function createComponentDeliveryPaymentForm(): DeliveryPaymentForm
	{
		$form = $this->deliveryPaymentFormFactory->create();
		$form->onSuccess[] = function (): void {
			$this->redirect('summary');
		};

		return $form;
	}

	public function createComponentCartItemList(): CartItemList
	{
		$cart = $this->cartItemListFactory->create();
		$cart->onAnchor[] = function (CartItemList $itemList): void {
			$itemList->template->setFile(\dirname(__DIR__, 6) . '/app/Eshop/Controls/cartItemList.latte');
		};

		$redirectCallback = function (): void {
			$this->redirect('this');
		};

		$cart->onItemDelete[] = $redirectCallback;
		$cart->onDeleteAll[] = $redirectCallback;
		$cart->onItemAmountChange[] = $redirectCallback;

		return $cart;
	}

	public function createComponentCartImport(): CartImportForm
	{
		$cartImport = $this->cartImportFactory->create();
		$cartImport->onSuccess[] = function (): void {
			$this->getPresenter()->flashMessage($this->translator->translate('CH.importSuccess', 'Import produktů proběhl úspěšně.'), 'success');
			$this->getPresenter()->redirect('this');
		};

		return $cartImport;
	}

	public function createComponentCartChecker(): CartChecker
	{
		$cartChecker = $this->cartCheckerFactory->create();
		$cartChecker->onAnchor[] = function (CartChecker $cartChecker): void {
			$cartChecker->template->setFile(\dirname(__DIR__, 6) . '/app/Eshop/Controls/cartChecker.latte');
		};

		return $cartChecker;
	}

	public function createComponentCouponForm(): CouponForm
	{
		$couponForm = $this->couponFormFactory->create();

		$couponForm->onSet[] = function (DiscountCoupon $coupon): void {
			$this->flashMessage($this->translator->translate('couponForm.couponSet', 'Kupón přidán do košíku.'), 'success');

			$this->redirect('this');
		};

		return $couponForm;
	}

	public function actionAddresses(): void
	{
		if (!$this->shopperUser->getCheckoutManager()->isStepAllowed('addresses')) {
			$maxStep = $this->shopperUser->getCheckoutManager()->getMaxStep();

			if ($maxStep) {
				$this->redirect($maxStep);
			} else {
				$this->redirect(':Web:Index:default');
			}
		}
	}

	public function renderAddresses(): void
	{
		$this->template->steps = $this->shopperUser->getCheckoutManager()->getCheckoutSteps();
	}

	public function actionCart(?string $coupon = null): void
	{
		if ($coupon) {
			$this->shopperUser->getCheckoutManager()->setDiscountCoupon(
				$this->discountCouponRepository->getValidCouponByCart($coupon, $this->shopperUser->getCheckoutManager()->getCart(), $this->shopperUser->getCustomer()),
			);

			$this->redirect('this');
		}
	}

	public function renderCart(): void
	{
		$vat = false;

		if ($this->shopperUser->showPricesWithVat() && $this->shopperUser->showPricesWithoutVat()) {
			if ($this->shopperUser->showPriorityPrices() === 'withVat') {
				$vat = true;
			}
		} else {
			if ($this->shopperUser->showPricesWithVat()) {
				$vat = true;
			}
		}

		$this->template->sumAmount = $this->shopperUser->getCheckoutManager()->getSumAmount();
		$this->template->deliveryDiscount = $this->shopperUser->getCheckoutManager()->getDeliveryDiscount();
		$this->template->possibleDeliveryDiscount = $this->shopperUser->getCheckoutManager()->getPossibleDeliveryDiscount();
		$this->template->deliveryDiscountLeft = $this->shopperUser->getCheckoutManager()->getPossibleDeliveryDiscount() ?
			$this->shopperUser->getCheckoutManager()->getPossibleDeliveryDiscount()->discountPriceFrom -
			($vat ? $this->shopperUser->getCheckoutManager()->getCartCheckoutPriceVat() : $this->shopperUser->getCheckoutManager()->getCartCheckoutPrice()) :
			null;
		$this->template->steps = $this->shopperUser->getCheckoutManager()->getCheckoutSteps();
		$this->template->cartCheckoutPrice = $this->shopperUser->getCheckoutManager()->getCartCheckoutPrice();
		$this->template->cartCheckoutPriceVat = $this->shopperUser->getCheckoutManager()->getCartCheckoutPriceVat();
		$this->template->weightSum = $this->shopperUser->getCheckoutManager()->getSumWeight();
		$this->template->dimensionSum = $this->shopperUser->getCheckoutManager()->getSumDimension();
		$this->template->loyaltyProgramPointsGain = $this->shopperUser->getCustomer() ?
			$this->cartRepository->getLoyaltyProgramPointsGainByCartItemsAndCustomer($this->shopperUser->getCheckoutManager()->getItems(), $this->shopperUser->getCustomer()) :
			null;
	}

	public function actionDeliveryPayment(): void
	{
		if (!$this->shopperUser->getCheckoutManager()->isStepAllowed('deliveryPayment')) {
			$maxStep = $this->shopperUser->getCheckoutManager()->getMaxStep();

			if ($maxStep) {
				$this->redirect($maxStep);
			} else {
				$this->redirect(':Web:Index:default');
			}
		}
	}

	public function renderDeliveryPayment(): void
	{
		$this->template->deliveryTypes = $this->shopperUser->getCheckoutManager()->getDeliveryTypes()->toArray();
		$this->template->paymentTypes = $this->shopperUser->getCheckoutManager()->getPaymentTypes()->toArray();
		$this->template->steps = $this->shopperUser->getCheckoutManager()->getCheckoutSteps();
	}

	public function actionSummary(): void
	{
		if (!$this->shopperUser->getCheckoutManager()->isStepAllowed('summary')) {
			$maxStep = $this->shopperUser->getCheckoutManager()->getMaxStep();

			if ($maxStep) {
				$this->redirect($maxStep);
			} else {
				$this->redirect(':Web:Index:default');
			}
		}
	}

	public function renderSummary(): void
	{
		$purchase = $this->shopperUser->getCheckoutManager()->getPurchase();

		$this->template->merchants = $this->merchantRepository->getMerchantsByCustomer($this->shopperUser->getCustomer());
		$this->template->order = $purchase;
		
		$this->template->billAddress = $purchase->billAddress;
		$this->template->deliveryAddress = $purchase->deliveryAddress ?: $purchase->billAddress;
		$this->template->items = $this->shopperUser->getCheckoutManager()->getItems();
		$this->template->deliveryType = $this->shopperUser->getCheckoutManager()->getPurchase(true)->deliveryType;
		$this->template->paymentType = $this->shopperUser->getCheckoutManager()->getPurchase(true)->paymentType;

		$this->template->priceSum = $this->shopperUser->getCheckoutManager()->getSumPrice();
		$this->template->priceSumVat = $this->shopperUser->getCheckoutManager()->getSumPriceVat();
		$this->template->deliveryPaymentPrice = $this->shopperUser->getCheckoutManager()->getDeliveryPrice() + $this->shopperUser->getCheckoutManager()->getPaymentPrice();
		$this->template->deliveryPaymentPriceVat = $this->shopperUser->getCheckoutManager()->getDeliveryPriceVat() + $this->shopperUser->getCheckoutManager()->getPaymentPriceVat();
		$this->template->discountPrice = $this->shopperUser->getCheckoutManager()->getDiscountPrice();
		$this->template->discountPriceVat = $this->shopperUser->getCheckoutManager()->getDiscountPriceVat();
		$this->template->checkoutPrice = $this->shopperUser->getCheckoutManager()->getCheckoutPrice();
		$this->template->checkoutPriceVat = $this->shopperUser->getCheckoutManager()->getCheckoutPriceVat();
		$this->template->weightSum = $this->shopperUser->getCheckoutManager()->getSumWeight();
		$this->template->dimensionSum = $this->shopperUser->getCheckoutManager()->getSumDimension();
		$this->template->steps = $this->shopperUser->getCheckoutManager()->getCheckoutSteps();
	}

	public function renderOrder(): void
	{
		$order = $this->shopperUser->getCheckoutManager()->getLastOrder();

		if (!$order) {
			throw new Nette\Application\BadRequestException('Order not found');
		}

		$this->template->steps = $this->shopperUser->getCheckoutManager()->getCheckoutSteps();
		$this->template->order = $order;
	}
}
