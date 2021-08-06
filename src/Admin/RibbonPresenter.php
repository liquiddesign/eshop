<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\DiscountRepository;
use Eshop\DB\InternalRibbon;
use Eshop\DB\InternalRibbonRepository;
use Eshop\DB\Ribbon;
use Eshop\DB\RibbonRepository;
use Forms\Form;
use Nette\Utils\Arrays;
use Nette\Utils\Image;
use StORM\Connection;
use StORM\DIConnection;

class RibbonPresenter extends BackendPresenter
{
	protected const CONFIGURATION = [
		'dynamicRibbonSaleability' => [
			'day' => 'Den',
			'week' => 'Týden',
			'14day' => '14 dní',
			'month' => 'Měsíc',
			'halfYear' => 'Půl roku',
			'year' => 'Rok'
		]
	];

	/** @inject */
	public RibbonRepository $ribbonRepository;
	
	/** @inject */
	public InternalRibbonRepository $internalRibbonRepository;

	/** @inject */
	public DiscountRepository $discountRepository;

	/** @inject */
	public Connection $storm;

	public const TYPES = [
		'normal' => 'Běžný',
		'onlyImage' => 'Pouze obrázek'
	];
	
	public function beforeRender()
	{
		parent::beforeRender();
		
		$this->template->tabs = [
			'@default' => 'Veřejné',
			'@internal' => 'Interní',
		];
	}
	
	
	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->ribbonRepository->many(), 20, 'priority');
		$grid->addColumnSelector();
		$grid->addColumnImage('imageFileName', Ribbon::IMAGE_DIR);
		$grid->addColumn('Typ', function (Ribbon $ribbon) {
			return $this::TYPES[$ribbon->type];
		}, '%s', 'type');
		$grid->addColumnText('Popisek', 'name', '%s', 'name');
		$columnText = $grid->addColumnText('Barva textu', 'color', '%s', 'color');
		$columnBackground = $grid->addColumnText('Barva pozadí', 'backgroundColor', '%s', 'backgroundColor');
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->onRenderRow[] = function (\Nette\Utils\Html $tr, Ribbon $object) use ($columnText, $columnBackground) {
			$tr[$columnText->getId()]->setAttribute('style', "color: $object->color");
			$tr[$columnBackground->getId()]->setAttribute('style', "color: $object->backgroundColor");
		};

		$grid->addFilterTextInput('search', ['name_cs'], null, 'Popisek');
		$grid->addFilterSelectInput('type', 'type = :t', null, '- Typ -', null, $this::TYPES, 't');

		$grid->addFilterButtons();

		$grid->onDelete[] = [$this, 'onDelete'];

		return $grid;
	}
	
	public function createComponentInternalGrid()
	{
		$grid = $this->gridFactory->create($this->internalRibbonRepository->many(), 20, 'priority');
		$grid->addColumnSelector();
		$grid->addColumnText('Popisek', 'name', '%s', 'name');
		$columnText = $grid->addColumnText('Barva textu', 'color', '%s', 'color');
		$columnBackground = $grid->addColumnText('Barva pozadí', 'backgroundColor', '%s', 'backgroundColor');
		$grid->addColumnLinkDetail('InternalDetail');
		$grid->addColumnActionDelete();
		
		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();
		
		$grid->onRenderRow[] = function (\Nette\Utils\Html $tr, InternalRibbon $object) use ($columnText, $columnBackground) {
			$tr[$columnText->getId()]->setAttribute('style', "color: $object->color");
			$tr[$columnBackground->getId()]->setAttribute('style', "color: $object->backgroundColor");
		};
		
		$grid->addFilterTextInput('search', ['name_cs'], null, 'Popisek');
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function createComponentInternalForm(): Form
	{
		$form = $this->formFactory->create(true);
		
		$form->addText('name', 'Název')->setRequired(true);
		
		$ribbon = $this->getParameter('ribbon');
		
		$form->addColor('color', 'Barva textu');
		$form->addColor('backgroundColor', 'Barva pozadí');
		;
		$form->addSubmits(!$ribbon);
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			$ribbon = $this->internalRibbonRepository->syncOne($values);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('internalDetail', 'internal', [$ribbon]);
		};
		
		return $form;
	}
	
	public function createComponentForm(): Form
	{
		$form = $this->formFactory->create(true);

		$form->addLocaleText('name', 'Název');
		$imagePicker = $form->addImagePicker('imageFileName', 'Obrázek', [
			Ribbon::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			Ribbon::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			Ribbon::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);

		$ribbon = $this->getParameter('ribbon');

		$imagePicker->onDelete[] = function () use ($ribbon) {
			$this->onDelete($ribbon);
			$this->redirect('this');
		};

		$form->addSelect('type', 'Typ', $this::TYPES);
		$form->addColor('color', 'Barva textu');
		$form->addColor('backgroundColor', 'Barva pozadí');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');

		$form->addGroup('Dynamický štítek');
		$form->addCheckbox('dynamic', 'Aktivní')
			->addCondition($form::EQUAL, true)
			->toggle('frm-saleability-toogle')
			->toggle('frm-newForm-maxProducts-toogle');

		$form->addSelect2('saleability', 'Prodejnost za období', static::CONFIGURATION['dynamicRibbonSaleability'])
			->addConditionOn($form['dynamic'], $form::EQUAL, true)
			->setRequired();
		$form->addInteger('maxProducts', 'Maximum přiřazených produktů')
			->addConditionOn($form['dynamic'], $form::EQUAL, true)
			->setRequired();

		$form->addDataMultiSelect('discounts', 'Akce', $this->discountRepository->getArrayForSelect())->setHtmlAttribute('placeholder', 'Vyberte položky...');

		$form->addSubmits(!$ribbon);

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$this->createImageDirs(Ribbon::IMAGE_DIR);

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			} else {
				$this->storm->rows(['eshop_discount_nxn_eshop_ribbon'])->where('fk_ribbon', $values['uuid'])->delete();
			}

			$discounts = Arrays::pick($values, 'discounts');

			$values['imageFileName'] = $form['imageFileName']->upload($values['uuid'] . '.%2$s');

			$ribbon = $this->ribbonRepository->syncOne($values);

			foreach ($discounts as $discountKey) {
				$this->storm->createRow('eshop_discount_nxn_eshop_ribbon', [
					'fk_ribbon' => $ribbon->getPK(),
					'fk_discount' => $discountKey
				]);
			}

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$ribbon]);
		};

		return $form;
	}

	public function renderDefault()
	{
		$this->template->headerLabel = 'Veřejné štítky';
		$this->template->headerTree = [
			['Veřejné štítky'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function renderInternal()
	{
		$this->template->headerLabel = 'Interní štítky';
		$this->template->headerTree = [
			['Interní štítky'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('internalNew')];
		$this->template->displayControls = [$this->getComponent('internalGrid')];
	}
	
	public function renderNew()
	{
		$this->template->headerLabel = 'Nový veřejný štítek';
		$this->template->headerTree = [
			['Veřejné štítky', 'default'],
			['Nový štítek'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
		$this->template->activeTab = 'default';
	}
	
	public function renderInternalNew()
	{
		$this->template->headerLabel = 'Nový interní štítek';
		$this->template->headerTree = [
			['Interní štítky', 'default'],
			['Nový títek'],
		];
		$this->template->displayButtons = [$this->createBackButton('internal')];
		$this->template->displayControls = [$this->getComponent('internalForm')];
		$this->template->activeTab = 'internal';
	}
	
	public function renderDetail()
	{
		$this->template->headerLabel = 'Detail veřejného štítku';
		$this->template->headerTree = [
			['Veřejné štítky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
		$this->template->activeTab = 'default';
	}

	public function renderInternalDetail()
	{
		$this->template->headerLabel = 'Detail interního štítku';
		$this->template->headerTree = [
			['Interní štítky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('internal')];
		$this->template->displayControls = [$this->getComponent('internalForm')];
		$this->template->activeTab = 'internal';
	}
	
	public function actionInternalDetail(InternalRibbon $ribbon)
	{
		/** @var Form $form */
		$form = $this->getComponent('internalForm');
		$form->setDefaults($ribbon->toArray());
	}

	public function actionDetail(Ribbon $ribbon)
	{
		/** @var Form $form */
		$form = $this->getComponent('form');

		$form->setDefaults($ribbon->toArray() + [
				'discounts' => \array_values($this->storm->rows(['eshop_discount_nxn_eshop_ribbon'])
					->where('fk_ribbon', $ribbon->getPK())
					->select(['discountKey' => 'fk_discount'])
					->toArrayOf('discountKey'))
			]);
	}
}
