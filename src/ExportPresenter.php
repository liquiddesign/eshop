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

	protected const ERROR_MSG = 'Invalid export settings! No pricelist selected! You can set pricelist in admin web settings.';

	public function __construct(Storage $storage)
	{
		parent::__construct();

		$this->cache = new Cache($storage);
	}

	public function beforeRender()
	{
		$this->template->productImageUrl = $this->request->getUrl()->withoutUserInfo()->getBaseUrl() . 'userfiles/' . Product::IMAGE_DIR . '/thumb/';
	}

	private function getPricelistFromSetting(string $settingName): Pricelist
	{
		/** @var \Web\DB\Setting $setting */
		$setting = $this->settingRepo->one(['name' => $settingName]);

		if (!$setting || !$setting->value) {
			throw new \Exception($this::ERROR_MSG);
		}

		if (!$pricelist = $this->priceListRepo->one($setting->value)) {
			throw new \Exception($this::ERROR_MSG);
		}

		return $pricelist;
	}

	private function export(string $name)
	{
		$this->template->setFile(__DIR__ . "/templates/export/$name.latte");

		$this->template->pricelist = $pricelist = $this->getPricelistFromSetting($name . 'ExportPricelist');

		$this->template->products = $this->cache->load("export_$name", function (&$dependencies) use ($pricelist) {
			$dependencies[Cache::TAGS] = ['export', 'categories'];
			$dependencies[Cache::EXPIRE] = '1 day';

			return $this->productRepo->getProducts([$pricelist])->where('this.hidden', false)->toArray();
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
}