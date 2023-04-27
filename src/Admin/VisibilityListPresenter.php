<?php

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\BackendPresenter;
use Eshop\DB\Product;
use Eshop\DB\VisibilityList;
use Eshop\DB\VisibilityListItem;
use Eshop\DB\VisibilityListRepository;
use Forms\Form;
use Grid\Datagrid;
use Nette\Application\Attributes\Persistent;
use Nette\DI\Attributes\Inject;
use StORM\Collection;

class VisibilityListPresenter extends BackendPresenter
{
	public const TABS = [
		'lists' => 'Seznamy',
		'items' => 'Položky',
	];

	#[Inject]
	public VisibilityListRepository $visibilityListRepository;

	#[Inject]
	public \Eshop\DB\VisibilityListItemRepository $visibilityListItemRepository;

	#[Persistent]
	public string $tab = 'lists';

	public function createComponentListsGrid(): AdminGrid
	{
		$collection = $this->visibilityListRepository->many();

		$grid = $this->gridFactory->create($collection, 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('listDetail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(condition: function (VisibilityList $systemicEntity) {
			return !$systemicEntity->isSystemic();
		});

		$grid->addFilterTextInput('search', ['name'], null, 'Název');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentItemsGrid(): AdminGrid
	{
		$collection = $this->visibilityListItemRepository->many()
			->where('visibilityList.fk_shop = :shop OR visibilityList.fk_shop IS NULL', ['shop' => $this->shopsConfig->getSelectedShop()?->getPK()]);

		$grid = $this->gridFactory->create($collection, 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Seznam', 'visibilityList.name', '%s', 'visibilityList.name');

		$grid->addColumn('Kód', function (VisibilityListItem $visibilityListItem) {
			return $visibilityListItem->product->getFullCode();
		}, '%s', 'product.code', ['class' => 'fit']);

		$grid->addColumn('Produkt', function (VisibilityListItem $visibilityListItem, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Product:edit') ? $datagrid->getPresenter()->link(
				':Eshop:Admin:Product:edit',
				[$visibilityListItem->product, 'backLink' => $this->storeRequest()],
			) : '#';

			return '<a href="' . $link . '">' . $visibilityListItem->product->name . '</a>';
		}, '%s');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);

		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('itemDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$mutationSuffix = $this->visibilityListItemRepository->getConnection()->getMutationSuffix();
		$grid->addFilterTextInput('product', ["product.name$mutationSuffix", 'product.code', 'product.ean'], null, 'Produkt - Jméno, kód, ean');

		if ($categories = $this->visibilityListRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$source->where('this.fk_visibilityList', $value);
			}, '', 'list', null, $categories)->setPrompt('- Seznam -');
		}

		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentListForm(): Form
	{
		$form = $this->formFactory->create();

		/** @var \Eshop\DB\VisibilityList|null $object */
		$object = $this->getParameter('object');

		$form->addText('name', 'Název')
			->setRequired();

		$form->addInteger('priority', 'Priorita')
			->setDefaultValue(10)
			->setRequired();

		$form->addCheckbox('hidden', 'Skryto');

		$this->formFactory->addShopsContainerToAdminForm($form);

		$form->addSubmits(!$object);

		$form->onSuccess[] = function (AdminForm $form) use ($object): void {
			$values = $form->getValues('array');

			$object = $this->visibilityListRepository->syncOne($values, null, true, ignore: false);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('listDetail', 'default', [$object]);
		};

		return $form;
	}

	public function createComponentItemForm(): Form
	{
		$form = $this->formFactory->create();

		/** @var \Eshop\DB\VisibilityListItem|null $object */
		$object = $this->getParameter('object');

		$form->monitor(BackendPresenter::class, function (BackendPresenter $presenter) use ($form, $object): void {
			$form->addInteger('priority', 'Priorita')
				->setDefaultValue(10)
				->setRequired();

			$hiddenInput = $form->addCheckbox('hidden', 'Skryto');
			$hiddenInMenuInput = $form->addCheckbox('hiddenInMenu', 'Skryto v menu a vyhledávání');

			$hiddenInMenuInput->addConditionOn($hiddenInput, $form::EQUAL, false)->toggle($hiddenInMenuInput->getHtmlId() . '-toogle');

			$productInput = $form->addSelectAjax('product', 'Produkt', 'Zvolte produkt', Product::class);

			if ($object) {
				$this->template->select2AjaxDefaults[$productInput->getHtmlId()] = [$object->getValue('product') => $object->product->name];
			}

			$form->addSelect2('visibilityList', 'Seznam', $this->visibilityListRepository->getArrayForSelect())
				->setPrompt('- Zvolte seznam -')
				->setRequired();

			$form->addSubmits(!$object);
		});

		$form->onValidate[] = function (AdminForm $form): void {
			if (!$form->isValid()) {
				return;
			}

			$data = $this->getHttpRequest()->getPost();

			if (isset($data['product'])) {
				return;
			}

			/** @var \Nette\Forms\Controls\SelectBox $input */
			$input = $form['product'];
			$input->addError('Toto pole je povinné!');
		};

		$form->onSuccess[] = function (AdminForm $form) use ($object): void {
			$values = $form->getValuesWithAjax();

			$object = $this->visibilityListItemRepository->syncOne($values, null, true, ignore: false);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('itemDetail', 'default', [$object]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Viditelnosti';
		$this->template->headerTree = [
			['Viditelnosti', 'this',],
			[self::TABS[$this->tab]],
		];

		if ($this->tab === 'lists') {
			$this->template->displayButtons = [$this->createNewItemButton('listNew')];
			$this->template->displayControls = [$this->getComponent('listsGrid')];
		} elseif ($this->tab === 'items') {
			$this->template->displayButtons = [$this->createNewItemButton('itemNew')];
			$this->template->displayControls = [$this->getComponent('itemsGrid')];
		}

		$this->template->tabs = self::TABS;
	}

	public function renderListNew(): void
	{
		$this->template->headerLabel = 'Nový';
		$this->template->headerTree = [
			['Viditelnosti', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('listForm')];
	}

	public function renderListDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Viditelnosti', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('listForm')];
	}

	public function actionListDetail(VisibilityList $object): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('listForm');
		$values = $object->toArray();
		$form->setDefaults($values);
	}

	public function renderItemNew(): void
	{
		$this->template->headerLabel = 'Nový';
		$this->template->headerTree = [
			['Viditelnosti'],
			['Položky', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('itemForm')];
	}

	public function renderItemDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Viditelnosti'],
			['Položky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('itemForm')];
	}

	public function actionItemDetail(VisibilityListItem $object): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('itemForm');
		$values = $object->toArray();
		$form->setDefaults($values);
	}
}
