<?php

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminFormFactory;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DiscountCouponRepository;
use Eshop\DB\MerchantRepository;
use Eshop\DB\OrderRepository;
use Eshop\ShopperUser;
use Forms\Form;
use Nette;
use Nette\Application\UI\Control;
use Tracy\Debugger;

class StatsControl extends Control
{
	/** @var array<callable(static): void> Occurs when component is attached to presenter */
	public $onAnchor = [];

	/**
	 * @persistent
	 * @var array<string>
	 */
	public array $state = [];

	public function __construct(
		private readonly AdminFormFactory $formFactory,
		public ShopperUser $shopperUser,
		private readonly OrderRepository $orderRepository,
		private readonly MerchantRepository $merchantRepository,
		private readonly CurrencyRepository $currencyRepository,
		private readonly CustomerRepository $customerRepository,
		private readonly CategoryRepository $categoryRepository,
		private readonly DiscountCouponRepository $discountCouponRepository,
		private readonly ?Customer $signedInCustomer = null
	) {

		$form = $this->formFactory->create();

		$form->addText('from', 'Od')
			->setHtmlAttribute('max', (new \Carbon\Carbon())->format('Y-m-d'))
			->setHtmlType('date')
			->setRequired()
			->setDefaultValue((new \Carbon\Carbon())->modify('- 1 week')->format('Y-m-d'));
		$form->addText('to', 'Do')
			->setHtmlAttribute('max', (new \Carbon\Carbon())->format('Y-m-d'))
			->setHtmlType('date')
			->setRequired()
			->setDefaultValue((new \Carbon\Carbon())->format('Y-m-d'));
		$form->addSelect2('customerType', 'Typ zákazníka', ['new' => 'Nový', 'current' => 'Stávající'])->setPrompt('- Všichni zákazníci -');
		$form->addText('customer', 'Zákazník')->setNullable()->setHtmlAttribute('placeholder', 'E-mail zákazníka (pouze registrovaní)');
		$form->addSelect2('merchant', 'Obchodník', $this->merchantRepository->getArrayForSelect())->setPrompt('- Obchodník -');
		$form->addSelect2('category', 'Kategorie', $this->categoryRepository->getTreeArrayForSelect())->setPrompt('- Kategorie -');

		$currencies = $this->currencyRepository->getArrayForSelect();

		$input = $form->addSelect2('currency', 'Měna', $currencies)->setRequired();

		if (\count($currencies) > 0) {
			$input->setDefaultValue(Nette\Utils\Arrays::first($currencies));
		}

		$form->addSubmit('submit', 'Zobrazit');

		$form->onValidate[] = function ($form): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues();

			if ($values->from <= $values->to) {
				return;
			}

			$form->addError('Neplatný rozsah!');
		};

		$form->onSuccess[] = function (Form $form): void {
			$this->state = $form->getValues('array');

			$this->redirect('this');
		};

