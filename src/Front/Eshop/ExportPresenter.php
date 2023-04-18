<?php

declare(strict_types=1);

namespace Eshop\Front\Eshop;

use Eshop\Admin\SettingsPresenter;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\InvoiceRepository;
use Eshop\DB\MerchantRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\PhotoRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\VatRateRepository;
use Eshop\DevelTools;
use Eshop\ShopperUser;
use Latte\Engine;
use Latte\Loaders\StringLoader;
use Latte\Policy;
use Latte\Sandbox\SecurityPolicy;
use League\Csv\Writer;
use Nette\Application\AbortException;
use Nette\Application\Application;
use Nette\Application\Responses\FileResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Bridges\ApplicationLatte\UIMacros;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Container;
use Nette\Http\IResponse;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Security\AuthenticationException;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use Security\DB\AccountRepository;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\PageRepository;
use Web\DB\SettingRepository;

abstract class ExportPresenter extends Presenter
{
	public const ERROR_MSG = 'Pricelists not set or other error.';

	protected const CONFIGURATION = [
		'customLabel_1' => false,
		'customLabel_2' => false,
	];

	/** @inject */
	public ProductRepository $productRepo;

	/** @inject */
	public SettingRepository $settingRepo;

	/** @inject */
	public PricelistRepository $priceListRepo;

	/** @inject */
	public CustomerRepository $customerRepo;

	/** @inject */
	public CategoryRepository $categoryRepository;

	/** @inject */
	public PriceRepository $priceRepository;

	/** @inject */
	public MerchantRepository $merchantRepo;

	/** @inject */
	public AccountRepository $accountRepo;

	/** @inject */
	public OrderRepository $orderRepo;

	/** @inject */
	public VatRateRepository $vatRateRepo;

	/** @inject */
	public DeliveryTypeRepository $deliveryTypeRepository;

	/** @inject */
	public CatalogPermissionRepository $catalogPermRepo;

	/** @inject */
	public CurrencyRepository $currencyRepository;

	/** @inject */
	public AttributeRepository $attributeRepository;

	/** @inject */
	public AttributeValueRepository $attributeValueRepository;

	/** @inject */
	public PhotoRepository $photoRepository;
	
	/** @inject */
	public PageRepository $pageRepository;

	/** @inject */
	public InvoiceRepository $invoiceRepository;

	/** @inject */
	public CustomerRepository $customerRepository;

	/** @inject */
	public Application $application;

	/** @inject */
	public Container $context;

	/** @inject */
	public ShopperUser $shopperUser;

	/** @inject */
	public Request $request;

	/** @inject */
	public Response $response;

	/** @inject */
	public LatteFactory $latteFactory;
	
	/** @inject */
	public CustomerGroupRepository $customerGroupRepository;

	/** @inject */
	public AttributeAssignRepository $attributeAssignRepository;

	/** @inject */
	public ProducerRepository $producerRepository;

	/** @inject */
	public PaymentTypeRepository $paymentTypeRepository;

	protected Cache $cache;

	protected Engine $latte;

	public function __construct(Storage $storage)
	{
		parent::__construct();

		$this->cache = new Cache($storage);
	}

	public function compileLatte(?string $string, array $params): ?string
	{
		$this->latte ??= $this->createLatteEngine();

		if ($string === null) {
			return null;
		}

		try {
			return $this->latte->renderToString($string, $params);
		} catch (\Throwable $e) {
			return null;
		}
	}

	public function beforeRender(): void
	{
		$this->template->configuration = $this::CONFIGURATION;
		$this->template->productImageUrl = $this->request->getUrl()->withoutUserInfo()->getHostUrl();
	}

	public function renderPartnersExport(): void
	{
		$this->template->setFile(__DIR__ . '/../../templates/export/partners.latte');
		$this->template->vatRates = $this->vatRateRepo->getVatRatesByCountry();
	}

	public function renderHeurekaExport(): void
	{
		try {
			$this->template->categoriesMapWithHeurekaCategories = $this->categoryRepository->getCategoriesMapWithHeurekaCategories($this->categoryRepository->many()->where('fk_type', 'main'));

			$this->export('heureka');
		} catch (\Exception $e) {
			$this->template->error = $e->getMessage();
		}
	}

