<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\OrderRepository;
use Eshop\Integration\MailerLite;
use Eshop\Integration\Zasilkovna;
use Web\DB\ContactItemRepository;
use Web\DB\SettingRepository;

class IntegrationPresenter extends BackendPresenter
{
	protected const CONFIGURATION = [
		'supportBox' => false,
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

	public function beforeRender(): void
	{
		parent::beforeRender();

		$this->template->tabs = [
			'@default' => 'Měření a nástroje',
			'@zasilkovna' => 'Zásilkovna',
			'@mailerLite' => 'MailerLite',
		];

		if (!isset($this::CONFIGURATION['supportBox']) || !$this::CONFIGURATION['supportBox']) {
			return;
		}

		$this->template->tabs['@supportBox'] = 'SupportBox';
	}

	public function actionDefault(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('form');

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

	public function renderZasilkovna(): void
	{
		$this->template->headerLabel = 'Integrace';
		$this->template->headerTree = [
			['Integrace'],
			['Zásilkovna'],
		];
		$this->template->displayButtons = [
			'<a href="' . $this->link('syncZasilkovnaPoints!') .
			'" onclick="return confirm(\'Opravdu? Tato operace může trvat až 5 minut.\')">
<button class="btn btn-sm btn-outline-primary"><i class="fa fa-sync"></i>  Synchronizovat výdejní místa</button></a>',
			$this->createButtonWithClass('syncZasilkovnaOrders!', '<i class="fa fa-sync"></i>  Synchronizovat objednávky', 'btn btn-sm btn-outline-primary'),
		];
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
			$this->zasilkovnaProvider->syncOrders($this->orderRepository->many()
				->where('this.completedTs IS NOT NULL AND this.canceledTs IS NULL')
				->where('purchase.zasilkovnaId IS NOT NULL')
				->where('zasilkovnaCompleted', false));
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
		$this->template->displayButtons = [$this->createButtonWithClass('syncMailerLite!', '<i class="fa fa-sync"></i>  Synchronizovat s MailerLite', 'btn btn-sm btn-outline-primary')];
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
}
