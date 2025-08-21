<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\CategoryRepository;
use Eshop\DB\OrderRepository;
use Eshop\Integration\MailerLite;
use Eshop\Integration\Zasilkovna;
use Eshop\Services\BalikobotApi\DeliveryProviders;
use Forms\Form;
use Nette\Forms\Form as FormAlias;
use Nette\Utils\Html;
use Web\DB\ContactItemRepository;
use Web\DB\SettingRepository;

class IntegrationPresenter extends BackendPresenter
{
	public const HEUREKA_API_KEY = 'heurekaApiKey';
	public const ZBOZI_API_KEY = 'zboziApiKey';
	public const ZBOZI_STORE_ID = 'zboziStoreId';
	public const BALIKOBOT_PROVIDER_ID = 'balikobotProviderId';
	public const BALIKOBOT_CC_ADDRESS = 'balikobotCcAddress';

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
	
	public function beforeRender(): void
	{
		parent::beforeRender();
		
		$this->template->tabs = [
			'@default' => 'Měření a nástroje',
			'@zasilkovna' => 'Zásilkovna',
			'@mailerLite' => 'MailerLite',
			'@heureka' => 'Heureka',
			'@zbozi' => 'Zboží',
			'@balikobot' => 'Balíkobot',
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

		$this->setFormDefaults($form);
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

		$shopsContainer = $form->addContainer('shops');
		$shops = $this->shopsConfig->getAvailableShops();

		foreach ($shops as $shop) {
			$shopContainer = $shopsContainer->addContainer($shop->getPK());

			$shopContainer->addText('integrationGTM', Html::fromHtml($shop->getIconImageFormAdmin() . 'GTM (Google Tag Manager)'))->setNullable();
		}

		if (!$shops) {
			$shopContainer = $shopsContainer->addContainer('default');

			$shopContainer->addText('integrationGTM', Html::fromHtml('GTM (Google Tag Manager)'))->setNullable();
		}
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValuesWithAjax();
			
			$this->saveSettings($values);
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('default');
		};
		
		return $form;
	}
	
	public function actionHeureka(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('heurekaForm');

		$this->setFormDefaults($form);
	}

	public function actionZbozi(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('zboziForm');

		$this->setFormDefaults($form);
	}
	
	public function actionZasilkovna(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('zasilkovnaForm');
		
		$this->setFormDefaults($form);
	}
	
	public function actionSupportbox(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('supportboxForm');
		
		$this->setFormDefaults($form);
	}
	
	public function actionTargito(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('targitoForm');
		
		$this->setFormDefaults($form);
	}

	public function actionBalikobot(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('balikobotForm');

		$this->setFormDefaults($form);
	}
	
	public function createComponentTargitoForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$shopsContainer = $form->addContainer('shops');
		$shops = $this->shopsConfig->getAvailableShops();

		foreach ($shops as $shop) {
			$shopContainer = $shopsContainer->addContainer($shop->getPK());

			$shopContainer->addText('targitoDataId', Html::fromHtml($shop->getIconImageFormAdmin() . 'data-id'))->setNullable();
			$shopContainer->addText('targitoDataOrigin', Html::fromHtml($shop->getIconImageFormAdmin() . 'data-origin'))->setNullable();
		}

		if (!$shops) {
			$shopContainer = $shopsContainer->addContainer('default');

			$shopContainer->addText('targitoDataId', Html::fromHtml('data-id'))->setNullable();
			$shopContainer->addText('targitoDataOrigin', Html::fromHtml('data-origin'))->setNullable();
		}
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValuesWithAjax();

