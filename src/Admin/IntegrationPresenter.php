<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\CategoryRepository;
use Eshop\DB\OrderRepository;
use Eshop\Integration\MailerLite;
use Eshop\Integration\Zasilkovna;
use Nette\Utils\Html;
use Web\DB\ContactItemRepository;
use Web\DB\SettingRepository;

class IntegrationPresenter extends BackendPresenter
{
	public const HEUREKA_API_KEY = 'heurekaApiKey';
	public const ZBOZI_API_KEY = 'zboziApiKey';
	public const ZBOZI_STORE_ID = 'zboziStoreId';

	protected const CONFIGURATION = [
		'supportBox' => false,
		'targito' => false,
	];
	
	#[\Nette\DI\Attributes\Inject]
	public SettingRepository $settingsRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public ContactItemRepository $contactItemRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public Zasilkovna $zasilkovnaProvider;
	
	#[\Nette\DI\Attributes\Inject]
	public MailerLite $mailerLite;
	
	#[\Nette\DI\Attributes\Inject]
	public OrderRepository $orderRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public CategoryRepository $categoryRepository;

	private string|null $shopIcon = null;
	
	public function beforeRender(): void
	{
		parent::beforeRender();
		
		$this->template->tabs = [
			'@default' => 'Měření a nástroje',
			'@zasilkovna' => 'Zásilkovna',
			'@mailerLite' => 'MailerLite',
			'@heureka' => 'Heureka',
			'@zbozi' => 'Zboží',
		];
		
		if (isset($this::CONFIGURATION['supportBox']) && $this::CONFIGURATION['supportBox']) {
			$this->template->tabs['@supportBox'] = 'SupportBox';
		}
		
		if (!isset($this::CONFIGURATION['targito']) || !$this::CONFIGURATION['targito']) {
			return;
		}
		
		$this->template->tabs['@targito'] = 'Targito';
	}
	
	public function actionDefault(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('form');

		$form->setDefaults(
			$this->shopsConfig->filterShopsInShopEntityCollection(
				$this->settingsRepo->many()->setIndex('name'),
				showOnlyEntitiesWithSelectedShops: true
			)->toArrayOf('value')
		);
	}
	
	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Integrace';
		$this->template->headerTree = [
			['Integrace'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function createComponentForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('integrationGTM', Html::fromHtml($this->shopIcon . 'GTM (Google Tag Manager)'))->setNullable();
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne([
					'name' => $key,
					'value' => $value,
					'shop' => $this->shopsConfig->getSelectedShop()?->getPK(),
				]);
			}
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('default');
		};
		