	public function renderTargitoProductsExport(): void
	{
		try {
			$pricelists = $this->getPricelistFromSetting('targitoExportPricelist', false);
			//$flavourRelationTypeSetting = $this->settingRepo->getValueByName('flavourRelationType');
			
			$products = $pricelists !== null && \count($pricelists) ? $this->productRepo->getProducts($pricelists) : $this->productRepo->getProductsAsCustomer(null);
			$products->where('this.hidden', false);
			
			$this->template->products = $products;
			
			$this->export('targito');
		} catch (\Exception $e) {
			$this->template->error = $e->getMessage();
		}
	}

	public function renderZboziExport(): void
	{
		$mutationSuffix = $this->attributeRepository->getConnection()->getMutationSuffix();
		$this->template->allAttributes = $this->attributeRepository->many()->select(['zbozi' => "IFNULL(zboziName,name$mutationSuffix)"])->toArrayOf('zbozi');
		$this->template->allAttributeValues = $this->attributeValueRepository->many()->select(['zbozi' => "IFNULL(zboziLabel,label$mutationSuffix)"])->toArrayOf('zbozi');

		/** @var \Web\DB\Setting|null $groupIdRelationType */
		$groupIdRelationType = $this->settingRepo->many()->where('name', 'zboziGroupRelation')->first();

		$this->template->groupIdMasterProducts = $groupIdRelationType ?
			$this->productRepo->many()->join(['rel' => 'eshop_related'], 'rel.fk_master = this.uuid')
				->setIndex('rel.fk_slave')
				->where('rel.fk_type', $groupIdRelationType->value)
				->toArray() : [];

		$pricelists = $this->getPricelistFromSetting('zboziExportPricelist', false);
		$groupAfterRegistration = $this->customerGroupRepository->getDefaultRegistrationGroup() ?: $this->customerGroupRepository->getUnregisteredGroup();

		$this->template->products = $pricelists ?
			$this->productRepo->getProducts($pricelists)->where('this.hidden', false) : $this->productRepo->getProductsAsGroup($groupAfterRegistration)->where('this.hidden', false);

		$this->template->pricelists = $pricelists ?: $groupAfterRegistration->defaultPricelists->toArray();

		try {
			$this->export('zbozi');
		} catch (\Exception $e) {
			$this->template->error = $e->getMessage();
		}
	}

	public function renderGoogleExport(): void
	{
		/** @var \Web\DB\Setting|null $discountPricelist */
		$discountPricelist = $this->settingRepo->many()->where('name', 'googleSalePricelist')->first();
		$this->template->groupAfterRegistration = $this->customerGroupRepository->getDefaultRegistrationGroup() ?: $this->customerGroupRepository->getUnregisteredGroup();
		
		$this->template->discountPrices = $discountPricelist && $discountPricelist->value ?
			$this->priceRepository->many()
				->where('fk_pricelist', $discountPricelist->value)
				->setIndex('this.fk_product')
				->toArray() :
			[];

		try {
			$this->export('google');
		} catch (\Exception $e) {
			$this->template->error = $e->getMessage();
		}
	}

	public function actionCategoriesTargito(): void
	{
		try {
			$tempFilename = \tempnam($this->context->parameters['tempDir'], 'csv');

			$this->application->onShutdown[] = function () use ($tempFilename): void {
				try {
					FileSystem::delete($tempFilename);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}
			};

			/** @var \Web\DB\Setting|null $categoryTypeSetting */
			$categoryTypeSetting = $this->settingRepo->getValueByName('heurekaCategoryTypeToParse');

			if (!$categoryTypeSetting) {
				throw new \Exception('Missing Heureka category type setting!');
			}

			$this->categoryRepository->csvExportTargito(Writer::createFromPath($tempFilename, 'w+'), $this->categoryRepository->many()->where('this.fk_type', $categoryTypeSetting));

			$this->getPresenter()->sendResponse(new FileResponse($tempFilename, 'categories.csv', 'text/csv'));
		} catch (\Exception $e) {
			if ($e instanceof AbortException) {
				throw $e;
			}

			$this->sendResponse(new TextResponse($e->getMessage()));
		}
	}

