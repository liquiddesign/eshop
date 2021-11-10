<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\Admin\Controls\OrderGridFactory;
use Eshop\BackendPresenter;
use Eshop\DB\AddressRepository;
use Eshop\DB\AutoshipRepository;
use Eshop\DB\BannedEmailRepository;
use Eshop\DB\CartItem;
use Eshop\DB\CartItemRepository;
use Eshop\DB\CartRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Delivery;
use Eshop\DB\DeliveryRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\InternalCommentOrderRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PackageItemRepository;
use Eshop\DB\PaymentRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\StoreRepository;
use Eshop\DB\SupplierRepository;
use Forms\Form;
use Grid\Datagrid;
use League\Csv\Writer;
use Messages\DB\TemplateRepository;
use Nette\Application\Application;
use Nette\Application\Responses\FileResponse;
use Nette\Forms\Controls\Button;
use Nette\Forms\Controls\TextInput;
use Nette\Http\Request;
use Nette\Mail\Mailer;
use Nette\Utils\DateTime;
use StORM\Literal;

class OrderPresenter extends BackendPresenter
{
	protected const CONFIGURATION = [
		'exportPPC' => false,
		'exportPPC_columns' => [],
		'defaultExportPPC_columns' => [],
	];

	/** @inject */
	public OrderRepository $orderRepository;

	/** @inject */
	public CartRepository $cartRepository;

	/** @inject */
	public DeliveryRepository $deliveryRepository;

	/** @inject */
	public PaymentRepository $paymentRepository;

	/** @inject */
	public DeliveryTypeRepository $deliveryTypeRepository;

	/** @inject */
	public PaymentTypeRepository $paymentTypeRepository;

	/** @inject */
	public CustomerRepository $customerRepository;

	/** @inject */
	public AutoshipRepository $autoshipRepository;

	/** @inject */
	public CurrencyRepository $currencyRepository;

	/** @inject */
	public OrderGridFactory $orderGridFactory;

	/** @inject */
	public StoreRepository $storeRepository;

	/** @inject */
	public \Eshop\CheckoutManager $checkoutManager;

	/** @inject */
	public Application $application;

	/** @inject */
	public Request $request;

	/** @inject */
	public \Eshop\Shopper $shopper;

	/** @inject */
	public CartItemRepository $cartItemRepo;

	/** @inject */
	public ProductRepository $productRepo;

	/** @inject */
	public SupplierRepository $supplierRepository;

	/** @inject */
	public AddressRepository $addressRepository;

	/** @inject */
	public PackageItemRepository $packageItemRepository;

	/** @inject */
	public TemplateRepository $templateRepository;

	/** @inject */
	public BannedEmailRepository $bannedEmailRepository;

	/** @inject */
	public Mailer $mailer;

	/** @inject */
	public InternalCommentOrderRepository $commentRepository;

	/** @persistent */
	public string $tab = 'received';

	public function createComponentOrdersGrid(): Datagrid
	{
		return $this->orderGridFactory->create($this->tab, self::CONFIGURATION);
	}