			$this->saveSettings($values);
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('targito');
		};
		
		return $form;
	}
	
	public function createComponentZasilkovnaForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$shopsContainer = $form->addContainer('shops');
		$shops = $this->shopsConfig->getAvailableShops();

		foreach ($shops as $shop) {
			$shopContainer = $shopsContainer->addContainer($shop->getPK());

			$shopContainer->addText('zasilkovnaApiKey', Html::fromHtml($shop->getIconImageFormAdmin() . ' Klíč API'))->setNullable();
			$shopContainer->addText('zasilkovnaApiPassword', Html::fromHtml($shop->getIconImageFormAdmin() . ' Heslo API'))->setNullable();
		}

		if (!$shops) {
			$shopContainer = $shopsContainer->addContainer('default');

			$shopContainer->addText('zasilkovnaApiKey', Html::fromHtml('Klíč API'))->setNullable();
			$shopContainer->addText('zasilkovnaApiPassword', Html::fromHtml('Heslo API'))->setNullable();
		}
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValuesWithAjax();

			$this->saveSettings($values);
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('zasilkovna');
		};
		
		return $form;
	}
	
	public function actionMailerLite(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('mailerLiteForm');
		
		$this->setFormDefaults($form);
	}
	
	public function createComponentMailerLiteForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$shopsContainer = $form->addContainer('shops');
		$shops = $this->shopsConfig->getAvailableShops();

		foreach ($shops as $shop) {
			$shopContainer = $shopsContainer->addContainer($shop->getPK());

			$shopContainer->addText('mailerLiteApiKey', Html::fromHtml($shop->getIconImageFormAdmin() . ' Klíč API'))->setNullable();
		}

		if (!$shops) {
			$shopContainer = $shopsContainer->addContainer('default');

			$shopContainer->addText('mailerLiteApiKey', Html::fromHtml('Klíč API'))->setNullable();
		}

		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValuesWithAjax();

			$this->saveSettings($values);
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('mailerLite');
		};
		
		return $form;
	}
	
	public function createComponentSupportboxForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$shopsContainer = $form->addContainer('shops');
		$shops = $this->shopsConfig->getAvailableShops();

		foreach ($shops as $shop) {
			$shopContainer = $shopsContainer->addContainer($shop->getPK());

			$shopContainer->addText('supportBoxApiKey', Html::fromHtml($shop->getIconImageFormAdmin() . ' Klíč API'))->setNullable();
		}

		if (!$shops) {
			$shopContainer = $shopsContainer->addContainer('default');

			$shopContainer->addText('supportBoxApiKey', Html::fromHtml('Klíč API'))->setNullable();
		}
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValuesWithAjax();

			$this->saveSettings($values);
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('supportBox');
		};
		
		return $form;
	}

	public function createComponentZboziForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$shopsContainer = $form->addContainer('shops');
		$shops = $this->shopsConfig->getAvailableShops();

		foreach ($shops as $shop) {
			$shopContainer = $shopsContainer->addContainer($shop->getPK());

			$shopContainer->addText($this::ZBOZI_API_KEY, Html::fromHtml($shop->getIconImageFormAdmin() . ' API klíč'))->setNullable();
			$shopContainer->addText($this::ZBOZI_STORE_ID, Html::fromHtml($shop->getIconImageFormAdmin() . ' ID provozovny'))->setNullable();
		}

		if (!$shops) {
			$shopContainer = $shopsContainer->addContainer('default');

			$shopContainer->addText($this::ZBOZI_API_KEY, Html::fromHtml('API klíč'))->setNullable();
			$shopContainer->addText($this::ZBOZI_STORE_ID, Html::fromHtml('ID provozovny'))->setNullable();
		}

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValuesWithAjax();

			$this->saveSettings($values);

			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('zbozi');
		};

		return $form;
	}
	
	public function createComponentHeurekaForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$shopsContainer = $form->addContainer('shops');
		$shops = $this->shopsConfig->getAvailableShops();

		foreach ($shops as $shop) {
			$shopContainer = $shopsContainer->addContainer($shop->getPK());

			$shopContainer->addText($this::HEUREKA_API_KEY, Html::fromHtml($shop->getIconImageFormAdmin() . ' API klíč'))->setNullable();
		}

		if (!$shops) {
			$shopContainer = $shopsContainer->addContainer('default');

			$shopContainer->addText($this::HEUREKA_API_KEY, Html::fromHtml('API klíč'))->setNullable();
		}
		
		$form->addSubmit('submit', 'Uložit');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValuesWithAjax();

			$this->saveSettings($values);
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('heureka');
		};
		
		return $form;
	}

	public function createComponentBalikobotForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$shopsContainer = $form->addContainer('shops');
		$shops = $this->shopsConfig->getAvailableShops();

		foreach ($shops as $shop) {
			$shopContainer = $shopsContainer->addContainer($shop->getPK());

			$shopContainer->addSelect(
				self::BALIKOBOT_PROVIDER_ID,
				Html::fromHtml($shop->getIconImageFormAdmin() . ' Poskytovatel svozu'),
				[
					null => '',
					DeliveryProviders::GLS->value => 'GLS',
					DeliveryProviders::PPL->value => 'PPL',
				]
			);
			$shopContainer
				->addText(
					self::BALIKOBOT_CC_ADDRESS,
					Html::fromHtml($shop->getIconImageFormAdmin() . ' Adresa pro kopii emailu'),
				)
				->setNullable()
				->addCondition(FormAlias::Filled)
				->addRule(FormAlias::Email);
		}

		if (!$shops) {
			$shopContainer = $shopsContainer->addContainer('default');

			$shopContainer->addSelect(
				self::BALIKOBOT_PROVIDER_ID,
				Html::fromHtml('Poskytovatel svozu'),
				[
					null => '',
					DeliveryProviders::GLS->value => 'GLS',
					DeliveryProviders::PPL->value => 'PPL',
				]
			);
			$shopContainer->addText(
				self::BALIKOBOT_CC_ADDRESS,
				Html::fromHtml('Adresa pro kopii emailu'),
			)
				->addCondition(FormAlias::Filled)
				->addRule(FormAlias::Email);
		}

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValuesWithAjax();

			foreach (\array_keys($values['shops']) as $key) {
				if ($values['shops'][$key][self::BALIKOBOT_PROVIDER_ID] === '') {
					$values['shops'][$key][self::BALIKOBOT_PROVIDER_ID] = null;
				}

				if ($values['shops'][$key][self::BALIKOBOT_CC_ADDRESS] !== '') {
					continue;
				}

				$values['shops'][$key][self::BALIKOBOT_CC_ADDRESS] = null;
			}

			$this->saveSettings($values);

			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('balikobot');
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

	public function renderBalikobot(): void
	{
		$this->template->headerLabel = 'Integrace';
		$this->template->headerTree = [
			['Integrace'],
			['Balíkobot'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('balikobotForm')];
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

	private function setFormDefaults(Form $form): void
	{
		$values = $this->settingsRepo->many();
		$defaults = [];

		foreach ($values as $value) {
			$defaults['shops'][$value->shop?->getPK() ?? 'default'][$value->name] = $value->value;
		}

		$form->setDefaults($defaults);
	}

	/**
	 * @param array{shops: array<string|int, array<string, string>>} $values
	 * @throws \StORM\Exception\NotFoundException
	 */
	private function saveSettings(array $values): void
	{
		foreach ($values['shops'] as $shop => $shopValues) {
			foreach ($shopValues as $key => $value) {
				$this->settingsRepo->syncOne([
					'name' => $key,
					'value' => $value,
					'shop' => $shop === 'default' ? null : $shop,
				], ignore: false);
			}
		}
	}
}