	public function actionCustomer(?string $uuid = null): void
	{
		$phpAuthUser = $this->request->getUrl()->getUser();

		if (Strings::length($phpAuthUser) === 0 && !$this->user->isLoggedIn() && !$uuid) {
			\Header('WWW-Authenticate: Basic realm="Please, log in."');
			\Header('HTTP/1.0 401 Unauthorized');
			echo "401 Unauthorized\n";
			echo "Please, sign in through this page or login page.\n";
			exit;
		}

		$phpAuthPassword = $this->request->getUrl()->getPassword();

		if (Strings::length($phpAuthUser) > 0) {
			try {
				$this->user->login($phpAuthUser, $phpAuthPassword, Customer::class);
			} catch (AuthenticationException $e) {
				\Header('WWW-Authenticate: Basic realm="Invalid login."');
				\Header('HTTP/1.0 401 Unauthorized');
				echo "401 Unauthorized\n";
				echo "Invalid login\n";
				exit;
			}
		}

		if (!$uuid) {
			/** @var \Eshop\DB\Customer|\Eshop\DB\Merchant $customer */
			$customer = $this->getUser()->getIdentity();
			$uuid = $customer->getPK();
		}

		$customer = $this->customerRepo->one($uuid);
		$merchant = $this->merchantRepo->one($uuid);

		if ($customer || $merchant) {
			return;
		}

		$this->template->error = 'User not found!';
	}

	public function renderCustomer(): void
	{
		$this->template->setFile(__DIR__ . '/../../templates/export/customer.latte');
		$this->template->vatRates = $this->vatRateRepo->getVatRatesByCountry();
	}

	public function handleExportEdi(Order $order): void
	{
		$tmpfname = \tempnam($this->context->parameters['tempDir'], 'xml');
		$fh = \fopen($tmpfname, 'w+');
		\fwrite($fh, $this->orderRepo->ediExport($order));
		\fclose($fh);

		$this->context->getService('application')->onShutdown[] = function () use ($tmpfname): void {
			try {
				FileSystem::delete($tmpfname);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};
		$this->sendResponse(new FileResponse($tmpfname, 'order.txt', 'text/plain'));
	}

	public function actionSupportbox(): void
	{
		/** @var \Web\DB\Setting $setting */
		$setting = $this->settingRepo->many()->where('name', 'supportBoxApiKey')->first(true);

		$auth = $this->getHttpRequest()->getHeader('Authorization');

		if (!$auth || $auth !== 'Basic ' . $setting->value) {
			$this->error('Auth error!', IResponse::S401_UNAUTHORIZED);
		}

		$email = $this->getParameter('email');

		if (!$email) {
			$this->error('Email parameter not found!');
		}

		/** @var \Eshop\DB\Customer|null $customer */
		$customer = $this->customerRepo->many()->where('email', $email)->first();

		$account = null;

		if (!$customer) {
			/** @var \Security\DB\Account|null $account */
			$account = $this->accountRepo->many()->where('login', $email)->first();

			if (!$account) {
				$this->error('User not found!');
			}

			/** @var \Eshop\DB\CatalogPermission|null $perm */
			$perm = $this->catalogPermRepo->many()->where('fk_account', $account->getPK())->first();

			if (!$perm) {
				$this->error('Invalid account found!');
			}

			$customer = $perm->customer;
		}

		/** @var array<\Eshop\DB\Order> $orders */
		$orders = $this->orderRepo->getOrdersByUser($customer);

		$data = [
			'email' => $email,
			'first_name' => $account && $account->fullname ? $account->fullname : $customer->fullname,
			'last_name' => null,
			'phone' => $customer->phone,
			'street' => $customer->billAddress ? $customer->billAddress->street : null,
			'city' => $customer->billAddress ? $customer->billAddress->city : null,
			'zip' => $customer->billAddress ? $customer->billAddress->zipcode : null,
			'company_name' => $customer->company,
			'company_ico' => $customer->ic,
			'company_dic' => $customer->dic,
			'orders' => [],
		];

		foreach ($orders as $order) {
			$orderItems = [];

			foreach ($order->purchase->getItems() as $cartItem) {
				$orderItems[] = [
					'price' => $cartItem->getPriceVatSum(),
					'name' => $cartItem->productName,
				];
			}

			$data['orders'][] = [
				'number' => $order->code,
				'total' => $order->getTotalPriceVat(),
				'state' => $this->orderRepo->getState($order),
				//'2015-08-27T13:56:16.000+02:00'
				'completed_at' => $order->completedTs,
				'edit_url' => $this->link(':Eshop:Admin:Order:printDetail', $order),
				'order_items' => $orderItems,
			];
		}

		$this->sendJson($data);
	}

	/**
	 * @param string $settingName
	 * @return array<\Eshop\DB\Pricelist>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getPricelistFromSetting(string $settingName, bool $required = true): ?array
	{
		/** @var \Web\DB\Setting|null $setting */
		$setting = $this->settingRepo->one(['name' => $settingName]);

		if (!$setting || !$setting->value) {
			if ($required) {
				throw new \Exception($this::ERROR_MSG);
			}

			return null;
		}

		return $this->priceListRepo->many()->where('this.uuid', \explode(';', $setting->value))->toArray();
	}

