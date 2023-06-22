<?php
declare(strict_types=1);

namespace Eshop\Front;

use Admin\Administrator;
use Ares\Ares;
use Ares\HttpException;
use Ares\IcNotFoundException;
use Base\DB\ShopRepository;
use Base\ShopsConfig;
use Eshop\DB\CartItem;
use Eshop\DB\NewsletterUserRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\WatcherRepository;
use Eshop\ShopperUser;
use Forms\Form;
use Forms\FormFactory;
use GuzzleHttp\Exception\GuzzleException;
use Latte\Engine;
use Latte\Loaders\StringLoader;
use Latte\Policy;
use Latte\Sandbox\SecurityPolicy;
use Messages\DB\TemplateRepository;
use Nette\Application\Application;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Attributes\Inject;
use Nette\DI\Container;
use Nette\Localization\Translator;
use Nette\Mail\Mailer;
use Nette\Mail\Message;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use PdoDebugger;
use StORM\DIConnection;
use StORM\LogItem;
use Throwable;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\Controls\Breadcrumb;
use Web\Controls\IBreadcrumbFactory;
use Web\Controls\IWidgetFactory;
use Web\Controls\Widget;

abstract class FrontendPresenter extends Presenter
{
	public string $appPath = __DIR__ . '/../../../../../app';

	public string $layoutTemplate = __DIR__ . '/../../../../../app/@layout.latte';

	public Administrator $admin;

	#[Inject]
	public Container $container;

	#[Inject]
	public LatteFactory $latteFactory;

	#[Inject]
	public Translator $translator;

	#[Inject]
	public ShopperUser $shopperUser;

	#[Inject]
	public ProductRepository $productRepository;

	#[Inject]
	public TemplateRepository $templateRepository;

	#[Inject]
	public Mailer $mailer;

	#[Inject]
	public IBreadcrumbFactory $breadcrumbFactory;

	#[Inject]
	public IWidgetFactory $widgetFactory;

	#[Inject]
	public Storage $storage;

	#[Inject]
	public WatcherRepository $watcherRepository;

	#[Inject]
	public NewsletterUserRepository $newsletterUserRepository;

	#[Inject]
	public FormFactory $formFactory;

	#[Inject]
	public ShopsConfig $shopsConfig;

	#[Inject]
	public ShopRepository $shopRepository;

	#[Inject]
	public Application $netteApplication;

	#[Inject]
	public DIConnection $connection;

	/** @persistent */
	public string $lang;

	/** @var array<callable(\Web\Controls\Breadcrumb): void> */
	public $onBreadcrumbCreated = [];

	protected Engine $latte;

	protected string $tempDir;

	protected string $userDir;

	protected string $appDir;

	private Cache $cache;

	/**
	 * @return array<string>
	 */
	public function formatLayoutTemplateFiles(): array
	{
		$dirs = parent::formatLayoutTemplateFiles();
		$dirs[] = $this->layoutTemplate;

		return $dirs;
	}

	public function createComponentWidget(): Widget
	{
		return $this->widgetFactory->create();
	}

	public function handleLogout(): void
	{
		if ($this->shopperUser->getMerchant() && $this->shopperUser->getMerchant()->activeCustomer) {
			$this->shopperUser->getMerchant()->update(['activeCustomer' => null]);
			$this->shopperUser->getMerchant()->update(['activeCustomerAccount' => null]);

			$this->redirect(':Web:Index:default');
		}

		$this->user->logout(true);
		$this->redirect(':Web:Index:default');
	}

