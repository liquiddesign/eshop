<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use App\Admin\Controls\AdminForm;
use Eshop\DB\Ribbon;
use Eshop\DB\RibbonRepository;
use Forms\Form;
use Nette\Utils\Image;
use StORM\DIConnection;

class RibbonPresenter extends BackendPresenter
{
	/** @inject */
	public RibbonRepository $ribbonRepository;

	public const TYPES = [
		'normal' => 'Běžný',
		'onlyImage' => 'Pouze obrázek'
	];

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

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create();

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

		$form->addSubmits(!$ribbon);

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$this->createImageDirs(Ribbon::IMAGE_DIR);

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$values['imageFileName'] = $form['imageFileName']->upload($values['uuid'] . '.%2$s');

			$ribbon = $this->ribbonRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$ribbon]);
		};

		return $form;
	}

	public function renderDefault()
	{
		$this->template->headerLabel = 'Štítky';
		$this->template->headerTree = [
			['Štítky'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew()
	{
		$this->template->headerLabel = 'Nová štítek';
		$this->template->headerTree = [
			['Štítky', 'default'],
			['Nový štítek'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail()
	{
		$this->template->headerLabel = 'Detail  štítku';
		$this->template->headerTree = [
			['Štítky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(Ribbon $ribbon)
	{
		/** @var Form $form */
		$form = $this->getComponent('newForm');
		$form->setDefaults($ribbon->toArray());
	}
}