	public function createComponentDeliveryGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->getParameter('order')->deliveries, 20, 'createdTs', 'DESC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Doprava', 'getTypeName()', '%s', null)->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Dropshipping', 'supplier.name', '%s', null)->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Externí číslo', 'externalId', '%s');
		$grid->addColumnText('Expedováno', "shippedTs|date:'d.m.Y G:i'", '%s', 'shshippedTsippingDate', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumnText('Den doručování', "shippingDate|date:'d.m.Y'", '%s', 'shippingDate', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];

		$grid->addColumnText('Cena bez DPH', 'price|price:currency.code', '%s', 'price', ['class' => 'fit text-right'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumnText('Cena s DPH', 'priceVat|price:currency.code', '%s', 'priceVat', ['class' => 'fit text-right'])->onRenderCell[] = [$grid, 'decoratorNumber'];

		$grid->addColumnLinkDetail('detailDelivery');
		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected();

		return $grid;
	}

	public function createComponentBannedEmailGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->bannedEmailRepository->many(), 20, 'createdTs', 'DESC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Vytvořen', "createdTs|date:'d.m.Y G:i'", '%s', 'createdTs', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('E-mail', 'email', '%s', 'email');

		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('email', ['email'], null, 'E-mail');
		$grid->addFilterButtons(['default']);

		return $grid;
	}

	public function createComponentChangeForm(): Form
	{
		$order = $this->getParameter('order') ?: $this->getParameter('delivery')->order;

		$form = $this->formFactory->create();
		$form->addRadioList('Sklad', 'store', ['asda' => 'asdasd']);

		return $form;
	}

	public function createComponentDeliveryForm(): Form
	{
		$order = $this->getParameter('order') ?: $this->getParameter('delivery')->order;

		$form = $this->formFactory->create();
		$form->addSelect('type', 'Doprava', $this->deliveryTypeRepository->getArrayForSelect())->setRequired();
		$form->addDataSelect('supplier', 'Dropshipping', $this->supplierRepository->getArrayForSelect());
		$form->addText('externalId', 'Externí Id')->setNullable(true);
		$form->addDate('shippingDate', 'Den doručení')->setNullable(true);
		$form->addGroup('Cena');
		$form->addSelect('currency', 'Měna', $this->currencyRepository->getArrayForSelect())->setRequired();
		$form->addText('price', 'Cena bez DPH')->addRule($form::FLOAT)->setDefaultValue(0)->setRequired();
		$form->addText('priceVat', 'Cena s DPH')->addRule($form::FLOAT)->setDefaultValue(0)->setRequired();
		$form->addGroup('Stav');
		$form->addDatetime('shippedTs', 'Expedováno')->setNullable(true);

		$form->addHidden('order', (string)$order);

		$form->addSubmits(!$this->getParameter('delivery'));

		$form->onSuccess[] = function (AdminForm $form) use ($order): void {
			$values = $form->getValues('array');

			$type = $this->deliveryTypeRepository->one($values['type'])->toArray();
			$values['typeCode'] = $type['code'];
			$values['typeName'] = $type['name'];

			$delivery = $this->deliveryRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detailDelivery', 'delivery', [$delivery], [$order]);
		};

		return $form;
	}

	public function createComponentEmailForm(): Form
	{
		$order = $this->getParameter('order');

		$form = $this->formFactory->create();

		$templates = ['order.created', 'order.canceled', 'order.changed', 'order.created', 'order.payed', 'order.shipped'];

		$form->addSelect('template', 'Šablona', $this->templateRepository->many()->where('uuid', $templates)->toArrayOf('name'))->setRequired();
		$form->addText('email', 'E-mail')->setRequired();
		$form->addText('ccEmails', 'Kopie e-mailů')->setNullable();

		$form->addSubmit('submit', 'Odeslat');

		$form->onSuccess[] = function (AdminForm $form) use ($order): void {
			$values = $form->getValues('array');

			$mail = $this->templateRepository->createMessage($values['template'], $this->orderRepository->getEmailVariables($order), $values['email'], $values['ccEmails']);
			$this->mailer->send($mail);

			$this->flashMessage('Odesláno', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function createComponentPaymentForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addSelect('type', 'Platba', $this->paymentTypeRepository->getArrayForSelect())->setRequired();
		$form->addSelect('currency', 'Měna', $this->currencyRepository->getArrayForSelect())->setRequired();
		$form->addText('price', 'Cena bez DPH')->addRule($form::FLOAT)->setDefaultValue(0)->setRequired();
		$form->addText('priceVat', 'Cena s DPH')->addRule($form::FLOAT)->setDefaultValue(0)->setRequired();
		$form->addGroup('Údaje o zaplacení');
		$form->addDatetime('paidTs', 'Datum a čas')->setNullable(true);
		$form->addText('paidPrice', 'Částka bez DPH')->addRule($form::FLOAT)->setDefaultValue(0)->setRequired();
		$form->addText('paidPriceVat', 'Částka s DPH')->addRule($form::FLOAT)->setDefaultValue(0)->setRequired();
		$form->addHidden('order', (string)$this->getParameter('order'));

		$form->addSubmits(!$this->getParameter('order'));

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$type = $this->paymentTypeRepository->one($values['type'])->toArray();
			$values['typeCode'] = $type['code'];
			$values['typeName'] = $type['name'];

			$this->paymentRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');

			$form->processRedirect('payment', 'default', [$this->getParameter('order')], []);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$tabs = [
			'received' => 'Přijaté',
			'finished' => 'Odeslané',
			'canceled' => 'Stornované',
			'bannedEmails' => 'Blokované e-maily',
		];

		if ($this->shopper->getEditOrderAfterCreation()) {
			$tabs = \array_merge(['open' => 'Otevřené'], $tabs);
		}

		$this->template->tabs = $tabs;

		if ($this->tab === 'bannedEmails') {
			$this->template->headerLabel = "Blokované e-maily";
			$this->template->headerTree = [
				['Blokované e-maily', 'default'],
			];

			$this->template->displayControls = [$this->getComponent('bannedEmailGrid')];

			return;
		}

		$this->template->headerLabel = "Objednávky";
		$this->template->headerTree = [
			['Objednávky', 'default'],
		];

		$this->template->displayControls = [$this->getComponent('ordersGrid')];
		$this->template->ordersForJBOX = $this->getComponent('ordersGrid')->getItemsOnPage();
	}

	public function renderDetail(Order $order): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function renderDetailDelivery(Delivery $delivery): void
	{
		$this->template->headerLabel = 'Položky dopravy';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Položky dopravy'],
		];
		$this->template->displayButtons = [$this->createBackButton('delivery', [$delivery->order])];
		$this->template->displayControls = [$this->getComponent('deliveryForm')];
	}

	public function actionDetailDelivery(Delivery $delivery): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('deliveryForm');

		$form->setDefaults($delivery->toArray());
	}

	public function renderDelivery(Order $order): void
	{
		$this->template->headerLabel = 'Doprava: ' . $order->code;
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Doprava'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('deliveryGrid')];
	}

	public function renderPayment(Order $order): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('paymentForm')];
	}

	public function actionDetail(Order $order): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');

		$form->setDefaults($order->purchase->toArray(['billAddress', 'deliveryAddress']));

		$form->setDefaults($order->toArray());
	}

	public function createComponentNewOrderItemForm(): AdminForm
	{
		/** @var \Eshop\DB\Cart $order */
		$order = $this->getParameter('order');

		$form = $this->formFactory->create();

		$form->addSelect2('product', 'Produkt', [], [
			'ajax' => [
				'url' => $this->link('getProductsForSelect2!'),
			],
			'placeholder' => 'Zvolte produkt',
		]);

		$form->addSelect('cart', 'Košík č.', $order->purchase->carts->toArrayOf('id'))->setRequired();
		$form->addSelect('delivery', 'Doprava', $order->deliveries->where('shippedTs IS NULL')->toArrayOf('typeName'))->setRequired();

		$form->addInteger('amount', 'Množství')->setDefaultValue(1)->setRequired();

		$form->addSubmits(false);

		$form->onValidate[] = function (AdminForm $form): void {
			$data = $this->getHttpRequest()->getPost();

			if (!isset($data['product'])) {
				$form['product']->addError('Toto pole je povinné!');

				return;
			}

			if ($this->productRepo->getProduct($data['product'])) {
				return;
			}

			$form['product']->addError('Daný produkt nebyl nalezen nebo není dostupný');
		};

		$form->onSuccess[] = function (AdminForm $form) use ($order): void {
			$values = $form->getValues('array');

			/** @var \Eshop\DB\Cart $cart */
			$cart = $this->cartRepository->one($values['cart']);

			if ($order->purchase->customer) {
				$this->shopper->setCustomer($order->purchase->customer);
				$this->checkoutManager->setCustomer($order->purchase->customer);
			}

			/** @var \Eshop\DB\Product $product */
			$product = $this->productRepo->getProduct($form->getHttpData(Form::DATA_TEXT, 'product'));

			$cartItem = $this->checkoutManager->addItemToCart($product, null, $values['amount'], false, false, false, $cart);

			$amount = (int)$this->packageItemRepository->many()->where('fk_delivery', $values['delivery'])->where('fk_cartItem', $cartItem)->firstValue('amount');

			$this->packageItemRepository->syncOne([
				'amount' => $values['amount'] + $amount,
				'delivery' => $values['delivery'],
				'cartItem' => $cartItem,
			]);

			$this->flashMessage('Provedeno', 'success');
			$form->processRedirect('newOrderItem', 'orderItems', [$order], [$order]);
		};

		return $form;
	}

	public function createComponentSplitOrderItemForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->getCurrentGroup()->setOption('label', 'Nová položka');
		$form->addInteger('amount', 'Množství')->setDefaultValue(1)->setRequired();
		$form->addSelect('store', 'Sklad', $this->storeRepository->many()->toArrayOf('name'))->setRequired();
		$form->addSubmits(false, false);
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			/** @var \Eshop\DB\PackageItem $packageItem */
			$packageItem = $this->packageItemRepository->one($values['uuid'], true);

			$values['amount'] = $values['amount'] > $packageItem->amount ? $packageItem->amount : $values['amount'];

			if ($packageItem->getValue('store') === $values['store'] || $values['amount'] === 0) {
				$this->flashMessage('Položka neví ve zvoleném skladě k dispozici!', 'error');
				$this->redirect('this');
			}

			if ($values['amount'] === $packageItem->amount) {
				$this->packageItemRepository->many()->where('this.uuid', $values['uuid'])->delete();
			} else {
				$this->packageItemRepository->many()->where('this.uuid', $values['uuid'])->update(['amount' => new Literal("amount - $values[amount]")]);
			}

			$affected = $this->packageItemRepository->many()
				->match([
					'fk_store' => $values['store'],
					'fk_package' => $packageItem->getValue('package'),
					'fk_cartItem' => $packageItem->getValue('cartItem'),
				])
				->update(['amount' => new Literal("amount + $values[amount]")]);

			if (!$affected) {
				$this->packageItemRepository->createOne([
					'amount' => $values['amount'],
					'store' => $values['store'],
					'package' => $packageItem->getValue('package'),
					'cartItem' => $packageItem->getValue('cartItem'),
				]);
			}

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function createComponentStoreOrderItemForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addRadioList('store', null, $this->storeRepository->many()->toArrayOf('name'));

		$form->addSubmits(false, false);
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			/** @var \Eshop\DB\CartItem $item */
			$item = $this->packageItemRepository->one($values['uuid'], true)->update(['store' => $values['store']]);

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function createComponentMergeOrderForm(): AdminForm
	{
		$orderRepository = $this->orderRepository;
		$order = null;

		$form = $this->formFactory->create();

		$form->addText('code', 'Kód objednávky')->addRule(function (TextInput $value) use ($orderRepository) {
			return !$orderRepository->many()->where('code', $value->value)->isEmpty();
		}, 'Tato objednávka neexistuje')->setRequired();
		$form->addSubmits(false, false);
		$form->onSuccess[] = function (AdminForm $form) use ($order): void {
			$values = $form->getValues('array');

			/** @var \Eshop\DB\Order $order */
			$order = $this->orderRepository->one(['code' => $values['code']], true);

			// cancel the order
			/** @var \Eshop\DB\Order $orderOld */
			$orderOld = $this->orderRepository->one($orderId, true);

			/** @var \Eshop\DB\Order $orderNew */
			$orderNew = $this->orderRepository->one($orderId, true);

			if ($orderOld->purchase->customer) {
				$orderOld->purchase->customer->setAccount($orderOld->purchase->account);
				$this->shopper->setCustomer($orderOld->purchase->customer);
			} else {
				$this->shopper->setCustomer(null);
				$this->shopper->setCustomerGroup($this->customerGroupRepository->getUnregisteredGroup());
			}

			// add to cart and update order

			$newCart = $orderOld->purchase->carts->first();

			foreach ($orderOld->purchase->carts->first() as $item) {
				if ($item->getValue('product') === null) {
					// throw exception
				}

				$product = $this->productRepo->getProduct($item->getValue('product'));
				$cartItem = $this->checkoutManager->addItemToCart($product, null, $values['amount'], false, false, false, $newCart);
			}

			$orderOld->update(['canceledTs' => new DateTime()]);

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function createComponentDetailOrderItemForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addHidden('packageItem');
		$form->getCurrentGroup()->setOption('label', 'Nákup');
		$form->addInteger('amount', 'Celkové množství')->setRequired();
		$form->addTextArea('note', 'Poznámka')->setNullable();
		$form->addGroup('Cena za kus');
		$form->addText('price', 'Cena bez DPH')->addRule(Form::FLOAT)->setRequired();
		$form->addText('priceVat', 'Cena s DPH')->addRule(Form::FLOAT)->setRequired();
		$form->addInteger('vatPct', 'DPH')->setRequired();
		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$cartItem = $this->cartItemRepo->one($values['uuid'], true);

			$packageItem = $this->packageItemRepository->one($values['packageItem']);
			$packageItem->update(['amount' => $packageItem->amount + $values['amount'] - $cartItem->amount]);

			$cartItem->update($values);

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function actionOrderEmail(Order $order): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('emailForm');
		$form->setDefaults($order->purchase->toArray());
	}

	public function renderOrderEmail(Order $order): void
	{
		$this->template->headerLabel = 'Poslání e-mailu: ' . $order->code;
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Položky'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('emailForm')];
	}

	public function renderNewOrderItem(Order $order): void
	{
		$this->template->headerLabel = 'Nová položka objednávky';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Položky'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('orderItems', $order)];
		$this->template->displayControls = [$this->getComponent('newOrderItemForm')];
	}

	public function actionDetailOrderItem(CartItem $cartItem, Order $order): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('detailOrderItemForm');
		$form->setDefaults($cartItem->toArray());
	}

	public function actionPayment(Order $order): void
	{
		$payment = $order->getPayment();

		/** @var \Forms\Form $form */
		$form = $this->getComponent('paymentForm');
		$form->setDefaults($payment ? $payment->toArray() : []);
	}

	public function renderDetailOrderItem(CartItem $cartItem, Order $order): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Položky', 'orderItems', $order],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('orderItems', $order)];
		$this->template->displayControls = [$this->getComponent('detailOrderItemForm')];
	}

	public function handleChangePayment(string $payment, bool $paid, bool $email = false): void
	{
		/** @var \Eshop\DB\Payment $payment */
		$payment = $this->paymentRepository->one($payment, true);

		$values = [
			'paidTs' => $paid ? (string)new DateTime() : null,
			'paidPrice' => $paid ? $payment->order->getTotalPrice() : 0,
			'paidPriceVat' => $paid ? $payment->order->getTotalPriceVat() : 0,
		];
		$payment->update($values);

		if ($paid && $email) {
			$mail = $this->templateRepository->createMessage('order.payed', ['orderCode' => $payment->order->code], $payment->order->purchase->email);
			$this->mailer->send($mail);
		}

		$this->flashMessage($paid ? 'Zaplaceno' : 'Zaplacení zrušeno', 'success');

		$this->redirect('this');
	}

	public function handleChangeDelivery(string $delivery, bool $shipped, bool $email = false): void
	{
		/** @var \Eshop\DB\Delivery $delivery */
		$delivery = $this->deliveryRepository->one($delivery, true);

		$values = [
			'shippedTs' => $shipped ? (string)new DateTime() : null,
		];
		$delivery->update($values);

		if ($email && $shipped) {
			$mail = $this->templateRepository->createMessage('order.shipped', ['orderCode' => $delivery->order->code], $delivery->order->purchase->email);
			$this->mailer->send($mail);
		}

		$this->flashMessage($shipped ? 'Expedováno' : 'Expedice zrušena', 'success');

		$this->redirect('this');
	}

	public function modifyPackage(Button $button): void
	{
		$grid = $button->lookup(Datagrid::class);
		$delivery = $this->getParameter('delivery');

		foreach ($grid->getInputData() as $id => $data) {
			if (!$data) {
				$this->packageItemRepository->many()->where('fk_cartItem', $id)->where('fk_delivery', $delivery)->delete();

				continue;
			}

			$values = [
				'amount' => (int)$data['packageAmount'],
				'cartItem' => $id,
				'delivery' => $delivery,
			];
			$this->packageItemRepository->syncOne($values);
		}

		$this->flashMessage('Balík upraven', 'success');

		$this->redirect('this');
	}

	public function renderDeliveryColumn(CartItem $item, Datagrid $grid)
	{
		/** @var \Eshop\DB\Delivery[] $deliveries */
		$deliveries = $item->getDeliveries()->toArray();
		$types = [];

		if (!$deliveries) {
			return '-';
		}

		foreach ($deliveries as $delivery) {
			$date = $delivery->shippingDate ? '<i style=\'color: gray;\' class=\'fa fa-shipping-fast\'></i> ' . $grid->template->getLatte()->invokeFilter('date', [$delivery->shippingDate]) : '';
			$dropshipping = $delivery->supplier ? ' (' . $delivery->supplier->name . ')' : '';
			$types[] = $delivery->getTypeName() . "$dropshipping <small>$date</small>";
		}

		return \implode(', ', $types);
	}

	public function actionComments(Order $order): void
	{
		$this->template->headerLabel = 'Komentáře - ' . $order->code;
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Komentáře'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
	}

	public function actionExportPPC(array $ids): void
	{
		$this->template->headerLabel = 'Export pro PPC';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Export pro PPC'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('exportPPCForm')];
	}

	public function createComponentExportPPCForm()
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $this->getComponent('ordersGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $grid->getPaginator()->getItemCount();
		$selectedNo = \count($ids);
		$mutationSuffix = $this->productRepository->getConnection()->getMutationSuffix();

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Exportovat', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'	' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		]);
		$form->addCheckbox('header', 'Hlavička')->setDefaultValue(true)->setHtmlAttribute('data-info', 'Pokud tuto možnost nepoužijete tak nebude možné tento soubor použít pro import!');

		$headerColumns = $form->addDataMultiSelect('columns', 'Sloupce');

		$items = [];
		$defaultItems = [];

		if (isset(self::CONFIGURATION['exportPPC_columns'])) {
			$items += self::CONFIGURATION['exportPPC_columns'];

			if (isset(self::CONFIGURATION['defaultExportPPC_columns'])) {
				$defaultItems = \array_merge($defaultItems, self::CONFIGURATION['defaultExportPPC_columns']);
			}
		}

		$headerColumns->setItems($items);
		$headerColumns->setDefaultValue($defaultItems);

		$form->addSubmit('submit', 'Exportovat');

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $grid, $items): void {
			$values = $form->getValues('array');

			$selectedItems = $values['bulkType'] === 'selected' ? $this->orderRepository->many()->where('this.uuid', $ids) : $grid->getFilteredSource();

			$tempFilename = \tempnam($this->tempDir, "csv");
			$headerColumns = \array_filter($items, function ($item) use ($values) {
				return \in_array($item, $values['columns']);
			}, \ARRAY_FILTER_USE_KEY);

			$this->orderRepository->csvPPCExport(
				$selectedItems,
				Writer::createFromPath($tempFilename),
				$headerColumns,
				$values['delimiter'],
				$values['header'] ? \array_values($headerColumns) : null,
			);

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, "orders.csv", 'text/csv'));
		};

		return $form;
	}

	public function createComponentOrderForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addGroup('Kontakty');
		$form->addText('phone', 'Telefon')->setNullable(true);
		$form->addText('email', 'E-mail')->setNullable(true);
		$form->addGroup('Fakturační adresa');
		$billAddress = $form->addContainer('billAddress');
		$billAddress->addHidden('uuid')->setNullable();
		$billAddress->addText('street', 'Ulice');
		$billAddress->addText('city', 'Město');
		$billAddress->addText('zipcode', 'PSČ');

		$form->addGroup('Doručovací adresa');
		$deliveryAddress = $form->addContainer('deliveryAddress');
		$deliveryAddress->addHidden('uuid')->setNullable();
		$deliveryAddress->addText('street', 'Ulice');
		$deliveryAddress->addText('city', 'Město');
		$deliveryAddress->addText('zipcode', 'PSČ');
		$form->addGroup('Ostatní');
		$form->addDate('desiredShippingDate', 'Požadované doručení')->setNullable(true);
		$form->addText('internalOrderCode', 'Interní číslo')->setNullable(true);
		$form->addTextArea('note', 'Poznámka')->setNullable(true);

		$form->addSubmits(!$this->getParameter('order'));

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			unset($values['uuid']);

			foreach (['deliveryAddress', 'billAddress'] as $address) {
				if ($values[$address]['uuid'] === null) {
					unset($values[$address]);
				}
			}

			/** @var \Eshop\DB\Order $order */
			$order = $this->getParameter('order');

			$order->purchase->update($values, true);

			$this->flashMessage('Uloženo', 'success');

			$form->processRedirect('printDetail', 'default', [$order]);
		};

		return $form;
	}

	public function renderComments(Order $order): void
	{
		$this->template->comments = $this->commentRepository->many()->where('fk_order', $order->getPK())->orderBy(['createdTs' => 'DESC'])->toArray();
		$this->template->setFile(__DIR__ . '/templates/comments.latte');
	}

	public function createComponentNewComment()
	{

		$form = $this->formFactory->create(true, false, false, false, false);

		$form->addGroup('Nový komentář');
		$form->addTextArea('text', 'Komentáře');

		$form->addSubmit('send', 'Odeslat');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues('array');

			$this->commentRepository->createOne([
				'order' => $this->getParameter('order')->getPK(),
				'text' => $values['text'],
				'administrator' => $this->admin->getIdentity()->getPK(),
				'adminFullname' => $this->admin->getIdentity()->fullName,
			]);

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('comments', $this->getParameter('order'));
		};

		return $form;
	}

	public function actionPrintDetail(Order $order): void
	{
		$array = $order->purchase->toArray(['billAddress', 'deliveryAddress']) + $order->toArray();

		$this->getComponent('orderForm')->setDefaults($array);
	}

	public function renderPrintDetail(Order $order): void
	{
		$this->template->headerLabel = 'Objednávka - ' . $order->code;

		$this->template->order = $order;
		$this->template->orderItems = $order->purchase->getItems()->toArray();
		$this->template->packages = $order->packages;

		$this->template->stores = $this->storeRepository->many();
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Detail'],
		];
		$this->template->setFile(__DIR__ . '/templates/Order.printDetail.latte');

		$this->template->displayButtons = [$this->createBackButton('default')];

		$state = $this->orderRepository->getState($order);

		$this->template->displayButtonsRight = [];

		if ($state === 'open' && $this->shopper->getEditOrderAfterCreation()) {
			$this->template->displayButtonsRight[] = $this->createButtonWithClass('receiveOrder!', '<i class="fas fa-check mr-1"></i>Přijmout', 'btn btn-sm btn-success', $order->getPK());
			$this->template->displayButtonsRight[] = $this->createButtonWithClass(
				'receiveAndCompleteOrder!',
				'<i class="fas fa-check mr-1"></i>Přijmout a zpracovat',
				'btn btn-sm btn-success',
				$order->getPK(),
			);
			$this->template->displayButtonsRight[] = $this->createButtonWithClass('cancelOrder!', '<i class="fas fa-times mr-1"></i>Storno', 'btn btn-sm btn-danger', $order->getPK());
			$this->template->displayButtonsRight[] = $this->createButtonWithClass(
				'banOrder!',
				'<i class="fas fa-exclamation mr-1"></i>Problémová objednávka',
				'btn btn-sm btn-warning',
				$order->getPK(),
			);
		} elseif ($state === 'received') {
			$this->template->displayButtonsRight[] = $this->createButtonWithClass('completeOrder!', '<i class="fas fa-check mr-1"></i>Zpracovat', 'btn btn-sm btn-success', $order->getPK());
			$this->template->displayButtonsRight[] = $this->createButtonWithClass('cancelOrder!', '<i class="fas fa-times mr-1"></i>Storno', 'btn btn-sm btn-danger', $order->getPK());
			$this->template->displayButtonsRight[] = $this->createButtonWithClass(
				'banOrder!',
				'<i class="fas fa-exclamation mr-1"></i>Problémová objednávka',
				'btn btn-sm btn-warning',
				$order->getPK(),
			);
		} elseif ($state === 'finished') {
			$this->template->displayButtonsRight[] = $this->createButtonWithClass('cancelOrder!', '<i class="fas fa-times mr-1"></i>Storno', 'btn btn-sm btn-danger', $order->getPK());
			$this->template->displayButtonsRight[] = $this->createButtonWithClass(
				'banOrder!',
				'<i class="fas fa-exclamation mr-1"></i>Problémová objednávka',
				'btn btn-sm btn-warning',
				$order->getPK(),
			);
		} elseif ($state === 'canceled') {
			$this->template->displayButtonsRight[] = $this->createButtonWithClass('completeOrder!', '<i class="fas fa-check mr-1"></i>Zpracovat', 'btn btn-sm btn-success', $order->getPK());
		}

		$this->template->displayButtons[] = '<a href="#" data-toggle="modal" data-target="#modal-orderForm"><button class="btn btn-sm btn-primary"><i class="fas fa-edit mr-1"></i> Editovat</button></a>';
		$this->template->displayButtons[] = '<a href="#" data-toggle="modal" data-target="#modal-mergeOrderForm"><button class="btn btn-sm btn-primary"><i class="fas fa-compress mr-1"></i> Spojit</button></a>';
		$this->template->displayButtons[] = '<a href="#" onclick="window.print();"><button class="btn btn-sm btn-primary"><i class="fas fa-print mr-1"></i> Tisk</button></a>';
		$this->template->displayButtons[] = $this->createButton('cloneOrder!', '<i class="far fa-clone mr-1"></i>Objednat znovu', [$order->getPK()]);
		$this->template->displayButtons[] = '<a href="#" data-toggle="modal" data-target="#modal-emailForm"><button class="btn btn-sm btn-primary"><i class="fas fa-envelope mr-1"></i> Poslat e-mail</button></a>';

		$this->template->displayButtons[] = $this->createButton('exportEdi!', '<i class="fa fa-download mr-1"></i>EDI', [$order->getPK()]);
		$this->template->displayButtons[] = $this->createButton('exportCsv!', '<i class="fa fa-download mr-1"></i>CSV', [$order->getPK()]);

		//  window.print()
	}

	public function handleToggleDeleteOrderItem(string $itemId): void
	{
		$this->packageItemRepository->many()->where('this.fk_cartItem', $itemId)->update(['this.deleted' => new Literal('NOT deleted')]);

		$this->redirect('this');
	}

	public function handleCloneOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId, true);

		$this->checkoutManager->createCart();

		if ($order->purchase->customer) {
			$order->purchase->customer->setAccount($order->purchase->account);
			$this->shopper->setCustomer($order->purchase->customer);
		} else {
			$this->shopper->setCustomer(null);
			$this->shopper->setCustomerGroup($this->customerGroupRepository->getUnregisteredGroup());
		}

		$this->checkoutManager->addItemsFromCart($order->purchase->carts->first());

		$purchase = $this->checkoutManager->syncPurchase($order->purchase->toArray());
		$this->checkoutManager->createOrder($purchase);

		$this->redirect('this');
	}

	public function handleCancelOrder(string $orderId): void
	{
		$this->orderRepository->cancelOrderById($orderId);

		$this->redirect('this');
	}

	public function handleBanOrder(string $orderId): void
	{
		$this->orderRepository->banOrderById($orderId);

		$this->redirect('this');
	}

	public function handleCompleteOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId, true);

		if ($order->canceledTs === null) {
			foreach ($order->purchase->getItems() as $item) {
				if (!$item->product) {
					continue;
				}

				$item->product->update(['buyCount' => $item->product->buyCount + $item->amount]);
			}
		}

		$order->update(['completedTs' => (string)new DateTime(), 'canceledTs' => null]);

		$this->redirect('this');
	}

	public function handleReceiveOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId, true);
		$order->update(['receivedTs' => (string)new DateTime(), 'canceledTs' => null]);

		$this->redirect('this');
	}

	public function handleReceiveAndCompleteOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId, true);
		$order->update([
			'receivedTs' => (string)new DateTime(),
			'completedTs' => (string)new DateTime(),
			'canceledTs' => null,
		]);

		foreach ($order->purchase->getItems() as $item) {
			if (!$item->product) {
				continue;
			}

			$item->product->update(['buyCount' => $item->product->buyCount + $item->amount]);
		}

		$this->redirect('this');
	}

	public function handleExportCsv(string $orderId): void
	{
		$presenter = $this;
		$object = $this->orderRepository->one($orderId, true);

		$tempFilename = \tempnam($presenter->tempDir, "csv");
		$this->application->onShutdown[] = function () use ($tempFilename): void {
			\unlink($tempFilename);
		};
		$this->orderRepository->csvExport($object, Writer::createFromPath($tempFilename, 'w+'));
		$response = new FileResponse($tempFilename, "objednavka-$object->code.csv", 'text/csv');
		$presenter->sendResponse($response);
	}

	public function handleExportEdi(string $orderId): void
	{
		$presenter = $this;
		$object = $this->orderRepository->one($orderId, true);

		$tempFilename = \tempnam($this->tempDir, "xml");
		$fh = \fopen($tempFilename, 'w+');
		\fwrite($fh, $this->orderRepository->ediExport($object));
		\fclose($fh);
		$this->application->onShutdown[] = function () use ($tempFilename): void {
			\unlink($tempFilename);
		};
		$this->sendResponse(new FileResponse($tempFilename, 'order.txt', 'text/plain'));
	}
}
