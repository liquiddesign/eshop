<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Eshop\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\Admin\Controls\OrderGridFactory;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\PackageItemRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\AddressRepository;
use Eshop\DB\AutoshipRepository;
use Eshop\DB\Cart;
use Eshop\DB\CartItem;
use Eshop\DB\CartItemRepository;
use Eshop\DB\CartRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Delivery;
use Eshop\DB\DeliveryRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentRepository;
use Forms\Form;
use Grid\Datagrid;
use Messages\DB\TemplateRepository;
use Nette\Forms\Controls\Button;
use Nette\Forms\Controls\TextInput;
use Nette\Http\Request;
use Nette\Mail\Mailer;
use Nette\Utils\DateTime;

class OrderPresenter extends BackendPresenter
{
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
	public \Eshop\CheckoutManager $checkoutManager;

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
	public Mailer $mailer;

	/** @persistent */
	public string $tab = 'received';

	public function createComponentOrdersGrid()
	{
		return $this->orderGridFactory->create($this->tab);
	}

	public function createComponentDeliveryGrid()
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

		$grid->addColumnLink('deliveryItems', 'Balík', null, ['class' => 'minimal']);

		$grid->addColumnLinkDetail('detailDelivery');
		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected();

		return $grid;
	}

	public function createComponentPackageGrid()
	{
		$collection = $this->cartItemRepo->many()
			->select(['amount' => 'SUM(this.amount)', 'packageAmount' => 'IFNULL(package.amount, 0)'])
			->join(['package' => 'eshop_packageItem'], 'package.fk_cartItem =this.uuid AND package.fk_delivery = :delivery', ['delivery' => $this->getParameter('delivery')])
			->join(['cart' => 'eshop_cart'], 'cart.uuid = this.fk_cart')
			->where('cart.fk_purchase', $this->getParameter('delivery')->order->getValue('purchase'))
			->setGroupBy(['this.productCode']);


		$grid = $this->gridFactory->create($collection, 20, 'createdTs', 'DESC');
		$grid->addColumnSelector();
		$grid->addColumnImage('product.imageFileName', Product::IMAGE_DIR);
		$grid->addColumnText('Kód', 'getFullCode', '%s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Název', 'productName', '%s');

		$grid->addColumnInput('Množství', 'packageAmount', function ($id) use ($grid) {
			$textbox = new TextInput();

			if ($item = $grid->getItemsOnPage()[$id] ?? null) {
				$textbox->addRule(Form::MAX, null, $grid->getItemsOnPage()[$id]->amount);
			}
			$textbox->addRule(Form::MIN, null, 0);
			$textbox->setHtmlAttribute('class', 'form-control form-control-sm');
			$textbox->addRule(Form::INTEGER);
			$textbox->setRequired();

			return $textbox;
		}, '', '0', null, ['class' => 'minimal']);

		$grid->addColumnText('Objednáno', 'amount', '%s ks', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];

		$submit = $grid->getForm()->addSubmit('completeMultiple', 'Uložit');
		$submit->setHtmlAttribute('class', 'btn btn-sm btn-primary');
		$submit->onClick[] = [$this, 'modifyPackage'];

		return $grid;
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

		$form->onSuccess[] = function (AdminForm $form) use ($order) {
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

		$form->onSuccess[] = function (AdminForm $form) use ($order) {
			$values = $form->getValues('array');

			$mail = $this->templateRepository->createMessage($values['template'], $this->orderRepository->getEmailVariables($order), $values['email'], $values['ccEmails']);
			$this->mailer->send($mail);

			$this->flashMessage('Odesláno', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function createComponentForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addText('code', 'Kód')->setRequired();
		$form->addGroup('Stav');
		$form->addDatetime('completedTs', 'Zpracované')->setNullable(true);
		$form->addDatetime('canceledTs', 'Zrušeno')->setNullable(true);
		$form->addGroup('Kontakty');
		$form->addText('phone', 'Telefon')->setNullable(true);
		$form->addText('email', 'E-mail')->setNullable(true);
		$form->addText('ccEmails', 'E-mail (Kopie)')->setNullable(true);
		$form->addGroup('Fakturační adresa');
		$billAddress = $form->addContainer('billAddress');
		$billAddress->addHidden('uuid')->setNullable();
		$billAddress->addText('street', 'Ulice');
		$billAddress->addText('city', 'Město');
		$billAddress->addText('zipcode', 'PSČ');
		$billAddress->addText('state', 'Stát');

		$form->addGroup('Doručovací adresa');
		$deliveryAddress = $form->addContainer('deliveryAddress');
		$deliveryAddress->addHidden('uuid')->setNullable();
		$deliveryAddress->addText('street', 'Ulice');
		$deliveryAddress->addText('city', 'Město');
		$deliveryAddress->addText('zipcode', 'PSČ');
		$deliveryAddress->addText('state', 'Stát');
		$form->addGroup('Ostatní');
		$form->addDate('desiredShippingDate', 'Požadované datum doručení')->setNullable(true);
		$form->addText('internalOrderCode', 'Interní číslo objednávky')->setNullable(true);
		$form->addTextArea('note', 'Poznámka')->setNullable(true);


		$form->addSubmits(!$this->getParameter('order'));

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			unset($values['uuid']);

			/** @var Order $order */
			$order = $this->getParameter('order');
			$order->update($values, true);

			$values['billAddress'] = (string)$this->addressRepository->syncOne($values['billAddress']);

			if ($values['deliveryAddress']['street'] && $values['deliveryAddress']['city']) {
				$values['deliveryAddress'] = (string)$this->addressRepository->syncOne($values['deliveryAddress']);
			} elseif ($values['deliveryAddress']['uuid']) {
				$values['deliveryAddress'] = null;
			} else {
				unset($values['deliveryAddress']);
			}

			$order->purchase->update($values, true);

			$this->flashMessage('Uloženo', 'success');

			$form->processRedirect('detail', 'default', [$order]);
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

		$form->onSuccess[] = function (AdminForm $form) {
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

	public function renderDefault()
	{
		$tabs = [
			'received' => 'Aktuální',
			'finished' => 'Zpracované',
			'canceled' => 'Stornované',
		];

		if ($this->shopper->getEditOrderAfterCreation()) {
			$tabs = \array_merge(['open' => 'Otevřené'], $tabs);
		}

		$this->template->tabs = $tabs;

		$this->template->headerLabel = "Objednávky";
		$this->template->headerTree = [
			['Objednávky', 'default'],
		];

		$this->template->displayControls = [$this->getComponent('ordersGrid')];
	}

	public function renderDetail(Order $order)
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function renderDeliveryItems(Delivery $delivery)
	{
		$this->template->headerLabel = 'Balík: ' . $delivery->order->code;
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Doprava'],
		];
		$this->template->displayButtons = [$this->createBackButton('delivery', [$delivery->order])];
		$this->template->displayControls = [$this->getComponent('packageGrid')];
	}

	public function renderNewDelivery(Order $order)
	{
		$this->template->headerLabel = 'Položky dopravy';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Položky dopravy'],
		];
		$this->template->displayButtons = [$this->createBackButton('delivery', [$order])];
		$this->template->displayControls = [$this->getComponent('deliveryForm')];
	}

	public function renderDetailDelivery(Delivery $delivery)
	{
		$this->template->headerLabel = 'Položky dopravy';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Položky dopravy'],
		];
		$this->template->displayButtons = [$this->createBackButton('delivery', [$delivery->order])];
		$this->template->displayControls = [$this->getComponent('deliveryForm')];
	}

	public function actionDetailDelivery(Delivery $delivery)
	{
		/** @var Form $form */
		$form = $this->getComponent('deliveryForm');

		$form->setDefaults($delivery->toArray());
	}

	public function renderDelivery(Order $order)
	{
		$this->template->headerLabel = 'Doprava: ' . $order->code;
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Doprava'],
		];
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('NewDelivery', [$order])];
		$this->template->displayControls = [$this->getComponent('deliveryGrid')];
	}

	public function renderPayment(Order $order)
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('paymentForm')];
	}


	public function actionDetail(Order $order)
	{
		/** @var Form $form */
		$form = $this->getComponent('form');

		$form->setDefaults($order->purchase->toArray(['billAddress', 'deliveryAddress']));

		$form->setDefaults($order->toArray());
	}

	public function createComponentOrderItemsGrid()
	{
		$grid = $this->gridFactory->create($this->getParameter('order')->purchase->getItems(), 20, 'code', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Košik', 'cart.id', '#%s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumnImage('product.imageFileName', Product::IMAGE_DIR);
		$grid->addColumnText('Kód', 'getFullCode', '%s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Název', 'productName', '%s');
		$grid->addColumn('Doprava / Dropshipping', [$this, 'renderDeliveryColumn']);
		$grid->addColumnText('Množství', 'amount', '%s ks', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumnText('Cena bez DPH', 'getPriceSum|price:cart.currency.code', '%s', null, ['class' => 'fit text-right'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumnText('Cena s DPH', 'getPriceVatSum|price:cart.currency.code', '%s', null, ['class' => 'fit text-right'])->onRenderCell[] = [$grid, 'decoratorNumber'];

		$grid->addColumnLinkDetail('detailOrderItem', ['order' => $this->getParameter('order')]);
		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected();

		$searchExpressions = ['productCode', 'productName_cs',];
		$grid->addFilterTextInput('q', $searchExpressions, null, 'Kód, název');
		$grid->addFilterButtons(['default']);

		return $grid;
	}

	public function renderOrderItems(Order $order)
	{
		$this->template->headerLabel = 'Položky objednávky';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Položky'],
		];
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('newOrderItem', [$order])];
		$this->template->displayControls = [$this->getComponent('orderItemsGrid')];
	}

	public function createComponentNewOrderItemForm(): AdminForm
	{
		/** @var \Eshop\DB\Cart $order */
		$order = $this->getParameter('order');

		$form = $this->formFactory->create();

		$form->addSelect2('product', 'Produkt', [], [
			'ajax' => [
				'url' => $this->link('getProductsForSelect2!')
			],
			'placeholder' => 'Zvolte produkt'
		]);

		$form->addSelect('cart', 'Košík č.', $order->purchase->carts->toArrayOf('id'))->setRequired();
		$form->addSelect('delivery', 'Doprava', $order->deliveries->where('shippedTs IS NULL')->toArrayOf('typeName'))->setRequired();

		$form->addInteger('amount', 'Množství')->setDefaultValue(1)->setRequired();

		$form->addSubmits(false);

		$form->onValidate[] = function (AdminForm $form) {
			$data = $this->getHttpRequest()->getPost();

			if (!isset($data['product'])) {
				$form['product']->addError('Toto pole je povinné!');

				return;
			}

			if (!$this->productRepo->getProduct($data['product'])) {
				$form['product']->addError('Daný produkt nebyl nalezen nebo není dostupný');
			}
		};

		$form->onSuccess[] = function (AdminForm $form) use ($order) {
			$values = $form->getValues('array');

			/** @var Cart $cart */
			$cart = $this->cartRepository->one($values['cart']);

			if ($order->purchase->customer) {
				$this->shopper->setCustomer($order->purchase->customer);
				$this->checkoutManager->setCustomer($order->purchase->customer);
			}

			/** @var Product $product */
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

	public function createComponentDetailOrderItemForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addInteger('amount');
		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			/** @var \Eshop\DB\CartItem $item */
			$item = $this->getParameter('cartItem');
			$item->update($values);

			$this->flashMessage('Provedeno', 'success');
			$form->processRedirect('', 'orderItems', [], [$this->getParameter('order')]);
		};

		return $form;
	}

	public function actionOrderEmail(Order $order): void
	{
		/** @var Form $form */
		$form = $this->getComponent('emailForm');
		$form->setDefaults($order->purchase->toArray());
	}

	public function renderOrderEmail(Order $order): void
	{
		$this->template->headerLabel = 'Poslání e-mailu: ' . $order->code;
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Položky'],
			['Nová položka']
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
			['Nová položka']
		];
		$this->template->displayButtons = [$this->createBackButton('orderItems', $order)];
		$this->template->displayControls = [$this->getComponent('newOrderItemForm')];
	}

	public function actionDetailOrderItem(CartItem $cartItem, Order $order): void
	{
		/** @var Form $form */
		$form = $this->getComponent('detailOrderItemForm');
		$form->setDefaults($cartItem->toArray());
	}

	public function actionPayment(Order $order): void
	{
		$payment = $order->getPayment();

		/** @var Form $form */
		$form = $this->getComponent('paymentForm');
		$form->setDefaults($payment ? $payment->toArray() : []);
	}

	public function renderDetailOrderItem(CartItem $cartItem, Order $order): void
	{
		$this->template->headerLabel = 'Detail položky objednávky';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Položky', 'orderItems', $order],
			['Detail položky']
		];
		$this->template->displayButtons = [$this->createBackButton('orderItems', $order)];
		$this->template->displayControls = [$this->getComponent('detailOrderItemForm')];
	}

	public function handleChangePayment(string $payment, bool $paid, bool $email = false)
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

	public function handleChangeDelivery(string $delivery, bool $shipped, bool $email = false)
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

	public function modifyPackage(Button $button)
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
		/** @var Delivery[] $deliveries */
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
}