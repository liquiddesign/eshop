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
	
	public function beforeRender()
	{
		parent::beforeRender();
		
		$this->template->tabs = [
			'@default' => 'Měření a nástroje',
			'@zasilkovna' => 'Zásilkovna',
			'@mailerLite' => 'MailerLite',
		];
	}
	
	public function actionDefault()
	{
		/** @var AdminForm $form */
		$form = $this->getComponent('form');
		
		$form->setDefaults($this->settingsRepo->many()->setIndex('name')->toArrayOf('value'));
	}
	
	public function renderDefault()
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
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne(['name' => $key, 'value' => $value]);
			}
			
			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('default');
		};
		
		return $form;
	}

	public function actionZasilkovna()
	{
		/** @var AdminForm $form */
		$form = $this->getComponent('zasilkovnaForm');

		$form->setDefaults($this->settingsRepo->many()->setIndex('name')->toArrayOf('value'));
	}

	public function createComponentZasilkovnaForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('zasilkovnaApiKey', 'Klíč API')->setNullable();
		$form->addText('zasilkovnaApiPassword', 'Heslo API')->setNullable();

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne(['name' => $key, 'value' => $value]);
			}

			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('zasilkovna');
		};

		return $form;
	}

	public function actionMailerLite()
	{
		/** @var AdminForm $form */
		$form = $this->getComponent('mailerLiteForm');

		$form->setDefaults($this->settingsRepo->many()->setIndex('name')->toArrayOf('value'));
	}

	public function createComponentMailerLiteForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addText('mailerLiteApiKey', 'Klíč API')->setNullable();

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			foreach ($values as $key => $value) {
				$this->settingsRepo->syncOne(['name' => $key, 'value' => $value]);
			}

			$this->flashMessage('Nastavení uloženo', 'success');
			$form->processRedirect('mailerLite');
		};

		return $form;
	}

	public function renderZasilkovna()
	{
		$this->template->headerLabel = 'Integrace';
		$this->template->headerTree = [
			['Integrace'],
			['Zásilkovna']
		];
		$this->template->displayButtons = [
			'<a href="' . $this->link('syncZasilkovnaPoints!') . '" onclick="return confirm(\'Opravdu? Tato operace může trvat až 5 minut.\')"><button class="btn btn-sm btn-outline-primary"><i class="fa fa-sync"></i>  Synchronizovat výdejní místa</button></a>',
			$this->createButtonWithClass('syncZasilkovnaOrders!', '<i class="fa fa-sync"></i>  Synchronizovat objednávky', 'btn btn-sm btn-outline-primary')
		];
		$this->template->displayControls = [$this->getComponent('zasilkovnaForm')];
	}

	public function handleSyncZasilkovnaPoints()
	{
		try {
			$this->zasilkovnaProvider->syncPickupPoints();
			$this->flashMessage('Provedeno', 'success');
		} catch (\Exception $e) {
			$this->flashMessage('Chyba! Zkontrolujte API klíč.', 'error');
		}

		$this->redirect('this');
	}

	public function handleSyncZasilkovnaOrders()
	{
		try {
			$this->zasilkovnaProvider->syncOrders($this->orderRepository->many()
				->where('this.completedTs IS NOT NULL AND this.canceledTs IS NULL')
				->where('purchase.zasilkovnaId IS NOT NULL')
				->where('zasilkovnaCompleted', false)
			);
			$this->flashMessage('Provedeno', 'success');
		} catch (\Exception $e) {
			$this->flashMessage('Chyba! Zkontrolujte API klíč.', 'error');
		}

		$this->redirect('this');
	}

	public function renderMailerLite()
	{
		$this->template->headerLabel = 'Integrace';
		$this->template->headerTree = [
			['Integrace'],
			['MailerLite']
		];
		$this->template->displayButtons = [$this->createButtonWithClass('syncMailerLite!', '<i class="fa fa-sync"></i>  Synchronizovat s MailerLite', 'btn btn-sm btn-outline-primary')];
		$this->template->displayControls = [$this->getComponent('mailerLiteForm')];
	}

	public function handleSyncMailerLite()
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