		$this->addComponent($form, 'form');
	}

	public function render(): void
	{
		Debugger::$showBar = false;

		/** @var \Nette\Application\UI\Form $form */
		$form = $this->getComponent('form');

		$statsFrom = isset($this->state['from']) ? new \Carbon\Carbon($this->state['from']) : ((new \Carbon\Carbon())->modify('- 1 week'));
		$statsTo = isset($this->state['to']) ? new \Carbon\Carbon($this->state['to']) : (new \Carbon\Carbon());
		$customerType = $this->state['customerType'] ?? 'all';
		$customer = $this->signedInCustomer ??
			(isset($this->state['customer']) ? $this->customerRepository->many()->where('this.email LIKE :s', ['s' => '%' . $this->state['customer'] . '%'])->first() : null);

		if (isset($this->state['customer']) && !$customer) {
			$this->flashMessage('Zákazník nenalezen', 'warning');
		}

		$merchant = isset($this->state['merchant']) ? $this->merchantRepository->one($this->state['merchant']) : null;
		$category = isset($this->state['category']) ? $this->categoryRepository->one($this->state['category']) : null;

		/** @var \Eshop\DB\Currency $currency */
		$currency = isset($this->state['currency']) ? $this->currencyRepository->one($this->state['currency'], true) : $this->currencyRepository->many()->first();

		$form->setDefaults($this->state);

		$statsFrom->setTime(0, 0);
		$statsTo->setTime(23, 59, 59);
		$fromString = $statsFrom->format('Y-m-d\TH:i:s');
		$toString = $statsTo->format('Y-m-d\TH:i:s');

		$orders = $this->orderRepository->many()
			->where('this.receivedTs IS NOT NULL AND this.completedTs IS NOT NULL AND this.canceledTs IS NULL')
			->select(['date' => "DATE_FORMAT(this.createdTs, '%Y-%m')"])
			->where('this.createdTs >= :from AND this.createdTs <= :to', ['from' => $fromString, 'to' => $toString])
			->join(['purchase' => 'eshop_purchase'], 'purchase.uuid = this.fk_purchase')
			->where('purchase.fk_currency', $currency->getPK());

		if ($customerType !== 'all' && !$customer) {
			$subSelect = $this->orderRepository->many()
				->join(['purchase' => 'eshop_purchase'], 'purchase.uuid = this.fk_purchase')
				->setGroupBy(['purchase.fk_customer'], 'customerCount ' . ($customerType === 'new' ? '= 1' : '> 1'))
				->select(['customerCount' => 'COUNT(purchase.fk_customer)'])
				->select(['customerUuid' => 'purchase.fk_customer'])
				->where('this.receivedTs IS NOT NULL AND this.completedTs IS NOT NULL AND this.canceledTs IS NULL')
				->select(['date' => "DATE_FORMAT(this.createdTs, '%Y-%m')"])
				->where('this.createdTs >= :from AND this.createdTs <= :to', ['from' => $fromString, 'to' => $toString])
				->where('purchase.fk_currency', $currency->getPK());

			$orders->where('purchase.fk_customer', \array_values($subSelect->toArrayOf('customerUuid')));
		}

		if ($customer) {
			$orders->where('purchase.fk_customer', $customer->getPK());
		}

		if ($merchant) {
			$orders->join(['customerXmerchant' => 'eshop_merchant_nxn_eshop_customer'], 'customerXmerchant.fk_customer = purchase.fk_customer')
				->where('customerXmerchant.fk_merchant', $merchant->getPK());
		}

		$orders->join(['cart' => 'eshop_cart'], 'purchase.uuid = cart.fk_purchase')
			->join(['cartCurrency' => 'eshop_currency'], 'cartCurrency.uuid = cart.fk_currency')
			->select([
				'purchaseCart' => 'cart.uuid',
				'cartCurrency' => 'cartCurrency.uuid',
			]);

		if ($category) {
			$orders->join(['cartItem' => 'eshop_cartitem'], 'cart.uuid = cartItem.fk_cart', [], 'INNER')
				->join(['product' => 'eshop_product'], 'cartItem.fk_product = product.uuid', [], 'INNER')
				->join(['productXcategory' => 'eshop_product_nxn_eshop_category'], 'product.uuid = productXcategory.fk_product', [], 'INNER')
				->where('productXcategory.fk_category', $category->getPK());
		}

		$orders = $orders->toArray();

		$this->template->shopper = $this->shopperUser;
		$this->template->monthlyOrders = $this->orderRepository->getGroupedOrdersPrices($orders, $currency);
		$this->template->boughtCategories = $this->orderRepository->getOrdersCategoriesGroupedByAmountPercentage($orders, $currency);
		$this->template->topProducts = $this->orderRepository->getOrdersTopProductsByAmount($orders, $currency);
		$this->template->sumOrderPrice = $this->orderRepository->getSumOrderPrice($orders);
		$this->template->averageOrderPrice = $this->orderRepository->getAverageOrderPrice($orders);
		$this->template->lastOrder = $this->orderRepository->getLastOrder();
		$this->template->currency = $currency;
		$this->template->ordersCount = \count($orders);
		$this->template->discountCoupons = $discountCoupons = $this->discountCouponRepository->many()->where('fk_currency', $currency->getPK())->toArray();
		$this->template->usageDiscountCoupons = $this->orderRepository->getDiscountCouponsUsage($orders, $discountCoupons)[0];

		/** @var \Eshop\Admin\StatsPresenter $presenter */
		$presenter = $this->getPresenter();
		$this->template->admin = $presenter->admin;

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render($this->template->getFile() ?: __DIR__ . \DIRECTORY_SEPARATOR . 'statsControl.latte');
	}

	public function handleResetStatsFilter(): void
	{
		$this->state = [];

		$this->redirect('this');
	}
}
