<?php

namespace Eshop;

use Eshop\DB\CatalogPermission;
use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Merchant;
use Eshop\DB\MerchantRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\Pricelist;
use Eshop\DB\PricelistRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\VatRateRepository;
use Nette\Application\Responses\FileResponse;
use Nette\Application\Responses\JsonResponse;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Http\IResponse;
use Nette\Http\Request;
use Security\DB\Account;
use Security\DB\AccountRepository;
use Web\DB\Setting;
use Web\DB\SettingRepository;

abstract class ExportPresenter extends \Nette\Application\UI\Presenter
{
	protected const CONFIGURATION = [
		'customLabel_1' => false,
		'customLabel_2' => false
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
	public MerchantRepository $merchantRepo;

	/** @inject */
	public AccountRepository $accountRepo;

	/** @inject */
	public OrderRepository $orderRepo;

	/** @inject */
	public VatRateRepository $vatRateRepo;

	/** @inject */
	public CatalogPermissionRepository $catalogPermRepo;

	/** @inject */
	public Shopper $shopper;

	/** @inject */
	public Request $request;

	protected Cache $cache;

	protected const ERROR_MSG = 'Invalid export settings! No price list selected! You can set price lists in admin web settings.';

	public function __construct(Storage $storage)
	{
		parent::__construct();

		$this->cache = new Cache($storage);
	}

	public function beforeRender()
	{
		$this->template->configuration = static::CONFIGURATION;
		$this->template->productImageUrl = $this->request->getUrl()->withoutUserInfo()->getBaseUrl() . 'userfiles/' . Product::IMAGE_DIR . '/thumb/';
	}

	/**
	 * @param string $settingName
	 * @return Pricelist[]
	 * @throws \StORM\Exception\NotFoundException
	 */
	private function getPricelistFromSetting(string $settingName): array
	{
		/** @var \Web\DB\Setting $setting */
		$setting = $this->settingRepo->one(['name' => $settingName]);

		if (!$setting || !$setting->value) {
			throw new \Exception($this::ERROR_MSG);
		}

		$pricelistKeys = \explode(';', $setting->value);

		if (\count($pricelistKeys) == 0) {
			throw new \Exception($this::ERROR_MSG);
		}

		return $this->priceListRepo->many()->where('this.uuid', $pricelistKeys)->toArray();
	}

	private function export(string $name)
	{
		$this->template->setFile(__DIR__ . "/templates/export/$name.latte");

		$this->template->pricelists = $pricelists = $this->getPricelistFromSetting($name . 'ExportPricelist');

		$this->template->products = $this->cache->load("export_$name", function (&$dependencies) use ($pricelists) {
			$dependencies[Cache::TAGS] = ['export', 'categories'];
			$dependencies[Cache::EXPIRE] = '1 day';

			return $this->productRepo->getProducts($pricelists)->where('this.hidden', false)->toArray();
		});
	}

	public function renderPartnersExport(): void
	{
		$this->template->setFile(__DIR__ . '/templates/export/partners.latte');

		$this->template->products = $this->cache->load('export_partners', function (&$dependencies) {
			$dependencies[Cache::EXPIRE] = '1 day';
			$dependencies[Cache::TAGS] = ['export', 'categories'];

			return $this->productRepo->getProducts($this->priceListRepo->getAllPricelists())->where('this.hidden', false)->toArray();
		});

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

	public function actionCustomer(?string $uuid = null)
	{
		if (!isset($_SERVER['PHP_AUTH_USER']) && !$this->user->isLoggedIn() && !$uuid) {
			\Header("WWW-Authenticate: Basic realm=\"Please, log in.\"");
			\Header("HTTP/1.0 401 Unauthorized");
			echo "401 Unauthorized\n";
			echo "Please, sign in through this page or login page.\n";
			exit;
		}

		if (isset($_SERVER['PHP_AUTH_USER'])) {
			$this->user->login($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], Customer::class);
		}

		if (!$uuid) {
			$uuid = $this->user->getIdentity()->getPK();
		}

		/** @var Customer $customer */
		$customer = $this->customerRepo->one($uuid);

		/** @var Merchant $merchant */
		$merchant = $this->merchantRepo->one($uuid);

		if (!$customer && !$merchant) {
			$this->template->error = 'User not found!';

			return;
		}

		$this->template->products = $this->cache->load("export_customer_$uuid", function (&$dependencies) use ($customer, $merchant) {
			$dependencies[Cache::TAGS] = ['export', 'categories'];
			$dependencies[Cache::EXPIRE] = '1 day';

			return $this->productRepo->getProducts(null, ($customer ?? $merchant))->toArray();
		});
	}

	public function renderCustomer(?string $uuid = null)
	{
		$this->template->setFile(__DIR__ . '/templates/export/customer.latte');
		$this->template->vatRates = $this->vatRateRepo->getVatRatesByCountry();
	}

	public function handleExportEdi(Order $order)
	{
		$tmpfname = tempnam($this->context->parameters['tempDir'], "xml");
		$fh = fopen($tmpfname, 'w+');
		fwrite($fh, $this->orderRepo->ediExport($order));
		fclose($fh);
		$this->context->getService('application')->onShutdown[] = function () use ($tmpfname) {
			unlink($tmpfname);
		};
		$this->sendResponse(new FileResponse($tmpfname, 'order.txt', 'text/plain'));
	}

	public function actionSupportbox()
	{
		/** @var Setting $setting */
		$setting = $this->settingRepo->many()->where('name', 'supportBoxApiKey')->first(true);

		$auth = $this->getHttpRequest()->getHeader('Authorization');

		if (!$auth || $auth !== 'Basic ' . $setting->value) {
			$this->error('Auth error!', IResponse::S401_UNAUTHORIZED);
		}

		$email = $this->getParameter('email');

		if (!$email) {
			$this->error('Email parameter not found!');
		}

		/** @var Customer $customer */
		$customer = $this->customerRepo->many()->where('email', $email)->first();

		$account = null;

		if (!$customer) {
			/** @var Account $account */
			$account = $this->accountRepo->many()->where('login', $email)->first();

			if (!$account) {
				$this->error('User not found!');
			}

			/** @var CatalogPermission $perm */
			$perm = $this->catalogPermRepo->many()->where('fk_account', $account->getPK())->first();

			if (!$perm) {
				$this->error('Invalid account found!');
			}

			$customer = $perm->customer;
		}

		/** @var Order[] $orders */
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
				'completed_at' => $order->completedTs, //'2015-08-27T13:56:16.000+02:00'
				'edit_url' => $this->link(':Eshop:Admin:Order:printDetail', $order),
				'order_items' => $orderItems,
			];
		}

		$this->sendJson($data);
	}
}