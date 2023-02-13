<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\BackendPresenter;
use Eshop\DB\Complaint;
use Eshop\DB\ComplaintRepository;
use Eshop\DB\ComplaintState;
use Eshop\DB\ComplaintStateRepository;
use Eshop\DB\ComplaintType;
use Eshop\DB\ComplaintTypeRepository;
use Eshop\DB\OrderRepository;
use Nette\Forms\Controls\TextInput;
use StORM\DIConnection;

class ComplaintPresenter extends BackendPresenter
{
	public const TABS = [
		'complaints' => 'Reklamace',
		'types' => 'Typy',
		'states' => 'Stavy',
	];

	/** @inject */
	public ComplaintRepository $complaintRepository;

	/** @inject */
	public ComplaintStateRepository $complaintStateRepository;

	/** @inject */
	public ComplaintTypeRepository $complaintTypeRepository;

	/** @inject */
	public OrderRepository $orderRepository;

	/** @persistent */
	public string $tab = 'complaints';

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Reklamace';
		$this->template->headerTree = [
			['Reklamace', 'this',],
			[self::TABS[$this->tab]],
		];

		if ($this->tab === 'complaints') {
			$this->template->displayButtons = [$this->createNewItemButton('complaintNew')];
			$this->template->displayControls = [$this->getComponent('complaintsGrid')];
		} elseif ($this->tab === 'states') {
			$this->template->displayButtons = [$this->createNewItemButton('stateNew')];
			$this->template->displayControls = [$this->getComponent('statesGrid')];
		} elseif ($this->tab === 'types') {
			$this->template->displayButtons = [$this->createNewItemButton('typeNew')];
			$this->template->displayControls = [$this->getComponent('typesGrid')];
		}

