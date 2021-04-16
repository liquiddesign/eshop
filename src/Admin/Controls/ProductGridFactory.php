<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\DB\CategoryRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\RibbonRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\TagRepository;
use Grid\Datalist;
use Nette\Http\Session;
use StORM\Expression;
use Web\DB\PageRepository;
use Grid\Datagrid;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use StORM\Collection;
use StORM\ICollection;
use Admin\Controls\AdminGridFactory;

class ProductGridFactory
{
	private ProductRepository $productRepository;

	private AdminGridFactory $gridFactory;

	private ProducerRepository $producerRepository;

	private SupplierRepository $supplierRepository;

	private CategoryRepository $categoryRepository;

	private RibbonRepository $ribbonRepository;

	private TagRepository $tagRepository;

	private PageRepository $pageRepository;

	private Container $container;

	private PricelistRepository $pricelistRepository;

	public function __construct(
		\Admin\Controls\AdminGridFactory $gridFactory,
		Container $container,
		PageRepository $pageRepository,
		ProductRepository $productRepository,
		ProducerRepository $producerRepository,
		SupplierRepository $supplierRepository,
		CategoryRepository $categoryRepository,
		RibbonRepository $ribbonRepository,
		TagRepository $tagRepository,
		PricelistRepository $pricelistRepository
	)
	{
		$this->productRepository = $productRepository;
		$this->gridFactory = $gridFactory;
		$this->producerRepository = $producerRepository;
		$this->supplierRepository = $supplierRepository;
		$this->categoryRepository = $categoryRepository;
		$this->ribbonRepository = $ribbonRepository;
		$this->tagRepository = $tagRepository;
		$this->pageRepository = $pageRepository;
		$this->container = $container;
		$this->pricelistRepository = $pricelistRepository;
	}

	public function create(): Datagrid
	{
		$grid = $this->gridFactory->create($this->productRepository->many(), 20, 'this.priority', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnImage('imageFileName', Product::IMAGE_DIR);

		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumn('Název', function (Product $product, $grid) {
			return [$grid->getPresenter()->link(':Eshop:Product:detail', ['product' => (string)$product]), $product->name];
		}, '<a href="%s" target="_blank"> %s</a>', 'name');

		$grid->addColumnText('Výrobce', 'producer.name', '%s', 'producer.name_cs');
		$grid->addColumn('Kategorie', function (Product $product) {
			return \implode(', ', $product->categories->toArrayOf('name'));
		});
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');
		$grid->addColumnInputCheckbox('<i title="Neprodejné" class="fas fa-ban"></i>', 'unavailable', '', '', 'unavailable');

		$grid->addColumnLink('Parameters', 'Parametry');
		$grid->addColumnLink('Prices', 'Ceny');
		$grid->addColumnLink('Photos', '<i title="Obrázky" class="far fa-file-image"></i>');
		$grid->addColumnLink('Files', '<i title="Soubory" class="far fa-file"></i>');

		$grid->addColumnLinkDetail('edit');
		$grid->addColumnActionDelete([$this, 'onDelete']);

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected([$this, 'onDelete']);

		$btnSecondary = 'btn btn-sm btn-outline-primary';

		$grid->addButtonBulkEdit('productForm', ['producer', 'categories', 'tags', 'ribbons', 'displayAmount', 'displayDelivery', 'vatRate', 'taxes', 'hidden', 'unavailable'], 'productGrid');
		$submit = $grid->getForm()->addSubmit('newsletterExport', 'Newsletter export')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid) {
			$grid->getPresenter()->redirect('newsletterExportSelect', [$grid->getSelectedIds()]);
		};

		/*$submit = $grid->getForm()->addSubmit('completeMultiple2');
		$submit->setHtmlAttribute('class', $btnSecondary)->getControlPrototype()->setName('button')
			->setHtml('<i class="far fa-arrow-alt-circle-down"></i> Import produktů');
		$submit->onClick[] = [$this, 'completeOrderMultiple'];
		
		$submit = $grid->getForm()->addSubmit('completeMultiple');
		$submit->setHtmlAttribute('class', $btnSecondary)->getControlPrototype()->setName('button')
			->setHtml('<i class="far fa-arrow-alt-circle-up"></i> Export produktů');
		$submit->onClick[] = [$this, 'completeOrderMultiple'];
		
		$submit = $grid->getForm()->addSubmit('ccc');
		$submit->setHtmlAttribute('class', $btnSecondary)->getControlPrototype()->setName('button')
			->setHtml('<i class="far fa-arrow-alt-circle-down"></i> Import cen');
		$submit->onClick[] = [$this, 'completeOrderMultiple'];
		
		$submit = $grid->getForm()->addSubmit('aaaa');
		$submit->setHtmlAttribute('class', $btnSecondary)->getControlPrototype()->setName('button')
			->setHtml('<i class="far fa-arrow-alt-circle-up"></i> Export cen');
		$submit->onClick[] = [$this, 'completeOrderMultiple'];*/

		$this->addFilters($grid);
		$grid->addFilterButtons();

		return $grid;
	}

	private function addFilters(Datagrid $grid)
	{
		$grid->addFilterTextInput('code', ['this.code', 'this.ean'], null, 'EAN, kód', '', '%s%%');
		$grid->addFilterTextInput('search', ['this.name_cs'], null, 'Název');

		if ($categories = $this->categoryRepository->getTreeArrayForSelect()) {
			$grid->addFilterDataSelect(function (Collection $source, $value) {
				$source->filter(['category' => $this->categoryRepository->one($value)->path]);
			}, '', 'category', null, $categories)->setPrompt('- Kategorie -');
		}

		if ($producers = $this->producerRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$source->where('fk_producer', $value);
			}, '', 'producers', null, $producers, ['placeholder' => '- Výrobci -']);

		}

		if ($suppliers = $this->supplierRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$expression = new Expression();

				foreach ($value as $supplier) {
					$expression->add('OR', 'supplierProducts.fk_supplier=%1$s OR fk_supplierSource=%1$s', [$supplier]);
				}

				$source->where($expression->getSql(), $expression->getVars());
			}, '', 'suppliers', null, $suppliers, ['placeholder' => '- Zdroje -']);
		}

		if ($tags = $this->tagRepository->getListForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$this->productRepository->filterTag($value, $source);
			}, '', 'tags', null, $tags, ['placeholder' => '- Tagy -']);
		}

		if ($ribbons = $this->ribbonRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$this->productRepository->filterRibbon($value, $source);
			}, '', 'ribbons', null, $ribbons, ['placeholder' => '- Štítky -']);
		}

		if ($ribbons = $this->pricelistRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$this->productRepository->filterPricelist($value, $source);
			}, '', 'pricelists', null, $ribbons, ['placeholder' => '- Ceníky -']);
		}

		$grid->addFilterCheckboxInput('hidden', "hidden = 1", 'Skryté');
		$grid->addFilterCheckboxInput('unavailble', "unavailable = 1", 'Neprodejné');
	}

	public function onDelete(Product $product)
	{
		if ($page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, ['product' => $product])) {
			$page->delete();
		}

		if (!$product->imageFileName) {
			return;
		}

		$subDirs = ['origin', 'detail', 'thumb'];
		$dir = Product::IMAGE_DIR;

		foreach ($subDirs as $subDir) {
			$rootDir = $this->container->parameters['wwwDir'] . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . $dir;
			FileSystem::delete($rootDir . \DIRECTORY_SEPARATOR . $subDir . \DIRECTORY_SEPARATOR . $product->imageFileName);
		}

		$product->update(['imageFileName' => null]);
	}
}