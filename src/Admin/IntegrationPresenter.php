<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\CategoryRepository;
use Eshop\DB\OrderRepository;
use Eshop\Integration\Algolia;
use Eshop\Integration\MailerLite;
use Eshop\Integration\Zasilkovna;
use Tracy\Debugger;
use Web\DB\ContactItemRepository;
use Web\DB\Setting;
use Web\DB\SettingRepository;

class IntegrationPresenter extends BackendPresenter
{
	protected const CONFIGURATION = [
		'supportBox' => false,
		'targito' => false,
	];
	
	/** @inject */
	public SettingRepository $settingsRepo;
	
	/** @inject */
	public ContactItemRepository $contactItemRepo;
	
	/** @inject */
	public Zasilkovna $zasilkovnaProvider;
	
	/** @inject */
	public MailerLite $mailerLite;
	
	/** @inject */
	public OrderRepository $orderRepository;
	
	/** @inject */
	public Algolia $algoliaService;
	
	/** @inject */
	public CategoryRepository $categoryRepository;
	
	public function beforeRender(): void
	{
		parent::beforeRender();
		
		$this->template->tabs = [
			'@default' => 'Měření a nástroje',
			'@zasilkovna' => 'Zásilkovna',
			'@mailerLite' => 'MailerLite',
			'@algolia' => 'Algolia',
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
		
		$form->setDefaults($this->settingsRepo->many()->setIndex('name')->toArrayOf('value'));
	}
	
	public function actionAlgolia(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('algoliaForm');
		
		$form->setDefaults($this->settingsRepo->many()->setIndex('name')->toArrayOf('value'));
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
		$form->addText('integrationGTM', 'GTM (Google Tag Manager)')->setNullable();
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne(['name' => $key, 'value' => $value]);
			}
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('default');
		};
		
		return $form;
	}
	
	public function actionZasilkovna(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('zasilkovnaForm');
		
		$form->setDefaults($this->settingsRepo->many()->setIndex('name')->toArrayOf('value'));
	}
	
	public function actionSupportbox(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('supportboxForm');
		
		$form->setDefaults($this->settingsRepo->many()->setIndex('name')->toArrayOf('value'));
	}
	
	public function actionTargito(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('targitoForm');
		
		$form->setDefaults($this->settingsRepo->many()->setIndex('name')->toArrayOf('value'));
	}
	
	public function createComponentTargitoForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('targitoDataId', 'data-id')->setNullable();
		$form->addText('targitoDataOrigin', 'data-origin')->setNullable();
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne(['name' => $key, 'value' => $value]);
			}
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('targito');
		};
		
		return $form;
	}
	
	public function createComponentZasilkovnaForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('zasilkovnaApiKey', 'Klíč API')->setNullable();
		$form->addText('zasilkovnaApiPassword', 'Heslo API')->setNullable();
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne(['name' => $key, 'value' => $value]);
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
		
		$form->setDefaults($this->settingsRepo->many()->setIndex('name')->toArrayOf('value'));
	}
	
	public function createComponentMailerLiteForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('mailerLiteApiKey', 'Klíč API')->setNullable();
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne(['name' => $key, 'value' => $value]);
			}
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('mailerLite');
		};
		
		return $form;
	}
	
	public function createComponentSupportboxForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('supportBoxApiKey', 'Klíč API')->setNullable();
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne(['name' => $key, 'value' => $value]);
			}
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('supportBox');
		};
		
		return $form;
	}
	
	public function createComponentAlgoliaForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('algoliaAdminApiKey', 'Admin klíč')->setNullable();
		$form->addText('algoliaSearchApiKey', 'Vyhledávací klíč')->setNullable();
		$form->addText('algoliaApplicationId', 'Id aplikace')->setNullable();
		$form->addSelect2('algoliaCategory', 'Kategorie', $this->categoryRepository->getTreeArrayForSelect())->setPrompt('Všechny');
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne(['name' => $key, 'value' => $value]);
			}
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('algolia');
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
			/** @var \Eshop\DB\Order[] $orders */
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
	
	public function renderAlgolia(): void
	{
		$this->template->headerLabel = 'Integrace';
		$this->template->headerTree = [
			['Integrace'],
			['Algolia'],
		];
		
		$active = $this->checkSetting('algoliaAdminApiKey') &&
			$this->checkSetting('algoliaSearchApiKey') &&
			$this->checkSetting('algoliaApplicationId') &&
			$this->checkSetting('algoliaCategory');
		
		if ($active) {
			$this->template->displayButtons = [$this->createButtonWithClass('syncAlgoliaProducts!', '<i class="fa fa-sync"></i>  Synchronizovat produkty s Algolia', 'btn btn-sm btn-outline-primary')];
		}
		
		$this->template->displayControls = [$this->getComponent('algoliaForm')];
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
	
	public function handleSyncAlgoliaProducts(): void
	{
		try {
			$this->algoliaService->uploadProducts(
				[
					'products' => [
						'properties' => [],
						'mutationalProperties' => ['name'],
					],
				],
				[$this->settingsRepo->getConnection()->getMutation()],
			);
			$this->flashMessage('Provedeno', 'success');
		} catch (\Exception $e) {
			Debugger::log($e->getMessage());
			$this->flashMessage('Chyba! Zkontrolujte API klíč.', 'error');
		}
		
		$this->redirect('this');
	}
	
	private function checkSetting(string $name): ?Setting
	{
		/** @var \Web\DB\Setting|null $setting */
		$setting = $this->settingsRepo->many()->where('name', $name)->first();
		
		return $setting !== null && $setting->getValue('value') !== null && $setting->getValue('value') !== '' ? $setting : null;
	}
}
