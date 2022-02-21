<?php

namespace Eshop;

use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\MerchantRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PhotoRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\VatRateRepository;
use Nette\Application\Responses\FileResponse;
use Nette\Application\UI\Presenter;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Http\IResponse;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Security\AuthenticationException;
use Nette\Utils\FileSystem;
use Security\DB\AccountRepository;
use Web\DB\SettingRepository;

abstract class ExportPresenter extends Presenter
{
	public const ERROR_MSG = 'ERROR: Pricelists not set or other error.';

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
	public Shopper $shopper;

	/** @inject */
	public Request $request;

	/** @inject */
	public Response $response;

	protected Cache $cache;

	public function __construct(Storage $storage)
	{
		parent::__construct();

		$this->cache = new Cache($storage);
	}

	public function beforeRender(): void
	{
		$this->template->configuration = $this::CONFIGURATION;
		$this->template->productImageUrl = $this->request->getUrl()->withoutUserInfo()->getBaseUrl() . 'userfiles/' . Product::GALLERY_DIR . '/origin/';
	}

	public function renderPartnersExport(): void
	{
		$this->template->setFile(__DIR__ . '/templates/export/partners.latte');
		$this->template->vatRates = $this->vatRateRepo->getVatRatesByCountry();
	}

	public function renderHeurekaExport(): void
	{
		try {
			$this->export('heureka');
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

		$pricelists = $this->getPricelistFromSetting('zboziExportPricelist');
		$this->template->products = $this->productRepo->getProducts($pricelists)->where('this.hidden', false);

		try {
			$this->export('zbozi');
		} catch (\Exception $e) {
			$this->template->error = $e->getMessage();
		}
	}

	public function renderGoogleExport(): void
	{
		try {
			$this->export('google');
		} catch (\Exception $e) {
			$this->template->error = $e->getMessage();
		}
	}

	public function actionCustomer(?string $uuid = null): void
	{
		$phpAuthUser = $this->request->getUrl()->getUser();

		if (\strlen($phpAuthUser) === 0 && !$this->user->isLoggedIn() && !$uuid) {
			\Header('WWW-Authenticate: Basic realm="Please, log in."');
			\Header('HTTP/1.0 401 Unauthorized');
			echo "401 Unauthorized\n";
			echo "Please, sign in through this page or login page.\n";
			exit;
		}

		$phpAuthPassword = $this->request->getUrl()->getPassword();

		if (\strlen($phpAuthUser) > 0) {
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
		$this->template->setFile(__DIR__ . '/templates/export/customer.latte');
		$this->template->vatRates = $this->vatRateRepo->getVatRatesByCountry();
	}

	public function handleExportEdi(Order $order): void
	{
		$tmpfname = \tempnam($this->context->parameters['tempDir'], 'xml');
		$fh = \fopen($tmpfname, 'w+');
		\fwrite($fh, $this->orderRepo->ediExport($order));
		\fclose($fh);
		$this->context->getService('application')->onShutdown[] = function () use ($tmpfname): void {
			FileSystem::delete($tmpfname);
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
	public function getPricelistFromSetting(string $settingName): array
	{
		/** @var \Web\DB\Setting|null $setting */
		$setting = $this->settingRepo->one(['name' => $settingName]);

		if (!$setting || !$setting->value) {
			throw new \Exception($this::ERROR_MSG);
		}

		return $this->priceListRepo->many()->where('this.uuid', \explode(';', $setting->value))->toArray();
	}

	private function export(string $name): void
	{
		$currency = $this->currencyRepository->one('CZK');

		$this->template->priceType = $this->shopper->getShowVat() ? true : ($this->shopper->getShowWithoutVat() ? false : null);
		$this->template->deliveryTypes = $this->deliveryTypeRepository->getDeliveryTypes($currency, null, null, null, 0.0, 0.0);
		$this->template->setFile(__DIR__ . "/templates/export/$name.latte");
	}
}
