<?php

namespace Eshop;

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
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Http\Request;
use Security\DB\AccountRepository;
use Web\DB\Setting;
use Web\DB\SettingRepository;

abstract class ExportPresenter extends \Nette\Application\UI\Presenter
{
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
	public Shopper $shopper;

	/** @inject */
	public Request $request;

	protected Cache $cache;

	public function __construct(Storage $storage)
	{
		parent::__construct();

		$this->cache = new Cache($storage);
	}

	public function beforeRender()
	{
		$this->template->productImageUrl = $this->request->getUrl()->getBaseUrl() . 'userfiles/' . Product::IMAGE_DIR . '/thumb/';
	}

	public function renderPartnersExport(): void
	{
		$this->template->setFile(__DIR__ . '/templates/export/partners.latte');

		$products = $this->cache->load('export_products', function (&$dependencies) {
			$dependencies[Cache::EXPIRE] = '1 day';
			$dependencies[Cache::TAGS] = ['export', 'categories'];

			return $this->productRepo->getProducts($this->priceListRepo->getAllPricelists())->where('this.hidden', false)->toArray();
		});

		$this->template->vatRates = $this->vatRateRepo->getVatRatesByCountry();
		$this->template->products = $products;
	}

	public function renderHeurekaExport(): void
	{
		$this->template->setFile(__DIR__ . '/templates/export/heureka.latte');

		/** @var Setting $setting */
		$setting = $this->settingRepo->one(['name' => 'heurekaExportPricelist']);

		if (!$setting || !$setting->value) {
			$this->template->error = 'Invalid export settings! No pricelist selected! You can set pricelist in admin web settings.';

			return;
		}

		$pricelist = $this->priceListRepo->one($setting->value);

		if (!$pricelist) {
			$this->template->error = 'Invalid export settings! No pricelist selected! You can set pricelist in admin web settings.';

			return;
		}

		$this->template->pricelist = $pricelist;

		$products = $this->cache->load('export_heureka', function (&$dependencies) use ($pricelist) {
			$dependencies[Cache::TAGS] = ['export', 'categories'];
			$dependencies[Cache::EXPIRE] = '1 day';

			return $this->productRepo->getProducts([$pricelist])->where('this.hidden', false)->toArray();
		});

		$this->template->products = $products;
	}

	public function renderZboziExport(): void
	{
		$this->template->setFile(__DIR__ . '/templates/export/zbozi.latte');

		/** @var Setting $setting */
		$setting = $this->settingRepo->one(['name' => 'zboziExportPricelist']);

		if (!$setting || !$setting->value) {
			$this->template->error = 'Invalid export settings! No pricelist selected! You can set pricelist in admin web settings.';

			return;
		}

		/** @var Pricelist $pricelist */
		$pricelist = $this->priceListRepo->one($setting->value);

		if (!$pricelist) {
			$this->template->error = 'Invalid export settings! No pricelist selected! You can set pricelist in admin web settings.';

			return;
		}

		$this->template->pricelist = $pricelist;

		$products = $this->cache->load('export_zbozi', function (&$dependencies) use ($pricelist) {
			$dependencies[Cache::TAGS] = ['export', 'categories'];
			$dependencies[Cache::EXPIRE] = '1 day';

			return $this->productRepo->getProducts([$pricelist])->where('this.hidden', false)->toArray();
		});

		$this->template->products = $products;
	}

	public function renderGoogleExport(): void
	{
		$this->template->setFile(__DIR__ . '/templates/export/google.latte');

		/** @var Setting $setting */
		$setting = $this->settingRepo->one(['name' => 'googleExportPricelist']);

		if (!$setting || !$setting->value) {
			$this->template->error = 'Invalid export settings! No pricelist selected! You can set pricelist in admin web settings.';

			return;
		}

		/** @var Pricelist $pricelist */
		$pricelist = $this->priceListRepo->one($setting->value);

		if (!$pricelist) {
			$this->template->error = 'Invalid export settings! No pricelist selected! You can set pricelist in admin web settings.';

			return;
		}

		$this->template->pricelist = $pricelist;

		$products = $this->cache->load('export_google', function (&$dependencies) use ($pricelist) {
			$dependencies[Cache::TAGS] = ['export', 'categories'];
			$dependencies[Cache::EXPIRE] = '1 day';

			return $this->productRepo->getProducts([$pricelist])->where('this.hidden', false)->toArray();
		});

		$this->template->products = $products;
	}

	public function actionSupplier(string $uuid)
	{
		if (!isset($_SERVER['PHP_AUTH_USER']) && !$this->user->isLoggedIn()) {
			\Header("WWW-Authenticate: Basic realm=\"Please, log in.\"");
			\Header("HTTP/1.0 401 Unauthorized");
			echo "401 Unauthorized\n";
			echo "Please, sign in through this page or admin page.\n";
			exit;
		}

		if (isset($_SERVER['PHP_AUTH_USER'])) {
			$this->user->login($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], \Admin\DB\Administrator::class);
		}

		/** @var Customer $customer */
		$customer = $this->customerRepo->one($uuid);

		/** @var Merchant $merchant */
		$merchant = $this->merchantRepo->one($uuid);

		if (!$customer && !$merchant) {
			$this->template->error = 'User not found!';

			return;
		}

		$this->template->products = $this->productRepo->getProducts(null, ($customer ?? $merchant));
	}

	public function renderSupplier(string $uuid)
	{
		$this->template->setFile(__DIR__ . '/templates/export/supplier.latte');
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
}