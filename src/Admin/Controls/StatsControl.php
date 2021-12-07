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
use Eshop\Shopper;
use Forms\Form;
use Nette;
use Nette\Application\UI\Control;

class StatsControl extends Control
{
	/**
	 * @persistent
	 * @var string[]
	 */
	public array $state = [];

	public Shopper $shopper;

	private AdminFormFactory $formFactory;

	private OrderRepository $orderRepository;

	private MerchantRepository $merchantRepository;

	private CurrencyRepository $currencyRepository;

	private CustomerRepository $customerRepository;

	private CategoryRepository $categoryRepository;

	private DiscountCouponRepository $discountCouponRepository;

	private ?Customer $signedInCustomer;

	public function __construct(
		AdminFormFactory $formFactory,
		Shopper $shopper,
		OrderRepository $orderRepository,
		MerchantRepository $merchantRepository,
		CurrencyRepository $currencyRepository,
		CustomerRepository $customerRepository,
		CategoryRepository $categoryRepository,
		DiscountCouponRepository $discountCouponRepository,
		?Customer $signedInCustomer = null
	) {
		$this->formFactory = $formFactory;
		$this->shopper = $shopper;
		$this->orderRepository = $orderRepository;
		$this->merchantRepository = $merchantRepository;
		$this->currencyRepository = $currencyRepository;
		$this->customerRepository = $customerRepository;
		$this->categoryRepository = $categoryRepository;
		$this->discountCouponRepository = $discountCouponRepository;
		$this->signedInCustomer = $signedInCustomer;
	}

	public function createComponentStatsFilterForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addText('from', 'Od')
			->setHtmlAttribute('min', (new Nette\Utils\DateTime())->modify('- 1 year')->format('Y-m-d'))
			->setHtmlAttribute('max', (new Nette\Utils\DateTime())->format('Y-m-d'))
			->setHtmlType('date')
			->setRequired()
			->setDefaultValue((new Nette\Utils\DateTime())->modify('- 1 year')->format('Y-m-d'));
		$form->addText('to', 'Do')
			->setHtmlAttribute('min', (new Nette\Utils\DateTime())->modify('- 1 year')->format('Y-m-d'))
			->setHtmlAttribute('max', (new Nette\Utils\DateTime())->format('Y-m-d'))
			->setHtmlType('date')
			->setRequired()
			->setDefaultValue((new Nette\Utils\DateTime())->format('Y-m-d'));
		$form->addDataSelect('customerType', 'Typ zákazníka', ['new' => 'Nový', 'current' => 'Stávající'])->setPrompt('- Všichni zákazníci -');
		$form->addDataSelect(
			'customer',
			'Zákazník',
			$this->customerRepository->getCollection(true)->select(['emailName' => 'CONCAT(fullName, " : ", email)'])->toArrayOf('emailName'),
		)->setPrompt('- Zákazník -');
		$form->addDataSelect('merchant', 'Obchodník', $this->merchantRepository->getArrayForSelect())->setPrompt('- Obchodník -');
		$form->addDataSelect('category', 'Kategorie', $this->categoryRepository->getTreeArrayForSelect())->setPrompt('- Kategorie -');

		$currencies = $this->currencyRepository->getArrayForSelect();

		$input = $form->addDataSelect('currency', 'Měna', $currencies)->setRequired();

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

		return $form;
	}

	public function render(): void
	{
		/** @var \Nette\Application\UI\Form $form */
		$form = $this->getComponent('statsFilterForm');

		$statsFrom = isset($this->state['from']) ? new Nette\Utils\DateTime($this->state['from']) : ((new Nette\Utils\DateTime())->modify('- 1 year'));
		$statsTo = isset($this->state['to']) ? new Nette\Utils\DateTime($this->state['to']) : (new Nette\Utils\DateTime());
		$customerType = $this->state['customerType'] ?? 'all';
		$customer = $this->signedInCustomer ?? (isset($this->state['customer']) ? $this->customerRepository->one($this->state['customer']) : null);
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
			->select(["date" => "DATE_FORMAT(this.createdTs, '%Y-%m')"])
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
				->select(["date" => "DATE_FORMAT(this.createdTs, '%Y-%m')"])
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

		if ($category) {
			$orders->join(['cart' => 'eshop_cart'], 'purchase.uuid = cart.fk_purchase')
				->join(['cartItem' => 'eshop_cartitem'], 'cart.uuid = cartItem.fk_cart')
				->join(['product' => 'eshop_product'], 'cartItem.fk_product = product.uuid')
				->join(['productXcategory' => 'eshop_product_nxn_eshop_category'], 'product.uuid = productXcategory.fk_product')
				->join(['category' => 'eshop_category'], 'category.uuid = productXcategory.fk_category')
				->where('category.path LIKE :s', ['s' => "$category->path%"]);
		}

		$this->template->shopper = $this->shopper;
		$this->template->monthlyOrders = $this->orderRepository->getGroupedOrdersPrices($orders, $statsFrom, $statsTo, $currency);
		$this->template->boughtCategories = $this->orderRepository->getOrdersCategoriesGroupedByAmountPercentage($orders, $currency);
		$this->template->topProducts = $this->orderRepository->getOrdersTopProductsByAmount($orders, $currency);
		$this->template->averageOrderPrice = $this->orderRepository->getAverageOrderPrice($orders);
		$this->template->lastOrder = $this->orderRepository->getLastOrder();
		$this->template->currency = $currency;
		$this->template->ordersCount = \count($orders);
		$this->template->discountCoupons = $discountCoupons = $this->discountCouponRepository->many()->where('fk_currency', $currency->getPK())->toArray();
		$this->template->usageDiscountCoupons = $this->orderRepository->getDiscountCouponsUsage($orders, $discountCoupons);

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