	public function actionInvoice(string $hash): void
	{
		$this->template->invoice = $invoice = $this->invoiceRepository->one(['hash' => $hash], true);

		$invoice->update(['printed' => true]);

		$this->template->setFile($this->template->getFile() ?: __DIR__ . '/../../templates/export/invoice.latte');
	}

	public function renderInvoice(string $hash): void
	{
		unset($hash);
	}

	public function actionInvoiceMultiple(array $hashes): void
	{
		$this->invoiceRepository->many()->where('this.hash', $hashes)->update(['printed' => true]);
		$this->template->invoices = $this->invoiceRepository->many()->where('this.hash', $hashes)->toArray();

		$this->template->setFile($this->template->getFile() ?: __DIR__ . '/../../templates/export/invoice.multiple.latte');
	}

	public function actionRenderMultiple(array $hashes): void
	{
		unset($hashes);
	}

	public function renderHeurekaV2Export(): void
	{
		$pricelists = $this->getPricelistFromSetting('heurekaExportPricelist');

		if (!$pricelists) {
			$this->sendJson('No pricelists selected!');
		}

		$this->template->products = $this->productRepo->getProducts($pricelists)->where('this.hidden', false)->where('this.unavailable', 0)->toArray();
		$this->template->categoriesMapWithHeurekaCategories = $this->categoryRepository->getCategoriesMapWithHeurekaCategories($this->categoryRepository->many()->where('fk_type', 'main'));

		$mutationSuffix = $this->attributeRepository->getConnection()->getMutationSuffix();
		$this->template->allAttributes = $this->attributeRepository->many()->select(['heureka' => "IFNULL(heurekaName,name$mutationSuffix)"])->toArrayOf('heureka');
		$this->template->allAttributeValues = $this->attributeValueRepository->many()->select(['heureka' => "IFNULL(heurekaLabel,label$mutationSuffix)"])->toArrayOf('heureka');

		$this->template->photos = $this->photoRepository->many()->setGroupBy(['fk_product'])->setIndex('fk_product')->select(['fileNames' => 'GROUP_CONCAT(fileName)'])->toArrayOf('fileNames');

		$czkCurrency = $this->currencyRepository->one('CZK', true);
		$unregisteredGroup = $this->customerGroupRepository->getUnregisteredGroup();

		$this->template->possibleDeliveryTypes = $this->deliveryTypeRepository->getDeliveryTypes(
			$czkCurrency,
			null,
			$unregisteredGroup,
			null,
			0,
			0,
		)->where('this.externalIdHeureka IS NOT NULL')->toArray();

		$codPaymentTypeSettings = $this->settingRepo->getValuesByName(SettingsPresenter::COD_TYPE);

		/** @var \Eshop\DB\DeliveryType $deliveryType */
		foreach ($this->template->possibleDeliveryTypes as $deliveryType) {
			$this->template->possibleDeliveryTypes[$deliveryType->getPK()]->priceVatWithCod = $this->template->possibleDeliveryTypes[$deliveryType->getPK()]->priceVat;
			$allowedPaymentTypes = \array_keys($deliveryType->allowedPaymentTypes->toArray());

			foreach ($allowedPaymentTypes && $codPaymentTypeSettings ? $allowedPaymentTypes : $this->paymentTypeRepository->many() as $paymentId) {
				if (Arrays::contains($codPaymentTypeSettings, $paymentId)) {
					$this->template->possibleDeliveryTypes[$deliveryType->getPK()]->priceVatWithCod += $this->paymentTypeRepository->getPaymentTypes(
						$czkCurrency,
						null,
						$unregisteredGroup,
					)->where('this.uuid', $paymentId)->firstValue('priceVat');
				}
			}
		}

		$this->setProductsFrontendData();

		$this->template->setFile(__DIR__ . '/../../templates/export/heurekaV2.latte');
	}