	public function handleGetProductsForTypeAhead(): void
	{
		$value = $this->getParameter('value');
		$products = $this->productRepository->getProducts()->where(
			'this.hidden',
			false,
		)->filter(['q' => $value])->setTake(6);
		$result = [];

		/** @var \Eshop\DB\Product $product */
		foreach ($products as $product) {
			if ($product->inStock()) {
				$inStock = $product->displayAmount ? $product->displayAmount->label : $this->translator->translate(
					'productDetail.onRequest',
					'Skladem: na dotaz',
				);
			} else {
				$inStock = $product->displayAmount ? $product->displayAmount->label : $this->translator->translate(
					'productDetail.notInStock',
					'Není skladem',
				);
			}

			$result[$product->getPK()] = [
				'name' => $product->name,
				'uuid' => $product->getPK(),
				'code' => $product->getFullCode(),
				'link' => $this->link(':Eshop:Product:detail', $product->getPK()),
				'inStock' => $inStock,
			];

			if ($this->shopperUser->getCatalogPermission() !== 'price') {
				continue;
			}

			if ($this->shopperUser->getShowVat() && $this->shopperUser->getShowWithoutVat()) {
				$result[$product->getPK()]['price'] = $this->shopperUser->showPriorityPrices() === 'withVat' ?
					$this->shopperUser->filterPrice($product->getPriceVat()) :
					$this->shopperUser->filterPrice($product->getPrice());
			} else {
				if ($this->shopperUser->getShowVat()) {
					$result[$product->getPK()]['price'] = $this->shopperUser->filterPrice($product->getPriceVat());
				}

				if ($this->shopperUser->getShowWithoutVat()) {
					$result[$product->getPK()]['price'] = $this->shopperUser->filterPrice($product->getPrice());
				}
			}
		}

		$this->payload->result = $result;
		$this->sendPayload();
	}

	public function createComponentBreadcrumb(): Breadcrumb
	{
		$breadcrumb = $this->breadcrumbFactory->create();
		$breadcrumb->onAnchor[] = function (Breadcrumb $breadcrumb): void {
			$breadcrumb->template->setFile($this->appPath . '/Web/Controls/Breadcrumb.latte');
		};

		Arrays::invoke($this->onBreadcrumbCreated, $breadcrumb);

		return $breadcrumb;
	}

	public function handleWatchIt(string $product): void
	{
		if ($customer = $this->shopperUser->getCustomer()) {
			$this->watcherRepository->createOne([
				'product' => $product,
				'customer' => $customer,
				'amountFrom' => 1,
				'beforeAmountFrom' => 0,
			]);
		} else {
			$this->flashMessage($this->translator->translate(
				'watcher.signInToAdd',
				'Pro zapnutí hlídání je nutné být přihlášený.',
			), 'info');
			$this->redirect(':Eshop:User:login');
		}

		$this->redirect('this');
		// @TODO call event
	}

	public function handleUnWatchIt(string $product): void
	{
		if ($customer = $this->shopperUser->getCustomer()) {
			$this->watcherRepository->many()
				->where('fk_product', $product)
				->where('fk_customer', $customer)
				->delete();
		}

		$this->redirect('this');
		// @TODO call event
	}

	/**
	 * @throws \Nette\Application\AbortException
	 */
	public function handleLoadAres(): void
	{
		$ic = $this->getHttpRequest()->getPost('ic');

		if (!$ic) {
			$this->sendPayload();
		}

		try {
			$this->getPresenter()->payload->result = Ares::loadDataByIc($ic);
		} catch (HttpException | GuzzleException $e) {
			Debugger::log($e, ILogger::EXCEPTION);

			$this->getPresenter()->getHttpResponse()->setCode(400);
		} catch (IcNotFoundException $e) {
			$this->getPresenter()->getHttpResponse()->setCode(404);
		}

		$this->getPresenter()->sendPayload();
	}

	public function cleanCache(): void
	{
		$this->cache->clean([
			Cache::TAGS => ['menu'],
		]);
	}