		$this->template->tabs = self::TABS;
	}

	public function createComponentComplaintsGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->complaintRepository->many(), 20, 'createdTs', 'DESC');

		$grid->addColumnSelector();
		$grid->addColumnText('Vytvořeno', "createdTs|date:'d.m.Y G:i'", '%s', 'createdTs');
		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumnText('Typ', 'complaintType.name', '%s', 'complaintType.priority');
		$grid->addColumnText('Stav', 'complaintState.name', '%s', 'complaintState.sequence');
		$grid->addColumn('Objednávka', function (Complaint $complaint): string {
			$order = $complaint->order;
			$link = $order && $this->admin->isAllowed(':Eshop:Admin:Order:default') ? $this->link(':Eshop:Admin:Order:default', ['ordersGrid-search_order' => $complaint->order->code]) : null;

			return $order && $link ? "<a href='$link' target='_blank'>$order->code</a>" : $complaint->orderCode;
		}, '%s', 'code');

		$grid->addColumn('Zákazník', function (Complaint $complaint): array {
			$customer = $complaint->customer;
			$link = $customer && $this->admin->isAllowed(':Eshop:Admin:Customer:detail') ? $this->link(':Eshop:Admin:Customer:default', ['customers-search' => $customer->email]) : null;

			$result = [];

			$result[] = $customer && $link ? "<a href='$link' target='_blank'>$customer->fullname</a>" : $complaint->customerFullName;
			$result[] = $customer ? $customer->email : $complaint->customerEmail;
			$result[] = $customer ? $customer->phone : $complaint->customerPhone;

			return $result;
		}, '%1$s<br><a href="mailto:%2$s"><i class="far fa-envelope"></i> %2$s</a><small><a href="tel:%3$s" class="ml-2"><i class="fa fa-phone-alt"></i> %3$s</a></small>', 'code');

		$grid->addColumnLinkDetail('complaintDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('code', ['this.orderCode'], null, 'Kód objednávky');
		$grid->addFilterSelectInput('complaintType', 'this.fk_complaintType = :q', 'Typ', '- Typ -', null, $this->complaintTypeRepository->getArrayForSelect());
		$grid->addFilterSelectInput('complaintState', 'this.fk_complaintState = :q', 'Stav', '- Stav -', null, $this->complaintStateRepository->getArrayForSelect());
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentComplaintForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		/** @var \Eshop\DB\Complaint|null $complaint */
		$complaint = $this->getParameter('complaint');

		$form->addText('code', 'Kód')->setRequired()->setDisabled((bool) $complaint);
		$form->addText('orderCode', 'Kód objednávky')
			->setRequired()
			->setDisabled((bool) $complaint)
			->addRule(
				fn (\Nette\Forms\Controls\TextInput $input): bool => (bool) $this->orderRepository->one(['code' => $input->getValue()]),
				'Neplatný kód objednávky',
			);

		$form->addSelect('complaintType', 'Typ', $this->complaintTypeRepository->getArrayForSelect())->setRequired();
		$form->addSelect('complaintState', 'Stav', $this->complaintStateRepository->getArrayForSelect())->setRequired();

		$form->addTextArea('note', 'Komentář');

		$form->addText('customerFullName', 'Jméno zákazníka')->setNullable()->setDisabled((bool) $complaint);
		$form->addEmail('customerEmail', 'E-mail zákazníka')->setNullable()->setDisabled((bool) $complaint);
		$form->addText('customerPhone', 'Telefon zákazníka')->setNullable()->setDisabled((bool) $complaint)
			->setHtmlAttribute('data-info', 'Nepovinné údaje budou doplněny automaticky z objednávky.');

		$form->addSubmits(!$complaint);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();

				$object = $this->complaintRepository->create($values);
			} else {
				$object = $this->complaintRepository->syncOne($values, null, false, false);
			}

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('complaintDetail', 'default', [$object]);
		};

		return $form;
	}

	public function createComponentStatesGrid(): AdminGrid
	{
		$mutationSuffix = $this->complaintStateRepository->getConnection()->getMutationSuffix();

		$grid = $this->gridFactory->create($this->complaintStateRepository->many(), 20, 'sequence', 'ASC', true);

		$grid->addColumnSelector();
		$grid->addColumnText('Název', "name$mutationSuffix", '%s', "name$mutationSuffix");

		$grid->addColumnInputInteger('Pořadí', 'sequence', '', '', 'sequence', [], true);

		$grid->addColumnLinkDetail('stateDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		return $grid;
	}

	public function createComponentTypesGrid(): AdminGrid
	{
		$mutationSuffix = $this->complaintTypeRepository->getConnection()->getMutationSuffix();

		$grid = $this->gridFactory->create($this->complaintTypeRepository->many(), 20, 'sequence', 'ASC', true);

		$grid->addColumnSelector();
		$grid->addColumnText('Název', "name$mutationSuffix", '%s', "name$mutationSuffix");

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('typeDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		return $grid;
	}

	public function createComponentStateForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		/** @var \Eshop\DB\ComplaintState|null $complaintState */
		$complaintState = $this->getParameter('complaintState');

		$form->addLocaleText('name', 'Název')->forPrimary(function (TextInput $input): void {
			$input->setRequired();
		});

		$form->addInteger('sequence', 'Pořadí')->setRequired();

		$form->addSubmits(!$complaintState);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			/** @var \Eshop\DB\ComplaintState $object */
			$object = $this->complaintStateRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('stateDetail', 'default', [$object]);
		};

		return $form;
	}

	public function createComponentTypeForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		/** @var \Eshop\DB\ComplaintType|null $complaintType */
		$complaintType = $this->getParameter('complaintType');

		$form->addLocaleText('name', 'Název')->forPrimary(function (TextInput $input): void {
			$input->setRequired();
		});

		$form->addInteger('priority', 'Priorita')
			->setDefaultValue(10)
			->setRequired();
		$form->addCheckbox('hidden', 'Skryto');

		$form->addSubmits(!$complaintType);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			/** @var \Eshop\DB\ComplaintType $object */
			$object = $this->complaintTypeRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('typeDetail', 'default', [$object]);
		};

		return $form;
	}

	public function actionComplaintDetail(Complaint $complaint): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('complaintForm');

		$defaults = $complaint->toArray();

		$form->setDefaults($defaults);
	}

	public function renderComplaintNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Reklamace', 'default',],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('complaintForm')];
	}

	public function renderComplaintDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Reklamace', 'default',],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('complaintForm')];
	}

	public function renderStateNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Reklamace', 'default',],
			['Stav', 'default',],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('stateForm')];
	}

	public function actionStateDetail(ComplaintState $complaintState): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('stateForm');

		$defaults = $complaintState->toArray();

		$form->setDefaults($defaults);
	}

	public function renderStateDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Reklamace', 'default',],
			['Stav', 'default',],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('stateForm')];
	}

	public function renderTypeNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Reklamace', 'default',],
			['Typ', 'default',],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('typeForm')];
	}

	public function actionTypeDetail(ComplaintType $complaintType): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('typeForm');

		$defaults = $complaintType->toArray();

		$form->setDefaults($defaults);
	}

	public function renderTypeDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Reklamace', 'default',],
			['Typ', 'default',],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('typeForm')];
	}
}
