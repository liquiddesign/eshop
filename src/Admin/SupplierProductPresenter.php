<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Eshop\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\PricelistRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\SupplierProduct;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Forms\Form;
use StORM\Expression;
use StORM\ICollection;

class SupplierProductPresenter extends BackendPresenter
{
	/** @persistent */
	public ?string $tab = null;

	public array $tabs = [];

	/** @inject */
	public SupplierProductRepository $supplierProductRepository;

	/** @inject */
	public PricelistRepository $pricelistRepository;

	/** @inject */
	public ProductRepository $productRepository;

	/** @inject */
	public ProducerRepository $producerRepository;

	/** @inject */
	public SupplierRepository $supplierRepository;

	protected function startup()
	{
		parent::startup(); // TODO: Change the autogenerated stub

		if (!$this->tab) {
			$this->tab = \key($this->supplierRepository->getArrayForSelect());
		}
	}

	public function beforeRender(): void
	{
		parent::beforeRender();

		$this->tabs = $this->supplierRepository->getArrayForSelect();
	}

	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->supplierProductRepository->many()->where('this.fk_supplier', $this->tab), 20, 'this.createdTs', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumn('Kód a EAN', function (SupplierProduct $product) {
			return $product->code . ($product->ean ? "<br><small>EAN $product->ean</small>" : '');
		}, '%s', 'updatedTs', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumnText('Název', "name", '%s', 'name');
		$grid->addColumnText('Výrobce', "producer.name", '%s');
		$grid->addColumnText('Kategorie', ['category.getNameTree'], '%s');

		$grid->addColumn('Napárovano', function (SupplierProduct $supplierProduct, AdminGrid $datagrid) {
			$link = $supplierProduct->product && $this->admin->isAllowed(':Eshop:Admin:Product:edit') ? $datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$supplierProduct->product, 'backLink' => $this->storeRequest(),]) : '#';

			return $supplierProduct->product ? "<a href='$link'>" . $supplierProduct->product->getFullCode() . "</a>" : "-ne-";
		}, '%s', 'product');


		$grid->addColumnInputCheckbox('<span title="Aktivní">Aktivní</span>', 'active', 'active', '', 'this.active');


		$grid->addColumn('', function ($object, $grid) {
			return $grid->getPresenter()->link('detail', $object);
		}, '<a href="%s" class="btn btn-sm btn-outline-primary">Napárovat</a>');

		//$grid->addColumnLinkDetail('detail');
		$grid->addButtonSaveAll();

		$grid->addFilterTextInput('search', ['this.ean', 'this.code'], null, 'EAN, kód');
		$grid->addFilterTextInput('q', ['this.name'], null, 'Název produktu');

		$grid->addFilterText(function (ICollection $source, $value) {
			$parsed = \explode('>', $value);
			$expression = new Expression();

			for ($i = 1; $i != 5; $i++) {
				if (isset($parsed[$i - 1])) {
					$expression->add('AND', "category.categoryNameL$i=%s", [\trim($parsed[$i - 1])]);
				}
			}

			$source->where('(' . $expression->getSql() . ') OR producer.name=:producer', $expression->getVars() + ['producer' => $value]);

		}, '', 'category')->setHtmlAttribute('placeholder', 'Kategorie, výrobce')->setHtmlAttribute('class', 'form-control form-control-sm');

		$grid->addFilterCheckboxInput('notmapped', "fk_product IS NOT NULL", 'Napárované');

		$grid->addButtonBulkEdit('form', ['active']);

		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addCheckbox('active', 'Aktivní');

		return $form;
	}

	public function createComponentPairForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addSelect2('productFullCode', 'Párovat k produktu', [], [
			'ajax' => [
				'url' => $this->link('getProductsForSelect2!')
			],
			'placeholder' => 'Zvolte produkt'
		]);

		$form->addSubmits(false, false);

		$form->onValidate[] = function (AdminForm $form) {
			$data = $this->getHttpRequest()->getPost();

			if (!isset($data['productFullCode'])) {
				$form['productFullCode']->addError('Toto pole je povinné!');
			}
		};

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			$supplierProduct = $this->getParameter('supplierProduct');
			$product = $this->productRepository->one($form->getHttpData(Form::DATA_TEXT, 'productFullCode'));

			$update = [
				'productCode' => $product->code,
				'product' => $product,
			];

			if ($product->ean) {
				$update['ean'] = $product->ean;
			}

			$supplierProduct->update($update);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$supplierProduct]);
		};

		return $form;
	}

	public function renderDefault()
	{
		$this->template->headerLabel = 'Externí produkty';

		$this->template->headerTree = [
			['Externí produkty'],
		];

		$this->template->tabs = $this->tabs;

		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('grid')];
	}


	public function renderNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Externí produkty', 'default'],
			['Nová položka'],
		];

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function renderDetail()
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Externí produkty', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('pairForm')];
	}

	public function actionDetail(SupplierProduct $supplierProduct)
	{
	}
}
