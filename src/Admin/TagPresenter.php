<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use App\Admin\Controls\AdminForm;
use Eshop\DB\Tag;
use Eshop\DB\TagRepository;
use Forms\Form;
use Nette\Http\Request;
use Nette\Utils\Image;
use Pages\DB\PageRepository;
use Pages\Helpers;
use StORM\DIConnection;

class TagPresenter extends BackendPresenter
{
	/** @inject */
	public TagRepository $tagRepository;
	
	/** @inject */
	public PageRepository $pageRepository;

	/** @inject */
	public Request $request;

	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->tagRepository->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnImage('imageFileName', Tag::IMAGE_DIR);
		
		$grid->addColumn('Název', function (Tag $tag, $grid) {
			return [$grid->getPresenter()->link(':Eshop:Product:list', ['tag' => (string) $tag]), $tag->name];
		}, '<a href="%s" target="_blank"> %s</a>', 'name');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function ($object) {
			return $object->isSystemic();
		});

		$grid->addFilterTextInput('search', ['name_cs'], null, 'Název');
		$grid->addFilterButtons();

		$grid->onDelete[] = [$this, 'onDelete'];

		return $grid;
	}

	public function createComponentNewForm(): AdminForm
	{
		$form = $this->formFactory->create();
		
		$nameInput = $form->addLocaleText('name', 'Název');

		$imagePicker = $form->addImagePicker('imageFileName', 'Obrázek', [
			Tag::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			Tag::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			Tag::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);

		$tag = $this->getParameter('tag');

		$imagePicker->onDelete[] = function () use ($tag) {
			$this->onDelete($tag);
			$this->redirect('this');
		};

		$form->addLocalePerexEdit('perex', 'Perex');
		$form->addLocaleRichEdit('content', 'Obsah');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');
		$form->addCheckbox('recommended', 'Doporučeno');

		$form->addDataMultiSelect('similar', 'Podobné tagy', $this->tagRepository->getArrayForSelect());
		
		$form->addPageContainer('product_list', ['tag' => $this->getParameter('tag')], $nameInput);
		
		$form->addSubmits(!$tag);

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$this->createImageDirs(Tag::IMAGE_DIR);
			
			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}
		
			$values['imageFileName'] = $form['imageFileName']->upload($values['uuid'] . '.%2$s');

			$tag = $this->tagRepository->syncOne($values, null, true);
			
			$values['page']['params'] = Helpers::serializeParameters(['tag' => $tag->getPK()]);
			$this->pageRepository->syncOne($values['page']);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$tag]);
		};

		return $form;
	}

	public function renderDefault()
	{
		$this->template->headerLabel = 'Tagy';
		$this->template->headerTree = [
			['Tagy'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Tagy', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail()
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Tagy', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(Tag $tag)
	{
		/** @var Form $form */
		$form = $this->getComponent('newForm');

		$form->setDefaults($tag->toArray(['similar']));
	}
}