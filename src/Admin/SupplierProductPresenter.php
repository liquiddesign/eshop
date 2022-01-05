<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\BackendPresenter;
use Eshop\DB\PricelistRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\SupplierProduct;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\Integration\Algolia;
use Forms\Form;
use StORM\Expression;
use StORM\ICollection;

class SupplierProductPresenter extends BackendPresenter
{
	/** @persistent */
	public ?string $tab = null;

	/**
	 * @var string[]
	 */
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

	/** @inject */
	public Algolia $algolia;

	public function beforeRender(): void
	{
		parent::beforeRender();

		$this->tabs = $this->supplierRepository->getArrayForSelect();
	}

	public function createComponentGrid(): AdminGrid
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
			$link = $supplierProduct->product && $this->admin->isAllowed(':Eshop:Admin:Product:edit') ?
				$datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$supplierProduct->product, 'backLink' => $this->storeRequest(),]) : '#';

			return $supplierProduct->product ? "<a href='$link'>" . $supplierProduct->product->getFullCode() . "</a>" : "-ne-";
		}, '%s', 'product');


		$grid->addColumnInputCheckbox('<span title="Aktivní">Aktivní</span>', 'active', 'active', '', 'this.active');


		$grid->addColumn('', function ($object, $grid) {
			return $grid->getPresenter()->link('detail', $object);
		}, '<a href="%s" class="btn btn-sm btn-outline-primary">Napárovat ručně</a>');

		if ($this->algolia->isActive()) {
			$grid->addColumn('', function ($object, $grid) {
				return $grid->getPresenter()->link('detailAlgolia', $object);
			}, '<a href="%s" class="btn btn-sm btn-outline-primary">Napárovat Algolia</a>');
		}

		//$grid->addColumnLinkDetail('detail');
		$grid->addButtonSaveAll();

		$grid->addFilterTextInput('search', ['this.ean', 'this.code'], null, 'EAN, kód');
		$grid->addFilterTextInput('q', ['this.name'], null, 'Název produktu');

		$grid->addFilterText(function (ICollection $source, $value): void {
			$parsed = \explode('>', $value);
			$expression = new Expression();

			for ($i = 1; $i !== 5; $i++) {
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
				'url' => $this->link('getProductsForSelect2!'),
			],
			'placeholder' => 'Zvolte produkt',
		]);

		$form->addSubmits(false, false);

		$form->onValidate[] = function (AdminForm $form): void {
			$data = $this->getHttpRequest()->getPost();

			if (isset($data['productFullCode'])) {
				return;
			}

			/** @var \Nette\Forms\Controls\SelectBox $input */
			$input = $form['productFullCode'];
			$input->addError('Toto pole je povinné!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
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

	public function createComponentPairAlgoliaForm(): AdminForm
	{
		$form = $this->formFactory->create();

		/** @var \Eshop\DB\SupplierProduct|null $supplierProduct */
		$supplierProduct = $this->getParameter('supplierProduct');
		$algoliaResults = $this->algolia->searchProduct($supplierProduct->name, 'products');
		$results = [];

		foreach ($algoliaResults['hits'] as $result) {
			$results[$result['objectID']] = $result['name_cs'];
		}

		$form->addSelect2('product', 'Párovat k produktu', $results)->setRequired();

		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$supplierProduct = $this->getParameter('supplierProduct');

			$product = $this->productRepository->one($values['product']);

			$update = [
				'productCode' => $product->code,
				'product' => $product,
			];

			if ($product->ean) {
				$update['ean'] = $product->ean;
			}

			try {
				$supplierProduct->update($update);
			} catch (\PDOException $e) {
			}

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detailAlgolia', 'default', [$supplierProduct]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Externí produkty';

		$this->template->headerTree = [
			['Externí produkty'],
		];

		$this->template->tabs = $this->tabs;

		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Externí produkty', 'default'],
			['Nová položka'],
		];

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function renderDetail(SupplierProduct $supplierProduct): void
	{
		unset($supplierProduct);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Externí produkty', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('pairForm')];
	}

	public function renderDetailAlgolia(SupplierProduct $supplierProduct): void
	{
		unset($supplierProduct);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Externí produkty', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('pairAlgoliaForm')];
	}

	protected function startup(): void
	{
		// TODO: Change the autogenerated stub
		parent::startup();

		if ($this->tab) {
			return;
		}

		$this->tab = \key($this->supplierRepository->getArrayForSelect());
	}
}