		return $form;
	}
	
	public function actionHeureka(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('heurekaForm');
		
		$form->setDefaults(
			$this->shopsConfig->filterShopsInShopEntityCollection(
				$this->settingsRepo->many()->setIndex('name'),
				showOnlyEntitiesWithSelectedShops: true
			)->toArrayOf('value')
		);
	}

	public function actionZbozi(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('zboziForm');

		$form->setDefaults(
			$this->shopsConfig->filterShopsInShopEntityCollection(
				$this->settingsRepo->many()->setIndex('name'),
				showOnlyEntitiesWithSelectedShops: true
			)->toArrayOf('value')
		);
	}
	
	public function actionZasilkovna(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('zasilkovnaForm');
		
		$form->setDefaults(
			$this->shopsConfig->filterShopsInShopEntityCollection(
				$this->settingsRepo->many()->setIndex('name'),
				showOnlyEntitiesWithSelectedShops: true
			)->toArrayOf('value')
		);
	}
	
	public function actionSupportbox(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('supportboxForm');
		
		$form->setDefaults(
			$this->shopsConfig->filterShopsInShopEntityCollection(
				$this->settingsRepo->many()->setIndex('name'),
				showOnlyEntitiesWithSelectedShops: true
			)->toArrayOf('value')
		);
	}
	
	public function actionTargito(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('targitoForm');
		
		$form->setDefaults(
			$this->shopsConfig->filterShopsInShopEntityCollection(
				$this->settingsRepo->many()->setIndex('name'),
				showOnlyEntitiesWithSelectedShops: true
			)->toArrayOf('value')
		);
	}
	
	public function createComponentTargitoForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('targitoDataId', Html::fromHtml($this->shopIcon . 'data-id'))->setNullable();
		$form->addText('targitoDataOrigin', Html::fromHtml($this->shopIcon . 'data-origin'))->setNullable();
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne([
					'name' => $key,
					'value' => $value,
					'shop' => $this->shopsConfig->getSelectedShop()?->getPK(),
				]);
			}
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('targito');
		};
		
		return $form;
	}
	
	public function createComponentZasilkovnaForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('zasilkovnaApiKey', Html::fromHtml($this->shopIcon . 'Klíč API'))->setNullable();
		$form->addText('zasilkovnaApiPassword', Html::fromHtml($this->shopIcon . 'Heslo API'))->setNullable();
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne([
					'name' => $key,
					'value' => $value,
					'shop' => $this->shopsConfig->getSelectedShop()?->getPK(),
				]);
			}
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('zasilkovna');
		};
		
		return $form;
	}
	
	public function actionMailerLite(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('mailerLiteForm');
		
		$form->setDefaults(
			$this->shopsConfig->filterShopsInShopEntityCollection(
				$this->settingsRepo->many()->setIndex('name'),
				showOnlyEntitiesWithSelectedShops: true
			)->toArrayOf('value')
		);
	}
	
	public function createComponentMailerLiteForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('mailerLiteApiKey', Html::fromHtml($this->shopIcon . 'Klíč API'))->setNullable();
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne([
					'name' => $key,
					'value' => $value,
					'shop' => $this->shopsConfig->getSelectedShop()?->getPK(),
				]);
			}
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('mailerLite');
		};
		
		return $form;
	}
	
	public function createComponentSupportboxForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('supportBoxApiKey', Html::fromHtml($this->shopIcon . 'Klíč API'))->setNullable();
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne([
					'name' => $key,
					'value' => $value,
					'shop' => $this->shopsConfig->getSelectedShop()?->getPK(),
				]);
			}
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('supportBox');
		};
		
		return $form;
	}

	public function createComponentZboziForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText($this::ZBOZI_API_KEY, Html::fromHtml($this->shopIcon . 'API klíč'))->setNullable();
		$form->addText($this::ZBOZI_STORE_ID, Html::fromHtml($this->shopIcon . ' ID provozovny'))->setNullable();

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne([
					'name' => $key,
					'value' => $value,
					'shop' => $this->shopsConfig->getSelectedShop()?->getPK(),
				]);
			}

			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('zbozi');
		};

		return $form;
	}
	
	public function createComponentHeurekaForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText($this::HEUREKA_API_KEY, Html::fromHtml($this->shopIcon . ' API klíč'))->setNullable();
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne([
					'name' => $key,
					'value' => $value,
					'shop' => $this->shopsConfig->getSelectedShop()?->getPK(),
				]);
			}
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('heureka');
		};
		
		return $form;
	}
	
	public function renderSupportbox(): void
	{
		$this->template->headerLabel = 'Integrace';
		$this->template->headerTree = [
			['Integrace'],
			['SupportBox'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('supportboxForm')];
	}
	
	public function renderHeureka(): void
	{
		$this->template->headerLabel = 'Integrace';
		$this->template->headerTree = [
			['Integrace'],
			['Heureka'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('heurekaForm')];
	}

	public function renderZbozi(): void
	{
		$this->template->headerLabel = 'Integrace';
		$this->template->headerTree = [
			['Integrace'],
			['Zboží'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('zboziForm')];
	}
	
	public function renderTargito(): void
	{
		$this->template->headerLabel = 'Integrace';
		$this->template->headerTree = [
			['Integrace'],
			['Targito'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('targitoForm')];
	}
	
	public function renderZasilkovna(): void
	{
		$active = ($setting = $this->settingsRepo->many()->where('name', 'zasilkovnaApiKey')->first()) !== null &&
			$setting->getValue('value') !== null &&
			$setting->getValue('value') !== '' &&
			($setting = $this->settingsRepo->many()->where('name', 'zasilkovnaApiPassword')->first()) !== null &&
			$setting->getValue('value') !== null &&
			$setting->getValue('value') !== '';
		
		$this->template->headerLabel = 'Integrace';
		$this->template->headerTree = [
			['Integrace'],
			['Zásilkovna'],
		];
		
		if ($active) {
			$this->template->displayButtons = [
				'<a href="' . $this->link('syncZasilkovnaPoints!') .
				'" onclick="return confirm(\'Opravdu? Tato operace může trvat až 5 minut.\')">
					<button class="btn btn-sm btn-outline-primary"><i class="fa fa-sync"></i>  Synchronizovat výdejní místa</button></a>',
				$this->createButtonWithClass('syncZasilkovnaOrders!', '<i class="fa fa-sync"></i>  Synchronizovat objednávky', 'btn btn-sm btn-outline-primary'),
			];
		}
		
		$this->template->displayControls = [$this->getComponent('zasilkovnaForm')];
	}
	
	public function handleSyncZasilkovnaPoints(): void
	{
		try {
			$this->zasilkovnaProvider->syncPickupPoints();
			$this->flashMessage('Provedeno', 'success');
		} catch (\Exception $e) {
			$this->flashMessage('Chyba! Zkontrolujte API klíč.', 'error');
		}
		
		$this->redirect('this');
	}
	
	public function handleSyncZasilkovnaOrders(): void
	{
		try {
			/** @var array<\Eshop\DB\Order> $orders */
			$orders = $this->orderRepository->many()
				->where('this.completedTs IS NOT NULL AND this.canceledTs IS NULL')
				->where('purchase.zasilkovnaId IS NOT NULL')
				->where('zasilkovnaCompleted', false)
				->toArray();
			
			$this->zasilkovnaProvider->syncOrders($orders);
			$this->flashMessage('Provedeno', 'success');
		} catch (\Exception $e) {
			$this->flashMessage('Chyba! Zkontrolujte API klíč.', 'error');
		}
		
		$this->redirect('this');
	}
	
	public function renderMailerLite(): void
	{
		$this->template->headerLabel = 'Integrace';
		$this->template->headerTree = [
			['Integrace'],
			['MailerLite'],
		];
		
		$active = ($setting = $this->settingsRepo->many()->where('name', 'mailerLiteApiKey')->first()) !== null &&
			$setting->getValue('value') !== null &&
			$setting->getValue('value') !== '';
		
		if ($active) {
			$this->template->displayButtons = [$this->createButtonWithClass('syncMailerLite!', '<i class="fa fa-sync"></i>  Synchronizovat s MailerLite', 'btn btn-sm btn-outline-primary')];
		}
		
		$this->template->displayControls = [$this->getComponent('mailerLiteForm')];
	}
	
	public function handleSyncMailerLite(): void
	{
		try {
			$this->mailerLite->syncCustomers();
			$this->flashMessage('Provedeno', 'success');
		} catch (\Exception $e) {
			$this->flashMessage('Chyba! Zkontrolujte API klíč.', 'error');
		}
		
		$this->redirect('this');
	}

	protected function startup(): void
	{
		parent::startup();

		$this->shopIcon = $this->shopsConfig->getAvailableShops() ? '<i class="fas fa-store-alt fa-sm mr-1" title="Specifické nastavení pro zvolený obchod"></i>' : null;
	}
}