	public function renderZboziV2Export(): void
	{
		$pricelists = $this->getPricelistFromSetting('zboziExportPricelist');

		if (!$pricelists) {
			$this->sendJson('No pricelists selected!');
		}

		$this->template->products = $this->productRepo->getProducts($pricelists)->where('this.hidden', false)->where('this.unavailable', 0)->toArray();

		$mutationSuffix = $this->attributeRepository->getConnection()->getMutationSuffix();
		$this->template->allAttributes = $this->attributeRepository->many()->select(['zbozi' => "IFNULL(zboziName,name$mutationSuffix)"])->toArrayOf('zbozi');
		$this->template->allAttributeValues = $this->attributeValueRepository->many()->select(['zbozi' => "IFNULL(zboziLabel,label$mutationSuffix)"])->toArrayOf('zbozi');

		/** @var \Web\DB\Setting|null $groupIdRelationType */
		$groupIdRelationType = $this->settingRepo->many()->where('name', 'zboziGroupRelation')->first();

		$this->template->groupIdMasterProducts = $groupIdRelationType ?
			$this->productRepo->many()->join(['rel' => 'eshop_related'], 'rel.fk_master = this.uuid')
				->setIndex('rel.fk_slave')
				->where('rel.fk_type', $groupIdRelationType->value)
				->toArray() : [];

		$this->template->allCategories = $this->categoryRepository->many()
			->setSelect([
				'uuid' => 'this.uuid',
				'ancestor' => 'this.fk_ancestor',
				'name' => "this.name$mutationSuffix",
				'exportZboziCategory' => 'this.fk_exportZboziCategory',
			], [], true)
			->fetchArray(\stdClass::class);

		$this->setProductsFrontendData();

		$this->template->setFile(__DIR__ . '/../../templates/export/zboziV2.latte');
	}

	public function renderGoogleV2Export(): void
	{
		$pricelists = $this->getPricelistFromSetting('googleExportPricelist');

		$this->template->groupAfterRegistration = $groupAfterRegistration = $this->customerGroupRepository->getDefaultRegistrationGroup() ?: $this->customerGroupRepository->getUnregisteredGroup();
		$this->template->products = $this->productRepo->getProducts($pricelists)->where('this.hidden', false)->where('this.unavailable', 0)->toArray();

		$this->template->products = (
			$pricelists ?
				$this->productRepo->getProducts($pricelists) :
				$this->productRepo->getProductsAsGroup($groupAfterRegistration)
		)->where('this.hidden', false)->where('this.unavailable', 0);
		$this->template->pricelists = $pricelists ?: $groupAfterRegistration->defaultPricelists->toArray();
		$this->template->photos = $this->photoRepository->many()->where('this.googleFeed', true)->setIndex('this.fk_product')->toArray();
		$this->template->colorAttribute = $this->settingRepo->many()->where('name', 'googleColorAttribute')->first();
		$this->template->highlightsAttribute = $highlightsAttribute = $this->settingRepo->many()->where('name', 'googleHighlightsAttribute')->first();
		$this->template->highlightsMutation = $this->settingRepo->many()->where('name', 'googleHighlightsMutation')->first();
		$this->template->highlightsAttributeValues = $highlightsAttribute && $highlightsAttribute->value ?
			$this->attributeValueRepository->many()->where('fk_attribute', $highlightsAttribute->value)->toArray() :
			[];

		$mutationSuffix = $this->attributeRepository->getConnection()->getMutationSuffix();
		$this->template->allAttributes = $this->attributeRepository->many()->select(['zbozi' => "IFNULL(zboziName,name$mutationSuffix)"])->toArrayOf('zbozi');
		$this->template->allAttributeValues = $this->attributeValueRepository->many()->select(['zbozi' => "IFNULL(zboziLabel,label$mutationSuffix)"])->toArrayOf('zbozi');
		$this->template->allCategories = $this->categoryRepository->many()
			->setSelect([
				'uuid' => 'this.uuid',
				'ancestor' => 'this.fk_ancestor',
				'name' => "this.name$mutationSuffix",
				'exportGoogleCategory' => 'this.exportGoogleCategory',
				'exportGoogleCategoryId' => 'this.exportGoogleCategoryId',
			], [], true)
			->fetchArray(\stdClass::class);

		/** @var \Web\DB\Setting|null $discountPricelist */
		$discountPricelist = $this->settingRepo->many()->where('name', 'googleSalePricelist')->first();
		$this->template->groupAfterRegistration = $this->customerGroupRepository->getDefaultRegistrationGroup() ?: $this->customerGroupRepository->getUnregisteredGroup();

		$this->template->discountPrices = $discountPricelist && $discountPricelist->value ?
			$this->priceRepository->many()
				->where('fk_pricelist', $discountPricelist->value)
				->setIndex('this.fk_product')
				->toArray() :
			[];

		$this->setProductsFrontendData();

		$this->template->setFile(__DIR__ . '/../../templates/export/googleV2.latte');
	}

