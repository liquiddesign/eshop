<?php
declare(strict_types=1);

namespace Eshop\Front;

use Admin\Administrator;
use Ares\Ares;
use Ares\HttpException;
use Ares\IcNotFoundException;
use Eshop\CheckoutManager;
use Eshop\DB\CartItem;
use Eshop\DB\NewsletterUserRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\WatcherRepository;
use Eshop\Shopper;
use Forms\Form;
use Forms\FormFactory;
use GuzzleHttp\Exception\GuzzleException;
use Latte\Engine;
use Latte\Loaders\StringLoader;
use Latte\Policy;
use Latte\Sandbox\SecurityPolicy;
use Messages\DB\TemplateRepository;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Bridges\ApplicationLatte\UIMacros;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Container;
use Nette\Localization\Translator;
use Nette\Mail\Mailer;
use Nette\Mail\Message;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
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

	/** @inject */
	public Container $container;

	/** @inject */
	public LatteFactory $latteFactory;

	/** @inject */
	public Translator $translator;

	/** @inject */
	public Shopper $shopper;

	/** @inject */
	public ProductRepository $productRepository;

	/** @inject */
	public TemplateRepository $templateRepository;

	/** @inject */
	public Mailer $mailer;

	/** @inject */
	public IBreadcrumbFactory $breadcrumbFactory;

	/** @inject */
	public IWidgetFactory $widgetFactory;

	/** @inject */
	public Storage $storage;

	/** @inject */
	public WatcherRepository $watcherRepository;

	/** @inject */
	public NewsletterUserRepository $newsletterUserRepository;

	/** @inject */
	public FormFactory $formFactory;

	/** @inject */
	public CheckoutManager $checkoutManager;

	/** @persistent */
	public string $lang;

	/** @var array<callable(\Web\Controls\Breadcrumb): void> */
	public $onBreadcrumbCreated = [];

	protected Engine $latte;

	protected string $tempDir;

	protected string $userDir;

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
		if ($this->shopper->getMerchant() && $this->shopper->getMerchant()->activeCustomer) {
			$this->shopper->getMerchant()->update(['activeCustomer' => null]);
			$this->shopper->getMerchant()->update(['activeCustomerAccount' => null]);

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

			if ($this->shopper->getCatalogPermission() !== 'price') {
				continue;
			}

			if ($this->shopper->getShowVat() && $this->shopper->getShowWithoutVat()) {
				$result[$product->getPK()]['price'] = $this->shopper->showPriorityPrices() === 'withVat' ?
					$this->shopper->filterPrice($product->getPriceVat()) :
					$this->shopper->filterPrice($product->getPrice());
			} else {
				if ($this->shopper->getShowVat()) {
					$result[$product->getPK()]['price'] = $this->shopper->filterPrice($product->getPriceVat());
				}

				if ($this->shopper->getShowWithoutVat()) {
					$result[$product->getPK()]['price'] = $this->shopper->filterPrice($product->getPrice());
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
		if ($customer = $this->shopper->getCustomer()) {
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
		if ($customer = $this->shopper->getCustomer()) {
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
		} catch (\Throwable $e) {
			\bdump($e);

			return null;
		}
	}

	public function sendMessage(?Message $message): void
	{
		if ($message) {
			$this->mailer->send($message);
		}
	}

	protected function startup(): void
	{
		parent::startup();

		$this->tempDir = $this->container->getParameters()['tempDir'];
		$this->userDir = $this->container->getParameters()['wwwDir'] . '/userfiles';

		$this->latte = $this->createLatteEngine();

		if ($preferredMutation = $this->shopper->getUserPreferredMutation()) {
			$this->templateRepository->setMutation($preferredMutation);
		}

		$this->cache = new Cache($this->storage);

		$this->checkoutManager->onCartItemCreate[] = function (CartItem $cartItem): void {
			$this->setCartChanged();
		};

		$this->checkoutManager->onCartItemDelete[] = function (): void {
			$this->setCartChanged();
		};

		$this->checkoutManager->onCartItemUpdate[] = function (): void {
			$this->setCartChanged();
		};

		if (!$this->shopper->isIntegrationsEHub() || (!$eHub = $this->getParameter('ehub'))) {
			return;
		}

		\bdump('ehub detected and saved to session');

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