	public function createComponentSubscribeToNewsletter(): Form
	{
		$form = $this->formFactory->create();

		$form->addCheckbox('agree')->setDefaultValue(true)->setRequired();
		$form->addText('email')->setRequired()->addRule($form::EMAIL);
		$form->addSubmit('submit');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues();

			$this->newsletterUserRepository->syncOne(['email' => $values['email']]);

			$this->flashMessage($this->translator->translate('.newsletterRegistered', 'Byli jste přihlášeni k odběru.'), 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function compileLatte(?string $string, array $params): ?string
	{
		if ($string === null) {
			return null;
		}

		try {
			return $this->latte->renderToString($string, $params);
		} catch (Throwable $e) {
			return null;
		}
	}

	public function sendMessage(?Message $message): void
	{
		if ($message) {
			$this->mailer->send($message);
		}
	}

	public function afterRender(): void
	{
		\Tracy\Debugger::$maxLength = 100000;

		$this->netteApplication->onShutdown[] = function (): void {
			if ($this->container->getParameters()['debugMode'] === true) {
				$logItems = $this->connection->getLog();

				\uasort($logItems, function (LogItem $a, LogItem $b): int {
					return $b->getTotalTime() <=> $a->getTotalTime();
				});

				$totalTime = 0;
				$totalAmount = 0;

				$logItems = \array_filter($logItems, function (LogItem $item) use (&$totalTime, &$totalAmount): bool {
					$totalTime += $item->getTotalTime();
					$totalAmount += $item->getAmount();

					return $item->getTotalTime() > 0.1;
				});

				Debugger::dump($totalTime);
				Debugger::dump($totalAmount);

				foreach ($logItems as $logItem) {
					Debugger::dump($logItem);
					Debugger::dump(PdoDebugger::show($logItem->getSql(), $logItem->getVars()));
				}
			}
		};
	}

	protected function startup(): void
	{
		parent::startup();

		$this->tempDir = $this->container->getParameters()['tempDir'];
		$this->userDir = $this->container->getParameters()['wwwDir'] . '/userfiles';
		$this->appDir = $this->container->getParameters()['appDir'];

		$this->latte = $this->createLatteEngine();

		if ($preferredMutation = $this->shopperUser->getUserPreferredMutation()) {
			$this->templateRepository->setMutation($preferredMutation);
		}

		$this->cache = new Cache($this->storage);

		$this->shopperUser->getCheckoutManager()->onCartItemCreate[] = function (CartItem $cartItem): void {
			$this->setCartChanged();
		};

		$this->shopperUser->getCheckoutManager()->onCartItemDelete[] = function (): void {
			$this->setCartChanged();
		};

		$this->shopperUser->getCheckoutManager()->onCartItemUpdate[] = function (): void {
			$this->setCartChanged();
		};

		if (!$this->shopperUser->isIntegrationsEHub() || (!$eHub = $this->getParameter('ehub'))) {
			return;
		}

		$this->getSession()->getSection('frontend')->set('ehub', $eHub);
	}

	protected function beforeRender(): void
	{
		parent::beforeRender();

		$session = $this->getSession()->getSection('frontend');
		$this->template->cartChanged = false;

		if ($session->get('cartChanged')) {
			$this->template->cartChanged = $session->get('cartChanged');
			$session->remove('cartChanged');
		}

		if ($session->get('loggedIn')) {
			$this->template->loggedIn = false;
			$session->remove('loggedIn');
		}

		if (!$session->get('ordered')) {
			return;
		}

		$this->template->ordered = false;
		$session->remove('ordered');
	}

	protected function setCartChanged(): void
	{
		$this->getSession()->getSection('frontend')->set('cartChanged', true);
	}

	protected function setLoggedIn(): void
	{
		$this->getSession()->getSection('frontend')->set('loggedIn', true);
	}

	protected function setOrdered(): void
	{
		$this->getSession()->getSection('frontend')->set('ordered', true);
	}

	protected function createLatteEngine(): Engine
	{
		$latte = $this->latteFactory->create();

		$latte->addExtension(new UIExtension(null));
		$latte->setLoader(new StringLoader());
		$latte->setPolicy($this->getLatteSecurityPolicy());
		$latte->setSandboxMode();

		$latte->addFilter('firstLower', function (string $s): string {
			return Strings::firstLower($s);
		});

		return $latte;
	}

	protected function getLatteSecurityPolicy(): Policy
	{
		$policy = SecurityPolicy::createSafePolicy();
		$policy->allowFilters(['price', 'date']);

		return $policy;
	}
}