	protected function afterRender(): void
	{
		parent::afterRender();

		Debugger::log(DevelTools::getPeakMemoryUsage());
	}

	protected function createLatteEngine(): Engine
	{
		$latte = $this->latteFactory->create();

		/** @phpstan-ignore-next-line @TODO LATTEV3 */
		if (\version_compare(\Latte\Engine::VERSION, '3', '<')) {
			/** @phpstan-ignore-next-line @TODO LATTEV3 */
			UIMacros::install($latte->getCompiler());
		} else {
			$latte->addExtension(new UIExtension(null));
		}

		$latte->setLoader(new StringLoader());
		$latte->setPolicy($this->getLatteSecurityPolicy());
		$latte->setSandboxMode();

		return $latte;
	}

	protected function getLatteSecurityPolicy(): Policy
	{
		$policy = SecurityPolicy::createSafePolicy();
		$policy->allowFilters(['price', 'date']);

		return $policy;
	}

	protected function export(string $name): void
	{
		$currency = $this->currencyRepository->one('CZK');

		$this->template->priceType = $this->shopperUser->getShowVat() ? true : ($this->shopperUser->getShowWithoutVat() ? false : null);
		$this->template->deliveryTypes = $this->deliveryTypeRepository->getDeliveryTypes($currency, null, null, null, 0.0, 0.0)->where('this.exportToFeed', true);
		$this->template->setFile(__DIR__ . "/../../templates/export/$name.latte");
	}

	protected function setProductsFrontendData(): void
	{
		/** @var array<\Eshop\DB\Attribute> $allAttributes */
		$allAttributes = $this->attributeRepository->many()->toArray();
		/** @var array<\Eshop\DB\AttributeValue> $allAttributeValues */
		$allAttributeValues = $this->attributeValueRepository->many()->toArray();
		/** @var array<\Eshop\DB\Producer> $allProducers */
		$allProducers = $this->producerRepository->many()->toArray();
		/** @var array<\Eshop\DB\AttributeAssign> $allAttributeAssigns */
		$allAttributeAssigns = $this->attributeAssignRepository->many()->toArray();

		$attributeAssignsByProducts = [];

		foreach ($allAttributeAssigns as $attributeAssign) {
			$attributeAssignsByProducts[$attributeAssign->getValue('product')][$attributeAssign->getPK()] = $attributeAssign;
		}

		unset($allAttributeAssigns);

		$this->template->productsFrontendData = [];

		/** @var \Eshop\DB\Product $product */
		foreach ($this->template->products as $product) {
			$this->template->productsFrontendData[$product->getPK()] = $product->getSimpleFrontendData();

			$attributeAssigns = $attributeAssignsByProducts[$product->getPK()] ?? [];

			/** @var \Eshop\DB\AttributeAssign $attributeAssign */
			foreach ($attributeAssigns as $attributeAssign) {
				if (!isset($allAttributeValues[$attributeAssign->getValue('value')])) {
					continue;
				}

				$attributeValue = $allAttributeValues[$attributeAssign->getValue('value')];

				if (!isset($allAttributes[$attributeValue->getValue('attribute')])) {
					continue;
				}

				$attribute = $allAttributes[$attributeValue->getValue('attribute')];

				if (isset($this->template->productsFrontendData[$product->getPK()]['attributes'][$attribute->code])) {
					$this->template->productsFrontendData[$product->getPK()]['attributes'][$attribute->code] .= ', ' . $attributeValue->label;
				} else {
					$this->template->productsFrontendData[$product->getPK()]['attributes'][$attribute->code] = $attributeValue->label;
				}
			}

			$this->template->productsFrontendData[$product->getPK()]['producer'] = $product->getValue('producer') && isset($allProducers[$product->getValue('producer')]) ?
				$allProducers[$product->getValue('producer')]->name :
				null;
		}

		$currency = $this->currencyRepository->one('CZK');

		$this->template->priceType = $this->shopperUser->getShowVat() ? true : ($this->shopperUser->getShowWithoutVat() ? false : null);
		$this->template->deliveryTypes = $this->deliveryTypeRepository->getDeliveryTypes($currency, null, null, null, 0.0, 0.0)->where('this.exportToFeed', true);
	}
}
