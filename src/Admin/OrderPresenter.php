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
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Delivery;
use Eshop\DB\DeliveryRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\InternalCommentOrderRepository;
use Eshop\DB\InvoiceRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderLogItem;
use Eshop\DB\OrderLogItemRepository;
use Eshop\DB\OrderRepository;
use Eshop\DB\PackageItem;
use Eshop\DB\PackageItemRepository;
use Eshop\DB\Payment;
use Eshop\DB\PaymentRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\PickupPointRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\RelatedCartItemRepository;
use Eshop\DB\RelatedPackageItemRepository;
use Eshop\DB\RelatedTypeRepository;
use Eshop\DB\StoreRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\VatRateRepository;
use Eshop\Integration\EHub;
use Eshop\Integration\Integrations;
use Eshop\Services\DPD;
use Eshop\Services\PPL;
use Forms\Form;
use Grid\Datagrid;
use League\Csv\Writer;
use Messages\DB\TemplateRepository;
use Nette\Application\Application;
use Nette\Application\Responses\FileResponse;
use Nette\Application\UI\Presenter;
use Nette\Forms\Controls\Button;
use Nette\Forms\Controls\TextInput;
use Nette\Http\Request;
use Nette\Mail\Mailer;
use Nette\Utils\Arrays;
use Nette\Utils\DateTime;
use Nette\Utils\FileSystem;
use StORM\Collection;
use StORM\DIConnection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class OrderPresenter extends BackendPresenter
{
	protected const ORDER_STATES_NAMES = [
		Order::STATE_OPEN => 'Nové',
		Order::STATE_RECEIVED => 'Přijaté',
		Order::STATE_COMPLETED => 'Odeslané',
		Order::STATE_CANCELED => 'Stornované',
	];

	protected const ORDER_STATES_EVENTS = [
		Order::STATE_OPEN => [
			Order::STATE_RECEIVED,
			Order::STATE_COMPLETED,
			Order::STATE_CANCELED,
		],
		Order::STATE_RECEIVED => [
			Order::STATE_OPEN,
			Order::STATE_COMPLETED,
			Order::STATE_CANCELED,
		],
		Order::STATE_COMPLETED => [
			Order::STATE_OPEN,
			Order::STATE_RECEIVED,
			Order::STATE_CANCELED,
		],
		Order::STATE_CANCELED => [
			Order::STATE_OPEN,
			Order::STATE_RECEIVED,
			Order::STATE_COMPLETED,
		],
	];

	protected const CONFIGURATION = [
		'exportPPC' => false,
		'exportPPC_columns' => [],
		'defaultExportPPC_columns' => [],
		'exportEdi' => false,
		'exportCsv' => true,
		'exportTargito' => false,
		'showDispatch' => true,
		'showPay' => true,
		'showExtendedDispatch' => true,
		'showExtendedPay' => true,
		'targito' => false,
		'eHub' => false,
		/** @deprecated Use const ORDER_STATES_NAMES */
		'orderStates' => null,
		'print' => true,
		'printMultiple' => false,
		'printInvoices' => false,
		/** @deprecated PackageItem is always deleted */
		'deletePackageItemMode' => PackageItem::DELETE_MODE_MARK,
		'pauseOrder' => false,
		'noteIconColor' => null,
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
	public InvoiceRepository $invoiceRepository;

	/** @inject */
	public AddressRepository $addressRepository;

	/** @inject */
	public CustomerGroupRepository $customerGroupRepository;

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

	/** @inject */
	public OrderLogItemRepository $orderLogItemRepository;

	/** @inject */
	public PickupPointRepository $pickupPointRepository;

	/** @inject */
	public EHub $eHub;

	/** @inject */
	public RelatedTypeRepository $relatedTypeRepository;

	/** @inject */
	public Integrations $integrations;

	/** @inject */
	public VatRateRepository $vatRateRepository;

	/** @inject */
	public SettingRepository $settingRepository;

	/** @inject */
	public RelatedCartItemRepository $relatedCartItemRepository;

	/** @inject */
	public RelatedPackageItemRepository $relatedPackageItemRepository;

	/**
	 * Always use getter getTab()
	 * @persistent
	 */
	public ?string $tab = null;

	protected ?DPD $dpd = null;

	protected ?PPL $ppl = null;

	public function createComponentOrdersGrid(): Datagrid
	{
		return $this->orderGridFactory->create($this->tab, $this::CONFIGURATION, $this::ORDER_STATES_NAMES, $this::ORDER_STATES_EVENTS);
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

	public function createComponentOrderLogGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->orderLogItemRepository->many()->where('fk_order', $this->getParameter('order')), 20, 'createdTs', 'DESC', true);

		$grid->addColumnText('Vytvořena', "createdTs|date:'d.m.Y G:i'", '%s', 'createdTs', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Operace', 'operation', '%s');
		$grid->addColumnText('Doplňující zpráva', 'message', '%s');
		$grid->addColumn('Administrátor', function (OrderLogItem $orderLogItem, AdminGrid $datagrid): string {
			if ($orderLogItem->administrator) {
				$link = $this->admin->isAllowed(':Admin:Admin:Administrator:detail') && $orderLogItem->administrator->getPK() !== 'servis' ?
					$datagrid->getPresenter()->link(':Admin:Admin:Administrator:detail', [$orderLogItem->administrator, 'backLink' => $this->storeRequest()]) :
					'#';

				return "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" . $orderLogItem->administrator->fullName . '</a>';
			}

			if ($orderLogItem->administratorFullName) {
				return $orderLogItem->administratorFullName;
			}

			return '';
		});

		$grid->addFilterTextInput('search', ['administratorFullName', 'administrator.fullName'], null, 'Administrátor');
		$grid->addFilterButtons(['printDetail', $this->getParameter('order')]);

		return $grid;
	}

	public function createComponentDeliveryForm(): Form
	{
		/** @var \Eshop\DB\Order|null $order */
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

			if ($order) {
				$order->purchase->update(['deliveryType' => $values['type']]);
			}

			/** @var \Admin\DB\Administrator|null $admin */
			$admin = $this->admin->getIdentity();

			if (!$admin) {
				return;
			}

			Arrays::invoke($this->orderRepository->onOrderDeliveryChanged, $order, $delivery);
			$this->orderLogItemRepository->createLog($delivery->order, OrderLogItem::DELIVERY_CHANGED, $delivery->getTypeName(), $admin);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detailDelivery', 'delivery', [$delivery], [$order]);
		};

		return $form;
	}

	public function createComponentEmailForm(): Form
	{
		$order = $this->getParameter('order');

		$form = $this->formFactory->create();

		$templates = ['order.created', 'order.confirmed', 'order.canceled', 'order.changed', 'order.created', 'order.payed', 'order.shipped'];

		$form->addSelect('template', 'Šablona', $this->templateRepository->many()->where('uuid', $templates)->orderBy(['name'])->toArrayOf('name'))->setRequired();
		$form->addText('email', 'E-mail')->setRequired();
		$form->addText('ccEmails', 'Kopie e-mailů')->setNullable();

		$form->addSubmit('submit', 'Odeslat');

		$form->onSuccess[] = function (AdminForm $form) use ($order): void {
			$values = $form->getValues('array');

			try {
				$mail = $this->templateRepository->createMessage($values['template'], $this->orderRepository->getEmailVariables($order), $values['email'], $values['ccEmails']);
				$this->mailer->send($mail);

				/** @var \Messages\DB\Template $template */
				$template = $this->templateRepository->one($values['template']);

				/** @var \Admin\DB\Administrator|null $admin */
				$admin = $this->admin->getIdentity();

				$this->orderLogItemRepository->createLog($order, OrderLogItem::EMAIL_SENT, $template->name, $admin);
			} catch (\Throwable $e) {
			}

			$this->flashMessage('Odesláno', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function createComponentSendToSuppliersForm(): Form
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->getParameter('order');

		$form = $this->formFactory->create();

		$form->addSelect('type', 'K jakým dodavatelům odeslat?', ['all' => 'Všem', 'onlyUncompleted' => 'Pouze nedokončeným'])->setDefaultValue('onlyUncompleted');
		$form->addSubmit('submit', 'Odeslat');

		$form->onSuccess[] = function (AdminForm $form) use ($order): void {
			$values = $form->getValues('array');

			if ($values['type'] === 'onlyUncompleted') {
				$this->sendToSuppliersOnlyUncompleted($order);
			} else {
				$this->sendToSuppliersAll($order);
			}

			$this->flashMessage('Odesláno<br>Více informací naleznete v záznamech o objednávce', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function createComponentPaymentForm(): Form
	{
		/** @var \Eshop\DB\Order|null $order */
		$order = $this->getParameter('order') ?: $this->getParameter('payment')->order;

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

		$form->onSuccess[] = function (AdminForm $form) use ($order): void {
			$values = $form->getValues('array');

			$type = $this->paymentTypeRepository->one($values['type'])->toArray();
			$values['typeCode'] = $type['code'];
			$values['typeName'] = $type['name'];

			$payment = $this->paymentRepository->syncOne($values);

			if ($order) {
				$order->purchase->update(['paymentType' => $values['type']]);
			}

			/** @var \Admin\DB\Administrator|null $admin */
			$admin = $this->admin->getIdentity();

			if (!$admin) {
				return;
			}

			Arrays::invoke($this->orderRepository->onOrderPaymentChanged, $order, $payment);
			$this->orderLogItemRepository->createLog($payment->order, OrderLogItem::PAYMENT_CHANGED, $payment->getTypeName(), $admin);

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
			$tabs = \array_merge(['open' => 'Nové'], $tabs);
		}

		$this->template->tabs = $tabs;

		if ($this->tab === 'bannedEmails') {
			$this->template->headerLabel = 'Blokované e-maily';
			$this->template->headerTree = [
				['Blokované e-maily', 'default'],
			];

			$this->template->displayControls = [$this->getComponent('bannedEmailGrid')];

			return;
		}

		$this->template->headerLabel = 'Objednávky';
		$this->template->headerTree = [
			['Objednávky', 'default'],
		];

		$this->template->displayControls = [$this->getComponent('ordersGrid')];

		/** @var \Grid\Datagrid $grid */
		$grid = $this->getComponent('ordersGrid');
		$this->template->ordersForJBOX = $grid->getItemsOnPage();
	}

	public function renderDetail(Order $order): void
	{
		unset($order);

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
		unset($order);

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

	public function createComponentProductForm(): AdminForm
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->getParameter('order');

		$form = $this->formFactory->create();

		$form->monitor(Presenter::class, function () use ($form, $order): void {
			$form->addSelectAjax('product', 'Produkt', '- Vyberte produkt -', Product::class);

			$form->addSelect('cart', 'Košík č.', $order->purchase->getCarts()->toArrayOf('id'))->setRequired();
			$form->addSelect('package', 'Balík č.', $order->getPackages()->toArrayOf('id'))->setRequired();

			$form->addInteger('amount', 'Množství')->setDefaultValue(1)->setRequired();

			$form->addSubmits(false, false);
		});

		$form->onValidate[] = function (AdminForm $form) use ($order): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getHttpData();

			if (!isset($values['product'])) {
				/** @var \Nette\Forms\Controls\SelectBox $input */
				$input = $form['product'];
				$input->addError('Toto pole je povinné!');

				return;
			}

			/** @var \Nette\Forms\Controls\SelectBox $productInput */
			$productInput = $form['product'];

			$this->shopper->setCustomer($order->purchase->customer);

			if ($this->productRepo->getProducts($this->checkoutManager->getPricelists()->toArray())->where('this.uuid', $values['product'])->first()) {
				return;
			}

			$productInput->addError('Daný produkt nebyl nalezen nebo není dostupný pro uživatele');
		};

		$form->onSuccess[] = function (AdminForm $form) use ($order): void {
			$values = $form->getValuesWithAjax();

			/** @var \Eshop\DB\Cart $cart */
			$cart = $this->cartRepository->one($values['cart']);

			if ($order->purchase->customer) {
				$this->shopper->setCustomer($order->purchase->customer);
				$this->checkoutManager->setCustomer($order->purchase->customer);
			}

			/** @var \Eshop\DB\Product $product */
			$product = $this->productRepo->getProducts($this->shopper->getPricelists()->toArray())->where('this.uuid', $values['product'])->first();

			$cartItem = $this->checkoutManager->addItemToCart($product, null, $values['amount'], null, false, false, $cart);

			$packageItem = $this->packageItemRepository->createOne([
				'amount' => $values['amount'],
				'package' => $values['package'],
				'cartItem' => $cartItem,
			]);

			$setRelationType = $this->settingRepository->getValueByName(SettingsPresenter::SET_RELATION_TYPE);

			if ($setRelationType) {
				/** @var \Eshop\DB\RelatedType $setRelationType */
				$setRelationType = $this->relatedTypeRepository->one($setRelationType);
			}

			/* Get default set relation type and slave products in that relation for top-level cart item */
			if (!$setRelationType) {
				$this->flashMessage('Provedeno', 'success');
				$form->processRedirect('this');
			}

			/** @var array<mixed> $relatedCartItems */
			$relatedCartItems = [];

			/* Load real products in relation with prices */
			$relatedProducts = $this->productRepository->getSlaveRelatedProducts($setRelationType, $cartItem->product)->toArray();

			if (!$relatedProducts) {
				$this->flashMessage('Provedeno', 'success');
				$form->processRedirect('this');
			}

			$slaveProducts = [];

			foreach ($relatedProducts as $relatedProduct) {
				$slaveProducts[] = $relatedProduct->getValue('slave');
			}

			/* Compute total price of set items */
			/** @var array<\Eshop\DB\Product> $slaveProducts */
			$slaveProducts = $this->productRepository->getProducts()->where('this.uuid', $slaveProducts)->toArray();
			$slaveProductsTotalPrice = 0;
			$slaveProductsTotalPriceVat = 0;

			foreach ($relatedProducts as $relatedProduct) {
				if (!isset($slaveProducts[$relatedProduct->getValue('slave')])) {
					continue;
				}

				$slaveProductsTotalPrice += $slaveProducts[$relatedProduct->getValue('slave')]->getPrice() * $relatedProduct->amount;
				$slaveProductsTotalPriceVat += $slaveProducts[$relatedProduct->getValue('slave')]->getPriceVat() * $relatedProduct->amount;
			}

			$setTotalPriceModifier = $slaveProductsTotalPrice > 0 ? $cartItem->price / $slaveProductsTotalPrice : 1;
			$setTotalPriceVatModifier = $slaveProductsTotalPriceVat > 0 ? $cartItem->priceVat / $slaveProductsTotalPriceVat : 1;

			foreach ($relatedProducts as $relatedProduct) {
				if (!isset($slaveProducts[$relatedProduct->getValue('slave')])) {
					continue;
				}

				$product = $slaveProducts[$relatedProduct->getValue('slave')];

				/** @var \Eshop\DB\VatRate|null $vat */
				$vat = $this->vatRateRepository->one($product->vatRate);
				$vatPct = $vat ? $vat->rate : 0;

				/* Create related cart items with price computed to match unit price of top-level cart item */
				$relatedCartItems[] = [
					'cartItem' => $cartItem->getPK(),
					'relatedType' => $setRelationType->getPK(),
					'product' => $product->getPK(),
					'relatedTypeCode' => $setRelationType->code,
					'relatedTypeName' => $setRelationType->name,
					'productName' => $product->toArray()['name'],
					'productCode' => $product->getFullCode(),
					'productSubCode' => $product->subCode,
					'productWeight' => $product->weight,
					'productDimension' => $product->dimension,
					'amount' => $relatedProduct->amount * $cartItem->amount,
					'price' => $product->getPrice() * $setTotalPriceModifier,
					'priceVat' => $product->getPriceVat() * $setTotalPriceVatModifier,
					'priceBefore' => $product->getPriceBefore() ?: $product->getPrice(),
					'priceVatBefore' => $product->getPriceVatBefore() ?: $product->getPriceVat(),
					'vatPct' => (float) $vatPct,
				];
			}

			if (!$relatedCartItems) {
				$this->flashMessage('Provedeno', 'success');
				$form->processRedirect('this');
			}

			/** @var array<\Eshop\DB\RelatedCartItem> $relatedCartItems */
			$relatedCartItems = $this->relatedCartItemRepository->createMany($relatedCartItems)->toArray();

			/* Create relation between related package item and related cart item */
			foreach ($relatedCartItems as $relatedCartItem) {
				$this->relatedPackageItemRepository->createOne([
					'cartItem' => $relatedCartItem->getPK(),
					'packageItem' => $packageItem->getPK(),
				]);
			}

			/** @var \Admin\DB\Administrator|null $admin */
			$admin = $this->admin->getIdentity();

			if (!$admin) {
				return;
			}

			$this->orderLogItemRepository->createLog($order, OrderLogItem::NEW_ITEM, $product->name . ' | ' . $values['amount'] . ' ks', $admin);

			$this->flashMessage('Provedeno', 'success');
			$form->processRedirect('this');
		};

		return $form;
	}

	public function createComponentSplitOrderItemForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->getCurrentGroup()->setOption('label', 'Nová položka');
		$form->addInteger('amount', 'Množství')->setDefaultValue(1)->setRequired()->addRule($form::MIN, 'Zadejte číslo větší než 0!', 1);
		$form->addSelect('store', 'Sklad', $this->storeRepository->many()->toArrayOf('name'))->setPrompt('Žádný');
		$form->addSubmits(false, false);
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			/** @var \Eshop\DB\PackageItem $packageItem */
			$packageItem = $this->packageItemRepository->one($values['uuid'], true);

			$values['amount'] = $values['amount'] > $packageItem->amount ? $packageItem->amount : $values['amount'];

			$oldCartItemArray = $packageItem->cartItem->toArray();
			unset($oldCartItemArray['uuid']);
			$oldCartItemArray['amount'] = $values['amount'];

			$packageItem->update(['amount' => $packageItem->cartItem->amount - $values['amount']]);
			$packageItem->cartItem->update(['amount' => $packageItem->cartItem->amount - $values['amount']]);

			$oldPackageItemArray = $packageItem->toArray();
			unset($oldPackageItemArray['uuid']);
			$oldPackageItemArray['amount'] = $values['amount'];
			$oldPackageItemArray['store'] = $values['store'];

			$newCartItem = $this->cartItemRepo->createOne($oldCartItemArray);
			$oldPackageItemArray['cartItem'] = $newCartItem->getPK();

			$this->packageItemRepository->createOne($oldPackageItemArray);

			/** @var \Eshop\DB\Order $order */
			$order = $this->getParameter('order');

			/** @var \Admin\DB\Administrator|null $admin */
			$admin = $this->admin->getIdentity();

			if (!$admin) {
				return;
			}

			$this->orderLogItemRepository->createLog($order, OrderLogItem::SPLIT, $packageItem->cartItem->productName, $admin);

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

			/** @var \Eshop\DB\PackageItem $packageItem */
			$packageItem = $this->packageItemRepository->one($values['uuid'], true);
			$packageItem->update(['store' => $values['store'], 'status' => 'waiting']);

			/** @var \Eshop\DB\Order $order */
			$order = $this->getParameter('order');

			/** @var \Admin\DB\Administrator|null $admin */
			$admin = $this->admin->getIdentity();

			if (!$admin) {
				return;
			}

			$this->orderLogItemRepository->createLog($order, OrderLogItem::PACKAGE_CHANGED, $packageItem->cartItem->productName . ': Změna skladu - ' . $packageItem->store->name, $admin);

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function createComponentMergeOrderForm(): AdminForm
	{
		$orderRepository = $this->orderRepository;

		$form = $this->formFactory->create();

		/** @var \Eshop\DB\Order $targetOrder */
		$targetOrder = $this->getParameter('order');

		$form->addText('code', 'Kód objednávky')->addRule(function (TextInput $value) use ($orderRepository) {
			return !$orderRepository->many()->where('code', $value->getValue())->isEmpty();
		}, 'Tato objednávka neexistuje')->setRequired();
		$form->addSubmit('submit', 'Sloučit');

		$form->onValidate[] = function (AdminForm $form) use ($targetOrder): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues('array');

			/** @var \Eshop\DB\Order $oldOrder */
			$oldOrder = $this->orderRepository->one(['code' => $values['code']], true);

			if ($oldOrder->getPK() !== $targetOrder->getPK()) {
				return;
			}

			/** @var \Nette\Forms\Controls\TextInput $codeInput */
			$codeInput = $form['code'];
			$codeInput->addError('Nelze sloučit se stejnou objednávkou!');
		};

		$form->onSuccess[] = function (AdminForm $form) use ($targetOrder): void {
			$values = $form->getValues('array');

			$connection = $this->productRepository->getConnection();

			$connection->getLink()->beginTransaction();

			try {
				/** @var \Eshop\DB\Cart $targetCart */
				$targetCart = $targetOrder->purchase->carts->first();

				/** @var \Eshop\DB\Order $oldOrder */
				$oldOrder = $this->orderRepository->one(['code' => $values['code']], true);

				/** @var \Eshop\DB\Cart $oldCart */
				$oldCart = $oldOrder->purchase->carts->first();

				/** @var \Eshop\DB\Package $package */
				$package = $targetOrder->packages->first();

				if ($oldOrder->purchase->customer && $oldOrder->purchase->account) {
					$oldOrder->purchase->customer->setAccount($oldOrder->purchase->account);
					$this->shopper->setCustomer($oldOrder->purchase->customer);
				} else {
					$this->shopper->setCustomer(null);
					$this->shopper->setCustomerGroup($this->customerGroupRepository->getUnregisteredGroup());
				}

				/** @var array<\Eshop\DB\PackageItem> $topLevelItems */
				$topLevelItems = [];

				foreach ($oldCart->items->where('this.fk_upsell IS NULL') as $item) {
					if (($product = $item->getValue('product')) === null) {
						throw new \Exception('Product not found');
					}

					if (!$product = $this->productRepository->getProduct($product)) {
						throw new \Exception('Product not found');
					}

					if (!$item->getPriceSum() > 0) {
						$product->price = 0;
					}

					if (!$item->getPriceVatSum() > 0) {
						$product->priceVat = 0;
					}

					$cartItem = $this->checkoutManager->addItemToCart($product, null, $item->amount, null, false, false, $targetCart);

					$topLevelItems[$item->getPK()] = $this->packageItemRepository->createOne([
						'package' => $package->getPK(),
						'cartItem' => $cartItem->getPK(),
						'amount' => $cartItem->amount,
					]);
				}

				$relations = $this->productRepository->getCartItemsRelations($oldCart->items->toArray(), false, false);

				foreach ($oldCart->items->clear(true)->where('this.fk_upsell IS NOT NULL') as $item) {
					if (($product = $item->getValue('product')) === null) {
						throw new \Exception('Product not found');
					}

					if (($upsellProduct = $item->getValue('upsell')) === null) {
						throw new \Exception('Upsell product not found');
					}

					if (!isset($relations[$upsellProduct][$product])) {
						throw new \Exception('Product not found');
					}

					$product = $relations[$upsellProduct][$product];

					if (!$item->getPriceSum() > 0) {
						$product->price = 0;
					}

					if (!$item->getPriceVatSum() > 0) {
						$product->priceVat = 0;
					}

					$cartItem = $this->checkoutManager->addUpsellToCart($topLevelItems[$item->getValue('upsell')]->cartItem, $product, $item->realAmount);

					$this->packageItemRepository->createOne([
						'package' => $package->getPK(),
						'cartItem' => $cartItem->getPK(),
						'amount' => $cartItem->amount,
						'upsell' => $topLevelItems[$item->getValue('upsell')]->getPK(),
					]);
				}

				/** @var \Admin\DB\Administrator|null $admin */
				$admin = $this->admin->getIdentity();

				$this->orderRepository->cancelOrder($oldOrder, $admin);

				$this->orderLogItemRepository->createLog($targetOrder, OrderLogItem::MERGED, $oldOrder->code, $admin);

				$connection->getLink()->commit();

				$this->flashMessage('Provedeno', 'success');
			} catch (\Throwable $e) {
				$connection->getLink()->rollBack();

				Debugger::log($e->getMessage(), ILogger::ERROR);

				$this->flashMessage('Spojení objednávek se nezdařilo!', 'error');
			}

			$this->redirect('this');
		};

		return $form;
	}

	public function createComponentDetailOrderItemForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addHidden('packageItem');
		$form->getCurrentGroup()->setOption('label', 'Nákup');
		$form->addInteger('amount', 'Množství')->setRequired();

		$form->addTextArea('note', 'Poznámka')->setNullable();
		$form->addGroup('Cena za kus');
		$form->addText('price', 'Cena bez DPH')->addRule(Form::FLOAT)->setRequired();
		$form->addText('priceVat', 'Cena s DPH')->addRule(Form::FLOAT)->setRequired();
		$form->addInteger('vatPct', 'DPH')->setRequired();
		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$cartItemOld = $this->cartItemRepo->one($values['uuid'], true);
			$cartItem = clone $cartItemOld;

			$packageItem = $this->packageItemRepository->one($values['packageItem']);

			foreach ($packageItem->relatedPackageItems as $relatedPackageItem) {
				$relatedCartItem = $relatedPackageItem->cartItem;

				$relatedCartItem->update(['amount' => $relatedCartItem->amount / $cartItemOld->amount * $values['amount']]);
			}

			$packageItem->update(['amount' => $values['amount']]);

			$cartItem->update($values);

			/** @var \Eshop\DB\Order|null $order */
			$order = $this->getParameter('order');

			if (!$order) {
				return;
			}

			/** @var \Admin\DB\Administrator|null $admin */
			$admin = $this->admin->getIdentity();

			if (!$admin) {
				return;
			}

			$currencySymbol = $order->purchase->currency->symbol;
			$amountChange = $cartItemOld->amount !== $cartItem->amount ? ' | Množství z ' . $cartItemOld->amount . ' na ' . $cartItem->amount : '';
			$priceChange = $cartItemOld->price !== $cartItem->price ? " | Cena z $cartItemOld->price $currencySymbol na $cartItem->price $currencySymbol" : '';
			$vatChange = $cartItemOld->vatPct !== $cartItem->vatPct ? '  | DPH z ' . $cartItemOld->vatPct . ' % na ' . $cartItem->vatPct . ' %' : '';

			$changes = $amountChange . $priceChange . $vatChange;

			$this->orderLogItemRepository->createLog($order, OrderLogItem::ITEM_EDITED, $cartItem->productName . $changes, $admin);

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
		unset($order);

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
		unset($cartItem);

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
		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $this->admin->getIdentity();

		$this->orderRepository->changePayment($payment, $paid, $email, $admin);

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

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $this->admin->getIdentity();

		if (!$admin) {
			return;
		}

		$delivery->update($values);

		if ($shipped) {
			$this->orderLogItemRepository->createLog($delivery->order, OrderLogItem::SHIPPED, null, $admin);

			if ($email) {
				try {
					$mail = $this->templateRepository->createMessage('order.shipped', ['orderCode' => $delivery->order->code], $delivery->order->purchase->email);
					$this->mailer->send($mail);

					$this->orderLogItemRepository->createLog($delivery->order, OrderLogItem::EMAIL_SENT, OrderLogItem::SHIPPED, $admin);
				} catch (\Throwable $e) {
				}
			}
		} else {
			$this->orderLogItemRepository->createLog($delivery->order, OrderLogItem::SHIPPED_CANCELED, null, $admin);
		}

		$this->flashMessage($shipped ? 'Expedováno' : 'Expedice zrušena', 'success');

		$this->redirect('this');
	}

	public function modifyPackage(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
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

	public function renderDeliveryColumn(CartItem $item, Datagrid $grid): string
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
		unset($ids);

		$this->template->headerLabel = 'Export pro PPC';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Export pro PPC'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('exportPPCForm')];
	}

	public function createComponentExportPPCForm(): AdminForm
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $this->getComponent('ordersGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $grid->getPaginator()->getItemCount();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Exportovat', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'   ' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		]);
		$form->addCheckbox('header', 'Hlavička')->setDefaultValue(true)->setHtmlAttribute('data-info', 'Pokud tuto možnost nepoužijete, tak nebude možné tento soubor použít pro import!');

		$headerColumns = $form->addDataMultiSelect('columns', 'Sloupce');

		$items = [];
		$defaultItems = [];

		if (isset($this::CONFIGURATION['exportPPC_columns'])) {
			$items += $this::CONFIGURATION['exportPPC_columns'];

			if (isset($this::CONFIGURATION['defaultExportPPC_columns'])) {
				$defaultItems = \array_merge($defaultItems, $this::CONFIGURATION['defaultExportPPC_columns']);
			}
		}

		$headerColumns->setItems($items);
		$headerColumns->setDefaultValue($defaultItems);

		$form->addSubmit('submit', 'Exportovat');

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $grid, $items): void {
			$values = $form->getValues('array');

			$selectedItems = $values['bulkType'] === 'selected' ? $this->orderRepository->many()->where('this.uuid', $ids) : $grid->getFilteredSource();

			$tempFilename = \tempnam($this->tempDir, 'csv');
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

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, 'orders.csv', 'text/csv'));
		};

		return $form;
	}

	public function createComponentOrderForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addGroup('Kontakty');
		$form->addText('phone', 'Telefon')->setNullable();
		$form->addText('email', 'E-mail')->setNullable();

		$form->addGroup('Fakturační adresa');
		$billAddress = $form->addContainer('billAddress');
		$billAddress->addHidden('uuid')->setNullable();
		$billAddress->addText('street', 'Ulice');
		$billAddress->addText('city', 'Město');
		$billAddress->addText('zipcode', 'PSČ');

		$form->addGroup('Doručovací adresa');
		$otherAddress = $form->addCheckbox('otherAddress', 'Doručovací adresa je jiná než fakturační');

		$deliveryAddress = $form->addContainer('deliveryAddress');
		$deliveryAddress->addHidden('uuid')->setNullable();
		$deliveryAddress->addText('name', ' Jméno a příjmení / název firmy');
		$deliveryAddress->addText('companyName', ' Název firmy');
		$deliveryAddress->addText('street', 'Ulice');
		$deliveryAddress->addText('city', 'Město');
		$deliveryAddress->addText('zipcode', 'PSČ');

		/** @var \Nette\Forms\Controls\BaseControl $input */
		foreach ($deliveryAddress->getComponents() as $input) {
			$otherAddress->addCondition($form::EQUAL, true)->toggle($input->getHtmlId() . '-toogle');
		}

		$form->addGroup('Ostatní');
		$form->addDate('desiredShippingDate', 'Požadované doručení')->setNullable();
		$form->addText('internalOrderCode', 'Zákaznické číslo')->setNullable();
		$form->addTextArea('note', 'Poznámka')->setNullable();
		$form->addTextArea('internalNote', 'Interní poznámka')->setNullable();

		$form->addSubmits(!$this->getParameter('order'));

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			unset($values['uuid']);

			foreach (['deliveryAddress', 'billAddress'] as $address) {
				if ($values[$address]['uuid'] === null) {
					$values[$address]['uuid'] = DIConnection::generateUuid();
				}
			}

			/** @var \Eshop\DB\Order $order */
			$order = $this->getParameter('order');

			if (!$values['otherAddress']) {
				if ($order->purchase->deliveryAddress) {
					$order->purchase->deliveryAddress->delete();
				}

				$values['deliveryAddress'] = null;
			}

			$order->purchase->update($values, true);

			/** @var \Admin\DB\Administrator|null $admin */
			$admin = $this->admin->getIdentity();

			if (!$admin) {
				return;
			}

			$this->orderLogItemRepository->createLog($order, OrderLogItem::EDITED, null, $admin);

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

	public function createComponentNewComment(): AdminForm
	{
		$form = $this->formFactory->create(true, false, false, false, false);

		$form->addGroup('Nový komentář');
		$form->addTextArea('text', 'Komentáře');

		$form->addSubmit('send', 'Odeslat');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues('array');

			/** @var \Admin\DB\Administrator|null $admin */
			$admin = $this->admin->getIdentity();

			if (!$admin) {
				return;
			}

			/** @var \Eshop\DB\InternalCommentOrder $comment */
			$comment = $this->commentRepository->createOne([
				'order' => $this->getParameter('order')->getPK(),
				'text' => $values['text'],
				'administrator' => $admin->getPK(),
				'adminFullname' => $admin->fullName,
			]);

			$this->orderLogItemRepository->createLog($comment->order, OrderLogItem::NEW_COMMENT, $comment->text, $admin);


			$this->flashMessage('Uloženo', 'success');
			$this->redirect('comments', $this->getParameter('order'));
		};

		return $form;
	}

	public function actionPrintDetail(Order $order): void
	{
		$array = $order->purchase->toArray(['billAddress', 'deliveryAddress']) + $order->toArray();

		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('orderForm');

		$this->getComponent('productForm');

		$array['otherAddress'] = (bool) $order->purchase->deliveryAddress;

		$form->setDefaults($array);
	}

	public function renderPrintDetail(Order $order): void
	{
		$this->template->states = $this::ORDER_STATES_NAMES;
		$this->template->headerLabel = 'Objednávka - ' . $order->code;

		$this->template->order = $order;

		if ($order->purchase->zasilkovnaId) {
			$this->template->pickupPoint = $this->pickupPointRepository->many()->where('code', 'zasilkovna_' . $order->purchase->zasilkovnaId)->first();
		} elseif ($order->purchase->pickupPoint) {
			$this->template->pickupPoint = $order->purchase->pickupPoint;
		} else {
			$this->template->pickupPoint = null;
		}

		$this->template->packages = clone $order->packages;

		$upsells = [];

		foreach ($order->packages as $package) {
			foreach ($package->items->where('this.fk_upsell IS NULL') as $item) {
				$upsells[$package->getPK()][$item->getPK()] = $this->packageItemRepository->many()->where('this.fk_upsell', $item->getPK())->toArray();
			}
		}

		$this->template->upsells = $upsells;

		$relations = [];

		/** @var \Eshop\DB\RelatedType $relatedType */
		foreach ($this->relatedTypeRepository->getSetTypes() as $relatedType) {
			/** @var \Eshop\DB\CartItem $item */
			foreach ($order->purchase->getItems()->where('fk_product IS NOT NULL') as $item) {
				$relations[$item->getValue('product')] = $this->productRepo->getSlaveRelatedProducts($relatedType, $item->getValue('product'))->toArray();
			}
		}

		$this->template->relations = $relations;

		$this->template->stores = $this->storeRepository->many();
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Detail'],
		];
		$this->template->setFile(__DIR__ . '/templates/Order.printDetail.latte');

		$this->template->displayButtons = [$this->createBackButton('default')];

		$state = $this->orderRepository->getState($order);

		$this->template->displayButtonsRight = [];

		$stateOpen = $this->getOrderStateName(Order::STATE_OPEN);
		$stateReceived = $this->getOrderStateName(Order::STATE_RECEIVED);
		$stateFinished = $this->getOrderStateName(Order::STATE_COMPLETED);
		$stateCanceled = $this->getOrderStateName(Order::STATE_CANCELED);

		$openOrderButton = $this->createButtonWithClass('openOrder!', "<i class='fas fa-angle-double-right'></i> $stateOpen", 'btn btn-sm btn-primary', $order->getPK());
		$receiveOrderButton = $this->createButtonWithClass('receiveOrder!', "<i class='fas fa-angle-double-right'></i> $stateReceived", 'btn btn-sm btn-success', $order->getPK());
		$receiveAndCompleteOrderButton = $this->createButtonWithClass(
			'receiveAndCompleteOrder!',
			"<i class='fas fa-angle-double-right'></i> $stateFinished",
			'btn btn-sm btn-success',
			$order->getPK(),
		);
		$completeOrderButton = $this->createButtonWithClass('completeOrder!', "<i class='fas fa-angle-double-right'></i> $stateFinished", 'btn btn-sm btn-success', $order->getPK());
		$cancelOrderButton = $this->createButtonWithClass('cancelOrder!', "<i class='fas fa-angle-double-right'></i> $stateCanceled", 'btn btn-sm btn-danger', $order->getPK());

		$banOrderButton = $this->createButtonWithClass(
			'banOrder!',
			'<i class="fas fa-exclamation mr-1"></i> Zablokovat objednávku',
			'btn btn-sm btn-warning',
			$order->getPK(),
		);

		$unBanOrderButton = $this->createButtonWithClass(
			'unBanOrder!',
			'<i class="fas fa-check-circle mr-1"></i> Odblokovat objednávku',
			'btn btn-sm btn-warning',
			$order->getPK(),
		);

		$buttonsByTargetStates = [
			Order::STATE_OPEN => [
				Order::STATE_RECEIVED => $receiveOrderButton,
				Order::STATE_COMPLETED => $receiveAndCompleteOrderButton,
				Order::STATE_CANCELED => $cancelOrderButton,
			],
			Order::STATE_RECEIVED => [
				Order::STATE_OPEN => $openOrderButton,
				Order::STATE_COMPLETED => $completeOrderButton,
				Order::STATE_CANCELED => $cancelOrderButton,
			],
			Order::STATE_COMPLETED => [
				Order::STATE_OPEN => $openOrderButton,
				Order::STATE_RECEIVED => $receiveOrderButton,
				Order::STATE_CANCELED => $cancelOrderButton,
			],
			Order::STATE_CANCELED => [
				Order::STATE_OPEN => $openOrderButton,
				Order::STATE_RECEIVED => $receiveOrderButton,
				Order::STATE_COMPLETED => $completeOrderButton,
			],
		];

		foreach ($buttonsByTargetStates[$state] ?? [] as $targetState => $button) {
			if (!isset($this::ORDER_STATES_EVENTS[$state]) || !Arrays::contains($this::ORDER_STATES_EVENTS[$state], $targetState) ||
				($state === Order::STATE_OPEN && !$this->shopper->getEditOrderAfterCreation())) {
				continue;
			}

			$this->template->displayButtonsRight[] = $button;
		}

		$this->template->displayButtonsRight[] = !$order->bannedTs ? $banOrderButton : $unBanOrderButton;

		$this->template->displayButtons[] =
			'<a href="#" data-toggle="modal" data-target="#modal-orderForm"><button class="btn btn-sm btn-primary"><i class="fas fa-edit mr-1"></i> Editovat</button></a>';
		$this->template->displayButtons[] =
			'<a href="#" data-toggle="modal" data-target="#modal-productForm"><button class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Produkt</button></a>';
		$this->template->displayButtons[] =
			'<a href="#" data-toggle="modal" data-target="#modal-mergeOrderForm"><button class="btn btn-sm btn-primary"><i class="fas fa-compress mr-1"></i> Spojit</button></a>';

		if (!isset($this::CONFIGURATION['print']) || (isset($this::CONFIGURATION['print']) && $this::CONFIGURATION['print'])) {
			$this->template->displayButtons[] =
				'<a href="#" onclick="window.print();"><button class="btn btn-sm btn-primary"><i class="fas fa-print mr-1"></i> Tisk</button></a>';
		}

		if (isset($this::CONFIGURATION['printInvoices']) && $this::CONFIGURATION['printInvoices']) {
			if ((clone $order->invoices)->count() > 0) {
				$invoice = (clone $order->invoices)->first();

				$link = $this->link(':Eshop:Export:invoice', $invoice->hash);
				$icon = $invoice->printed ? 'fa-check' : 'fa-print';
				$class = $invoice->printed ? 'btn btn-sm btn-success' : 'btn btn-sm btn-primary';

				$this->template->displayButtons[] =
					"<a href='$link'><button class='$class'><i class='fas $icon mr-1'></i> Tisk faktury</button></a>";
			} else {
				$this->template->displayButtons[] =
					"<button class='btn btn-sm btn-primary disabled' disabled><i class='fas fa-times mr-1'></i> Tisk faktury</button>";
			}
		}

