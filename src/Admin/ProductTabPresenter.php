<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\Producer;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductTab;
use Eshop\DB\ProductTabRepository;
use Forms\Form;
use Nette\Http\Request;
use Nette\Utils\Image;
use Pages\DB\PageRepository;
use Pages\Helpers;
use StORM\DIConnection;
use StORM\Entity;

class ProductTabPresenter extends BackendPresenter
{
	/** @inject */
	public ProducerRepository $producerRepository;

	/** @inject */
	public PageRepository $pageRepository;

	/** @inject */
	public Request $request;

	/** @inject */
	public ProductTabRepository $productTabRepository;


	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->productTabRepository->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();


		$grid->addColumnText('Název', 'name', '%s', 'name');


		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);

		//$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

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
		$nameInput = $form->addLocaleText('name', 'Název');


		//$producer = $this->getParameter('producer');


		//$form->addLocaleRichEdit('content', 'Obsah');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		//$form->addCheckbox('recommended', 'Doporučeno');
		//$form->addCheckbox('hidden', 'Skryto');
		//$form->addPageContainer('product_list', ['producer' => $this->getParameter('producer')], $nameInput);

		//$form->addSubmits(!$producer);
		$form->addSubmits();

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');


			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$tab = $this->productTabRepository->syncOne($values, null, true);

			/*$form->syncPages(function () use ($producer, $values): void {
				$values['page']['params'] = Helpers::serializeParameters(['producer' => $producer->getPK()]);
				$this->pageRepository->syncOne($values['page']);
			});*/

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$tab]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Textové záložky produktů';
		$this->template->headerTree = [
			['Záložky produktů'],
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

	public function actionDetail(ProductTab $tab): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');
		$form->setDefaults($tab->toArray());
	}
}
