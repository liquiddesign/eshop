<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\Producer;
use Eshop\DB\ProducerRepository;
use Forms\Form;
use Nette\Http\Request;
use Nette\Utils\Image;
use Pages\DB\PageRepository;
use Pages\Helpers;
use StORM\DIConnection;
use StORM\Entity;

class ProducerPresenter extends BackendPresenter
{
	/** @inject */
	public ProducerRepository $producerRepository;

	/** @inject */
	public PageRepository $pageRepository;

	/** @inject */
	public Request $request;

	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->producerRepository->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnImage('imageFileName', Producer::IMAGE_DIR);

		$grid->addColumn('Název', function (Producer $producer, $grid) {
			return [$grid->getPresenter()->link(':Eshop:Product:list', ['producer' => (string)$producer]), $producer->name];
		}, '<a href="%s" target="_blank"> %s</a>', 'name');


		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['name_cs'], null, 'Název');

		$grid->addFilterButtons();

		$grid->onDelete[] = [$this, 'onDelete'];

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create();
		$nameInput = $form->addLocaleText('name', 'Název');
		$imagePicker = $form->addImagePicker('imageFileName', 'Obrázek', [
			Producer::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			Producer::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			Producer::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);

		$producer = $this->getParameter('producer');

		$imagePicker->onDelete[] = function () use ($producer) {
			$this->onDelete($producer);
			$this->redirect('this');
		};

		$form->addLocalePerexEdit('perex', 'Perex');
		$form->addLocaleRichEdit('content', 'Obsah');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('recommended', 'Doporučeno');
		$form->addCheckbox('hidden', 'Skryto');
		$form->addPageContainer('product_list', ['producer' => $this->getParameter('producer')], $nameInput);

		$form->addSubmits(!$producer);

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$this->createImageDirs(Producer::IMAGE_DIR);

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$values['imageFileName'] = $form['imageFileName']->upload($values['uuid'] . '.%2$s');

			$producer = $this->producerRepository->syncOne($values, null, true);

			$values['page']['params'] = Helpers::serializeParameters(['producer' => $producer->getPK()]);

			$this->pageRepository->syncOne($values['page']);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$producer]);
		};

		return $form;
	}

	public function renderDefault()
	{
		$this->template->headerLabel = 'Výrobci';
		$this->template->headerTree = [
			['Výrobci'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Výrobci', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail()
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Výrobci', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(Producer $producer)
	{
		/** @var Form $form */
		$form = $this->getComponent('newForm');
		$form->setDefaults($producer->toArray());
	}

	protected function onDelete(Entity $object)
	{
		$this->onDeleteImage($object);
		$this->onDeletePage($object);
	}
}
