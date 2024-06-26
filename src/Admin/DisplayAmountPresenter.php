<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\DisplayAmount;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Forms\Form;

class DisplayAmountPresenter extends BackendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public DisplayAmountRepository $displayAmountRepository;

	#[\Nette\DI\Attributes\Inject]
	public DisplayDeliveryRepository $displayDeliveryRepository;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->displayAmountRepository->many(), 20, 'priority', 'ASC', true);

		$grid->addColumnSelector();

		$grid->addColumnText('Popisek', 'label', '%s', 'label');
//		$grid->addColumnText('Množství od', 'amountFrom', '%s', 'amountFrom', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
//		$grid->addColumnText('Množství do', 'amountTo', '%s', 'amountTo', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];

		$grid->addColumnInputCheckbox('Vyprodáno', 'isSold', '', '', 'isSold');
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);

		$grid->addColumnLinkDetail('detail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function ($object) {
			return !$object->isSystemic();
		});

		$grid->addFilterTextInput('search', ['label_cs'], null, 'Popisek');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create(true);

		$form->addLocaleText('label', 'Popisek');
//		$form->addIntegerNullable('amountFrom', 'Množství od');
//		$form->addIntegerNullable('amountTo', 'Množství do');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10)->setRequired();
		$form->addSelect2('displayDelivery', 'Přednastavené doručení', $this->displayDeliveryRepository->getArrayForSelect())->setPrompt('Nepřiřazeno')
			->setHtmlAttribute('data-info', 'Pokud nastavíte "Přednastavené doručení", tak u produktů s nastaveným doručením na "Automaticky" bude zvoleno toto doručení.');
		$form->addCheckbox('isSold', 'Označit jako vyprodáno');

		$this->formFactory->addShopsContainerToAdminForm($form);

		$form->addSubmits(!$this->getParameter('displayAmount'));

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$displayAmount = $this->displayAmountRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');

			$form->processRedirect('detail', 'default', [$displayAmount]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Dostupnost';
		$this->template->headerTree = [
			['Dostupnost'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Dostupnost', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Zobrazované množství', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(DisplayAmount $displayAmount): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('newForm');
		$form->setDefaults($displayAmount->jsonSerialize());
	}
}
