<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CategoryRepository;
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
	public CategoryRepository $categoryRepository;

	/** @inject */
	public Request $request;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->producerRepository->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnImage('imageFileName', Producer::IMAGE_DIR);
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);

		$grid->addColumn('Název', function (Producer $producer, $grid) {
			return [
				$grid->getPresenter()->link(':Eshop:Product:list', ['producer' => (string)$producer]),
				$producer->name,
			];
		}, '<a href="%s" target="_blank"> %s</a>', 'name');


		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox(
			'<i title="Doporučeno" class="far fa-thumbs-up"></i>',
			'recommended',
			'',
			'',
			'recommended',
		);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['name_cs', 'this.code'], null, 'Kód, název');

		$grid->addFilterButtons();

		$grid->onDelete[] = [$this, 'onDelete'];

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create(true);
		$form->addText('code', 'Kód')->setRequired();
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

		$imagePicker->onDelete[] = function () use ($producer): void {
			$this->onDeleteImage($producer);
			$this->redirect('this');
		};

		$form->addLocalePerexEdit('perex', 'Perex');
		$form->addLocaleRichEdit('content', 'Obsah');
		$form->addSelect2('mainCategory', 'Hlavní kategorie', $this->categoryRepository->getTreeArrayForSelect())->setPrompt('- Kategorie -');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('recommended', 'Doporučeno');
		$form->addCheckbox('hidden', 'Skryto');
		$form->addPageContainer('product_list', ['producer' => $this->getParameter('producer')], $nameInput);

		$form->addSubmits(!$producer);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$this->createImageDirs(Producer::IMAGE_DIR);

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			/** @var \Forms\Controls\UploadImage $upload */
			$upload = $form['imageFileName'];
			
			$values['imageFileName'] = $upload->upload(DIConnection::generateUuid() . '.%2$s');

			$producer = $this->producerRepository->syncOne($values, null, true);

			$form->syncPages(function () use ($producer, $values): void {
				$values['page']['params'] = Helpers::serializeParameters(['producer' => $producer->getPK()]);
				$this->pageRepository->syncOne($values['page']);
			});

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$producer]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Výrobci';
		$this->template->headerTree = [
			['Výrobci'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Výrobci', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Výrobci', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(Producer $producer): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');
		$form->setDefaults($producer->toArray());
	}

	public function onDelete(Entity $object): void
	{
		$this->onDeleteImage($object);

		/** @var \Web\DB\Page|null $page */
		$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['producer' => $object->getPK()]);

		if (!$page) {
			return;
		}

		$page->delete();
	}
}