//		$this->template->displayButtons[] = $this->createButton('cloneOrder!', '<i class="far fa-clone mr-1"></i>Objednat znovu', [$order->getPK()]);
		$this->template->displayButtons[] =
			'<a href="#" data-toggle="modal" data-target="#modal-emailForm"><button class="btn btn-sm btn-primary"><i class="fas fa-envelope mr-1"></i> Poslat e-mail</button></a>';

		$this->template->displayButtons[] = $this->createButton('exportEdi!', '<i class="fa fa-download mr-1"></i>EDI', [$order->getPK()]);
		$this->template->displayButtons[] = $this->createButton('exportCsv!', '<i class="fa fa-download mr-1"></i>CSV', [$order->getPK()]);
		//  window.print()
	}

	public function createComponentPrintInvoiceMultipleForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('ordersGrid'), function (array $values, Collection $collection, AdminForm $form): void {
			$hashes = $this->invoiceRepository->many()
				->join(['orders' => 'eshop_invoice_nxn_eshop_order'], 'this.uuid = orders.fk_invoice')
				->where('orders.fk_order', $collection->toArrayOf('uuid', [], true));

			/** @var \Nette\Forms\Controls\SubmitButton $submitter */
			$submitter = $form->isSubmitted();

			if ($submitter->getName() === 'onlyNotPrinted') {
				$hashes->where('this.printed', false);
			}

			$this->redirect(':Eshop:Export:invoiceMultiple', [$hashes->toArrayOf('hash', [], true)]);
		}, $this->getBulkFormActionLink(), $this->orderRepository->many(), $this->getBulkFormIds(), function (AdminForm $form): void {
			$form->addSubmit('onlyNotPrinted', 'Pouze nevytištěné');

			/** @var \Nette\Forms\Controls\SubmitButton $submit */
			$submit = $form['submit'];
			$submit->setCaption('Vše');
		});
	}

	public function renderPrintInvoiceMultiple(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Tisk faktur';
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Tisk faktur'],
		];
		$this->template->displayButtons = [$this->getComponent('printInvoiceMultipleForm')];
	}

	public function renderPrintDetailMultiple(array $ids): void
	{
		$this->template->headerLabel = 'Tisk objednávek';

		$this->template->orders = $orders = $this->orderRepository->many()->where('this.uuid', $ids)->toArray();

		$upsells = [];

		foreach ($orders as $order) {
			foreach ($order->packages as $package) {
				foreach ($package->items->where('this.fk_upsell IS NULL') as $item) {
					$upsells[$order->getPK()][$package->getPK()][$item->getPK()] = $this->packageItemRepository->many()->where('this.fk_upsell', $item->getPK())->toArray();
				}
			}
		}

		$this->template->upsells = $upsells;

		$relations = [];

		/** @var \Eshop\DB\RelatedType $relatedType */
		foreach ($this->relatedTypeRepository->getSetTypes() as $relatedType) {
			foreach ($orders as $order) {
				/** @var \Eshop\DB\CartItem $item */
				foreach ($order->purchase->getItems()->where('fk_product IS NOT NULL') as $item) {
					$relations[$order->getPK()][$item->getValue('product')] = $this->productRepo->getSlaveRelatedProducts($relatedType, $item->getValue('product'))->toArray();
				}
			}
		}

		$this->template->relations = $relations;

		$this->template->stores = $this->storeRepository->many();
		$this->template->headerTree = [
			['Objednávky', 'default'],
			['Tisk'],
		];

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayButtons[] =
			'<a href="#" onclick="window.print();"><button class="btn btn-sm btn-primary"><i class="fas fa-print mr-1"></i> Tisk</button></a>';

		$this->template->setFile(__DIR__ . '/templates/Order.printDetailMultiple.latte');
	}

	public function handleToggleDeleteOrderItem(string $itemId): void
	{
		/** @var \Eshop\DB\PackageItem $packageItem */
		$packageItem = $this->packageItemRepository->one($itemId, true);

		/** @var \Eshop\DB\Order $order */
		$order = $this->getParameter('order');

		foreach ($packageItem->relatedPackageItems as $relatedPackageItem) {
			$relatedPackageItem->cartItem->delete();
			$relatedPackageItem->delete();
		}

		$packageItem->cartItem->delete();
		$packageItem->delete();

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $this->admin->getIdentity();

		if (!$admin) {
			return;
		}

		$this->orderLogItemRepository->createLog(
			$order,
			OrderLogItem::ITEM_DELETED,
			$packageItem->cartItem->productName . ' | ' . $packageItem->amount . ' ks',
			$admin,
		);

		$this->redirect('this');
	}

	public function handleCloneOrder(string $orderId): void
	{
		/** @TODO not working */

		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId, true);

		$this->checkoutManager->deleteCart();
		$this->checkoutManager->createCart();

		if ($order->purchase->customer && $order->purchase->account) {
			$order->purchase->customer->setAccount($order->purchase->account);
			$this->shopper->setCustomer($order->purchase->customer);
		} else {
			$this->shopper->setCustomer(null);
			$this->shopper->setCustomerGroup($this->customerGroupRepository->getUnregisteredGroup());
		}

		/** @var \Eshop\DB\Cart $cart */
		$cart = $order->purchase->carts->first();
		$this->checkoutManager->addItemsFromCart($cart);

		$purchase = $this->checkoutManager->syncPurchase($order->purchase->toArray());
		$this->checkoutManager->createOrder($purchase);

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $this->admin->getIdentity();

		if (!$admin) {
			return;
		}

		$this->orderLogItemRepository->createLog($order, OrderLogItem::CLONED, null, $admin);

		$this->redirect('this');
	}

	public function handleCancelOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId);

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $this->admin->getIdentity();

		$this->orderRepository->cancelOrder($order, $admin);

		$this->redirect('this');
	}

	public function handleBanOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId);

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $this->admin->getIdentity();

		$this->orderRepository->banOrder($order, $admin);

		$this->redirect('this');
	}

	public function handleUnBanOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId);

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $this->admin->getIdentity();

		$this->orderRepository->unBanOrder($order, $admin);

		$this->redirect('this');
	}

	public function handleCompleteOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId, true);

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $this->admin->getIdentity();

		$this->orderRepository->completeOrder($order, $admin);

		$this->redirect('this');
	}

	public function handleOpenOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId, true);

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $this->admin->getIdentity();

		$this->orderRepository->openOrder($order, $admin);

		$this->redirect('this');
	}

	public function handleReceiveOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId, true);

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $this->admin->getIdentity();

		$this->orderRepository->receiveOrder($order, $admin);

		$this->redirect('this');
	}

	public function handleReceiveAndCompleteOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId, true);

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $this->admin->getIdentity();

		$this->orderRepository->receiveAndCompleteOrder($order, $admin);

		$this->redirect('this');
	}

	public function handleExportCsv(string $orderId): void
	{
		$presenter = $this;
		$object = $this->orderRepository->one($orderId, true);

		$tempFilename = \tempnam($presenter->tempDir, 'csv');
		$this->application->onShutdown[] = function () use ($tempFilename): void {
			try {
				FileSystem::delete($tempFilename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};
		$this->orderRepository->csvExport($object, Writer::createFromPath($tempFilename, 'w+'));
		$response = new FileResponse($tempFilename, "objednavka-$object->code.csv", 'text/csv');
		$presenter->sendResponse($response);
	}

	public function handleExportEdi(string $orderId): void
	{
		$object = $this->orderRepository->one($orderId, true);

		$tempFilename = \tempnam($this->tempDir, 'xml');
		$fh = \fopen($tempFilename, 'w+');
		\fwrite($fh, $this->orderRepository->ediExport($object));
		\fclose($fh);
		$this->application->onShutdown[] = function () use ($tempFilename): void {
			try {
				FileSystem::delete($tempFilename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};
		$this->sendResponse(new FileResponse($tempFilename, 'order.txt', 'text/plain'));
	}

	public function renderSendDPD(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Odeslat do DPD';
		$this->template->headerTree = [
			['Objednávky', 'default',],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('dpdSendForm')];
	}

	public function renderPrintDPD(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Tisk DPD štítků';
		$this->template->headerTree = [
			['Objednávky', 'default',],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('dpdPrintForm')];
	}

	public function renderSendPPL(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Odeslat do PPL';
		$this->template->headerTree = [
			['Objednávky', 'default',],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('pplSendForm')];
	}

	public function renderPauseOrder(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Pozastavit';
		$this->template->headerTree = [
			['Objednávky', 'default',],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('pauseOrderForm')];
	}

	public function renderUnPauseOrder(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Zrušit pozastavení';
		$this->template->headerTree = [
			['Objednávky', 'default',],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('unPauseOrderForm')];
	}

	public function renderPrintPPL(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Tisk PPL štítků';
		$this->template->headerTree = [
			['Objednávky', 'default',],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('pplPrintForm')];
	}

	public function renderExportTargito(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Export pro Targito';
		$this->template->headerTree = [
			['Objednávky', 'default',],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('targitoExportForm')];
	}

	public function createComponentTargitoExportForm(): AdminForm
	{
		Debugger::$showBar = false;

		/** @var \Grid\Datagrid $grid */
		$grid = $this->getComponent('ordersGrid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $grid->getPaginator()->getItemCount();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Exportovat', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
//			'total' => 'vše',
		])->setDefaultValue('selected');

		$form->addSubmit('submit', 'Exportovat');

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $grid): void {
			$values = $form->getValues('array');

			/** @var \StORM\Collection $collection */
			$collection = $values['bulkType'] === 'selected' ? $this->orderRepository->many()->where('uuid', $ids) :
				($values['bulkType'] === 'all' ? $grid->getFilteredSource() : $this->orderRepository->many());

			$tempFilename = \tempnam($this->tempDir, 'csv');

			$this->application->onShutdown[] = function () use ($tempFilename): void {
				try {
					FileSystem::delete($tempFilename);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}
			};

			$this->orderRepository->csvExportTargito(Writer::createFromPath($tempFilename, 'w+'), $collection);

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, 'transactions.csv', 'text/csv'));
		};

		return $form;
	}

	public function renderEHubSendOrders(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Odeslat objednávky - eHUB';
		$this->template->headerTree = [
			['Objednávky', 'default',],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('eHubSendOrdersForm')];
	}

	public function createComponentEHubSendOrdersForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('ordersGrid'), function (array $values, Collection $collection): void {
			$sync = $this->eHub->syncOrders($collection);

			$this->flashMessage($sync ? 'Provedeno' : 'Chyba odesílání!', $sync ? 'success' : 'error');
		}, $this->getBulkFormActionLink(), $this->orderRepository->many(), $this->getBulkFormIds());
	}

	public function createComponentDpdSendForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('ordersGrid'), function (array $values, Collection $collection): void {
			try {
				$result = $this->dpd->syncOrders($collection);

				$msg = 'Odesláno: ' . \count($result['completed']) . '<br>';
				$msg .= 'Přeskočeno: ' . \count($result['ignored']) . '<br>';
				$msg .= 'Chyba: ' . \count($result['failed']) . '<br>';

				foreach ($result['failed'] as $order) {
					$msg .= $order->code . '<br>';
				}

				$this->flashMessage($msg, 'success');
			} catch (\Throwable $e) {
				$this->flashMessage($e->getMessage(), 'error');
			}
		}, $this->getBulkFormActionLink(), $this->orderRepository->many(), $this->getBulkFormIds());
	}

	public function createComponentDpdPrintForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('ordersGrid'), function (array $values, Collection $collection, AdminForm $form): void {
			/** @var \Nette\Forms\Controls\SubmitButton $submitter */
			$submitter = $form->isSubmitted();

			if ($submitter->getName() === 'onlyNotPrinted') {
				$collection->where('this.dpdPrinted', false);
			}

			$individualFiles = [];

			$this->dpd->getLabels($collection, null, $individualFiles);

			$mergedFilename = $this->dpd->mergePdfs($individualFiles);

			$this->flashMessage($mergedFilename ? 'Provedeno' : 'Chyba tisku!', $mergedFilename ? 'success' : 'error');

			if (!$mergedFilename) {
				return;
			}

			$this->sendResponse(new FileResponse($mergedFilename, 'labels.pdf', 'application/pdf'));
		}, $this->getBulkFormActionLink(), $this->orderRepository->many(), $this->getBulkFormIds(), function (AdminForm $form): void {
			$form->addSubmit('onlyNotPrinted', 'Pouze nevytištěné');

			/** @var \Nette\Forms\Controls\SubmitButton $submit */
			$submit = $form['submit'];
			$submit->setCaption('Vše');
		});
	}

	public function createComponentPplSendForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('ordersGrid'), function (array $values, Collection $collection): void {
			try {
				$result = $this->ppl->syncOrders($collection);

				$msg = 'Odesláno: ' . \count($result['completed']) . '<br>';
				$msg .= 'Přeskočeno: ' . \count($result['ignored']) . '<br>';
				$msg .= 'Chyba: ' . \count($result['failed']) . '<br>';

				foreach ($result['failed'] as $order) {
					$msg .= $order->code . '<br>';
				}

				$this->flashMessage($msg, 'success');
			} catch (\Throwable $e) {
				$this->flashMessage($e->getMessage(), 'error');
			}
		}, $this->getBulkFormActionLink(), $this->orderRepository->many(), $this->getBulkFormIds());
	}

	public function createComponentPauseOrderForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('ordersGrid'), function (array $values, Collection $collection): void {
			try {
				foreach ($collection as $order) {
					$this->orderRepository->pauseOrder($order);
				}

				$this->flashMessage('Provedeno', 'success');
			} catch (\Throwable $e) {
				$this->flashMessage($e->getMessage(), 'error');
			}
		}, $this->getBulkFormActionLink(), $this->orderRepository->many(), $this->getBulkFormIds());
	}

	public function createComponentUnPauseOrderForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('ordersGrid'), function (array $values, Collection $collection): void {
			try {
				foreach ($collection as $order) {
					$this->orderRepository->unPauseOrder($order);
				}

				$this->flashMessage('Provedeno', 'success');
			} catch (\Throwable $e) {
				$this->flashMessage($e->getMessage(), 'error');
			}
		}, $this->getBulkFormActionLink(), $this->orderRepository->many(), $this->getBulkFormIds());
	}

	public function createComponentPplPrintForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('ordersGrid'), function (array $values, Collection $collection, AdminForm $form): void {
			/** @var \Nette\Forms\Controls\SubmitButton $submitter */
			$submitter = $form->isSubmitted();

			if ($submitter->getName() === 'onlyNotPrinted') {
				$collection->where('this.pplPrinted', false);
			}

			$filename = $this->ppl->getLabels($collection);

			$this->flashMessage($filename ? 'Provedeno' : 'Chyba tisku!', $filename ? 'success' : 'error');

			if (!$filename) {
				return;
			}

			$this->sendResponse(new FileResponse($filename, 'labels.pdf', 'application/pdf'));
		}, $this->getBulkFormActionLink(), $this->orderRepository->many(), $this->getBulkFormIds(), function (AdminForm $form): void {
			$form->addSubmit('onlyNotPrinted', 'Pouze nevytištěné');

			/** @var \Nette\Forms\Controls\SubmitButton $submit */
			$submit = $form['submit'];
			$submit->setCaption('Vše');
		});
	}

	public function handleMergeOrders(string $targetOrder, array $ids): void
	{
		$connection = $this->orderRepository->getConnection();

		$connection->getLink()->beginTransaction();

		try {
			$this->orderRepository->mergeOrders($this->orderRepository->one($targetOrder), $this->orderRepository->many()->where('this.uuid', $ids)->toArray(), $this->getAdministrator());

			$connection->getLink()->commit();

			$this->flashMessage('Provedeno', 'success');
		} catch (\Throwable $e) {
			Debugger::log($e->getMessage(), ILogger::ERROR);
			$connection->getLink()->rollBack();

			$this->flashMessage('Spojení objednávek se nezdařilo!', 'error');
		}

		$this->redirect('this');
	}

	public function createComponentOrderBulkForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addDatetime('bannedTs', 'Zablokováno')->setNullable();

		if ($this->dpd) {
			$form->addCheckbox('dpdPrinted', 'DPD vytištěno');
		}

		if ($this->ppl) {
			$form->addCheckbox('pplPrinted', 'PPL vytištěno');
		}

		return $form;
	}

	public function handlePauseOrder(string $orderPK): void
	{
		$this->orderRepository->pauseOrder($this->orderRepository->one($orderPK));
	}

	public function handleUnPauseOrder(string $orderPK): void
	{
		$this->orderRepository->unPauseOrder($this->orderRepository->one($orderPK));
	}

	public function handleResetTransport(string $uuid): void
	{
		$order = $this->orderRepository->one(['uuid' => $uuid], true);
		
		if ($this->dpd && $order->dpdCode) {
			$this->dpd->deletePackages([$order->dpdCode]);
		}
		
		$order->update([
			'pplCode' => null,
			'dpdCode' => null,
			'pplError' => false,
			'dpdError' => false,
			'pplPrinted' => false,
			'dpdPrinted' => false,
		]);
		
		
		$this->flashMessage('Poslaní k dopravci bylo resetováno', 'success');
		
		$this->redirect('this');
	}

	protected function startup(): void
	{
		parent::startup();

		$this->dpd = $this->integrations->getService('dpd');
		$this->ppl = $this->integrations->getService('ppl');

		/** @var \Admin\DB\Administrator|null $admin */
		$admin = $this->admin->getIdentity();

		$this->orderRepository->onOrderDeliveryChanged[] = function (Order $order, Delivery $delivery) use ($admin): void {
			$this->orderLogItemRepository->createLog($delivery->order, OrderLogItem::DELIVERY_CHANGED, $delivery->getTypeName(), $admin);

			try {
				$mail = $this->templateRepository->createMessage('order.deliveryChanged', $this->orderRepository->getEmailVariables($order), $delivery->order->purchase->email);
				$this->mailer->send($mail);

				$this->orderLogItemRepository->createLog($delivery->order, OrderLogItem::EMAIL_SENT, OrderLogItem::DELIVERY_CHANGED, $admin);
			} catch (\Throwable $e) {
				Debugger::log($e->getMessage(), ILogger::WARNING);
			}
		};

		$this->orderRepository->onOrderPaymentChanged[] = function (Order $order, Payment $payment) use ($admin): void {
			$this->orderLogItemRepository->createLog($payment->order, OrderLogItem::DELIVERY_CHANGED, $payment->getTypeName(), $admin);

			try {
				$mail = $this->templateRepository->createMessage('order.paymentChanged', $this->orderRepository->getEmailVariables($order), $payment->order->purchase->email);
				$this->mailer->send($mail);

				$this->orderLogItemRepository->createLog($payment->order, OrderLogItem::EMAIL_SENT, OrderLogItem::DELIVERY_CHANGED, $admin);
			} catch (\Throwable $e) {
				Debugger::log($e->getMessage(), ILogger::WARNING);
			}
		};

		$this->orderRepository->onOrderReceived[] = function (Order $order) use ($admin): void {
			try {
				$mail = $this->templateRepository->createMessage('order.received', [
					'orderCode' => $order->code,
				], $order->purchase->email, null, null, $order->purchase->getCustomerPrefferedMutation());

				$this->mailer->send($mail);

				$this->orderLogItemRepository->createLog($order, OrderLogItem::EMAIL_SENT, OrderLogItem::RECEIVED, $admin);
			} catch (\Throwable $e) {
			}
		};

		$this->orderRepository->onOrderCompleted[] = function (Order $order) use ($admin): void {
			try {
				$mail = $this->templateRepository->createMessage('order.confirmed', [
					'orderCode' => $order->code,
				], $order->purchase->email, null, null, $order->purchase->getCustomerPrefferedMutation());

				$this->mailer->send($mail);

				$this->orderLogItemRepository->createLog($order, OrderLogItem::EMAIL_SENT, OrderLogItem::COMPLETED, $admin);
			} catch (\Throwable $e) {
			}
		};

		$this->orderRepository->onOrderCanceled[] = function (Order $order) use ($admin): void {
			try {
				$mail = $this->templateRepository->createMessage(
					'order.canceled',
					['orderCode' => $order->code],
					$order->purchase->email,
					null,
					null,
					$order->purchase->getCustomerPrefferedMutation(),
				);
				$this->mailer->send($mail);

				$this->orderLogItemRepository->createLog($order, OrderLogItem::EMAIL_SENT, OrderLogItem::CANCELED, $admin);
			} catch (\Throwable $e) {
			}
		};

		$this->tab = $this->getTab();
	}

	protected function sendToSuppliersAll(Order $order): void
	{
		unset($order);
	}

	protected function sendToSuppliersOnlyUncompleted(Order $order): void
	{
		unset($order);
	}

	protected function getTab(): string
	{
		return $this->tab ??= ($this->shopper->getEditOrderAfterCreation() ? Order::STATE_OPEN : Order::STATE_RECEIVED);
	}

	protected function getOrderStateName(string $state): ?string
	{
		return $this::CONFIGURATION['orderStates'][$state] ?? $this::ORDER_STATES_NAMES[$state] ?? null;
	}
}
