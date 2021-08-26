<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\DB\CategoryRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\InternalRibbonRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\RibbonRepository;
use Eshop\DB\SupplierCategoryRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\TagRepository;
use Eshop\Shopper;
use Web\DB\PageRepository;
use Grid\Datagrid;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use Admin\Controls\AdminGridFactory;

class ProductGridFactory
{
	private ProductRepository $productRepository;

	private AdminGridFactory $gridFactory;

	private ProducerRepository $producerRepository;

	private SupplierRepository $supplierRepository;

	private SupplierCategoryRepository $supplierCategoryRepository;

	private CategoryRepository $categoryRepository;

	private RibbonRepository $ribbonRepository;

	private InternalRibbonRepository $internalRibbonRepository;

	private TagRepository $tagRepository;

	private PageRepository $pageRepository;

	private Container $container;

	private PricelistRepository $pricelistRepository;

	private DisplayAmountRepository $displayAmountRepository;

	private Shopper $shopper;

	private ProductGridFiltersFactory $productGridFiltersFactory;

	public function __construct(
		\Admin\Controls\AdminGridFactory $gridFactory,
		Container $container,
		PageRepository $pageRepository,
		ProductRepository $productRepository,
		ProducerRepository $producerRepository,
		SupplierRepository $supplierRepository,
		SupplierCategoryRepository $supplierCategoryRepository,
		CategoryRepository $categoryRepository,
		RibbonRepository $ribbonRepository,
		InternalRibbonRepository $internalRibbonRepository,
		TagRepository $tagRepository,
		PricelistRepository $pricelistRepository,
		DisplayAmountRepository $displayAmountRepository,
		Shopper $shopper,
		ProductGridFiltersFactory $productGridFiltersFactory
	)
	{
		$this->productRepository = $productRepository;
		$this->gridFactory = $gridFactory;
		$this->producerRepository = $producerRepository;
		$this->supplierRepository = $supplierRepository;
		$this->supplierCategoryRepository = $supplierCategoryRepository;
		$this->categoryRepository = $categoryRepository;
		$this->ribbonRepository = $ribbonRepository;
		$this->internalRibbonRepository = $internalRibbonRepository;
		$this->tagRepository = $tagRepository;
		$this->pageRepository = $pageRepository;
		$this->container = $container;
		$this->pricelistRepository = $pricelistRepository;
		$this->displayAmountRepository = $displayAmountRepository;
		$this->productGridFiltersFactory = $productGridFiltersFactory;
	}

	public function create(array $configuration): Datagrid
	{
		$source = $this->productRepository->many()->setGroupBy(['this.uuid'])
			->join(['photo' => 'eshop_photo'], 'this.uuid = photo.fk_product')
			->join(['file' => 'eshop_file'], 'this.uuid = file.fk_product')
			->join(['comment' => 'eshop_internalcommentproduct'], 'this.uuid = comment.fk_product')
			->select([
				'photoCount' => "COUNT(DISTINCT photo.uuid)",
				'fileCount' => "COUNT(DISTINCT file.uuid)",
				'commentCount' => 'COUNT(DISTINCT comment.uuid)'
			]);

		$grid = $this->gridFactory->create($source, 20, 'this.priority', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnImage('imageFileName', Product::GALLERY_DIR);

		$grid->addColumn('Kód a EAN', function (Product $product) {
			return $product->getFullCode() . ($product->ean ? "<br><small>EAN $product->ean</small>" : '');
		}, '%s', 'code', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumn('Název', function (Product $product, $grid) {
			$suppliers = [];

			foreach ($product->supplierProducts as $supplierProduct) {
				$supplier = $supplierProduct->getValue('supplier');
				$code = $supplierProduct->code;
				$link = $grid->getPresenter()->link(':Eshop:Admin:SupplierProduct:default', ['tab' => $supplier, 'grid-search' => $code]);
				$suppliers[] = "<a href='$link' class='badge badge-light' style='font-weight: normal;' target='_blank'>$supplier</a>";
			}

			return [$grid->getPresenter()->link(':Eshop:Product:detail', ['product' => (string)$product]), $product->name, \implode(' &nbsp;', $suppliers)];
		}, '<a href="%s" target="_blank"> %s</a> <a href="" class="badge badge-light" style="font-weight: normal;">%s</a>', 'name');

		$grid->addColumnText('Výrobce', 'producer.name', '%s', 'producer.name_cs');
		$grid->addColumn('Kategorie', function (Product $product) {
			//return $product->primaryCategory->name;
			return \implode('&nbsp;|&nbsp;', $product->categories->toArrayOf('name'));
		});
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');
		$grid->addColumnInputCheckbox('<i title="Neprodejné" class="fas fa-ban"></i>', 'unavailable', '', '', 'unavailable');

//		if ($configuration['parameters']) {
//			$grid->addColumnLink('Parameters', 'Atributy');
//		}

		$grid->addColumnLink('Prices', 'Ceny');
		$grid->addColumn(null, function (Product $product, $grid) {
			return '<a class="btn btn-outline-primary btn-sm text-xs" style="white-space: nowrap" href="' . $grid->getPresenter()->link('photos', $product) . '"><i title="Obrázky" class="far fa-file-image"></i>&nbsp;' . $product->photoCount . '</a>';
		});
		$grid->addColumn(null, function (Product $product, $grid) {
			return '<a class="btn btn-outline-primary btn-sm text-xs" style="white-space: nowrap" href="' . $grid->getPresenter()->link('files', $product) . '"><i title="Soubory" class="far fa-file"></i>&nbsp;' . $product->fileCount . '</a>';
		});
		$grid->addColumn(null, function (Product $product, $grid) {
			return '<a class="btn btn-outline-primary btn-sm text-xs" style="white-space: nowrap" href="' . $grid->getPresenter()->link('comments', $product) . '"><i title="Komentáře" class="far fa-comment"></i>&nbsp;' . $product->commentCount . '</a>';
		});

		$grid->addColumnLinkDetail('edit');
		$grid->addColumnActionDelete([$this, 'onDelete']);

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected([$this, 'onDelete'], false, null, 'this.uuid');

		$bulkColumns = ['producer', 'categories', 'ribbons', 'internalRibbons', 'displayAmount', 'displayDelivery', 'vatRate', 'taxes', 'hidden', 'unavailable'];

		if (isset($configuration['buyCount']) && $configuration['buyCount']) {
			$bulkColumns = \array_merge($bulkColumns, ['buyCount']);
		}

		$grid->addButtonBulkEdit('productForm', $bulkColumns, 'productGrid');

		$submit = $grid->getForm()->addSubmit('join', 'Sloučit')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid) {
			$grid->getPresenter()->redirect('joinSelect', [$grid->getSelectedIds()]);
		};

		if (isset($configuration['buyCount']) && $configuration['buyCount']) {
			$submit = $grid->getForm()->addSubmit('generateRandomBuyCounts', 'Generovat zakoupení')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid) {
				$grid->getPresenter()->redirect('generateRandomBuyCounts', [$grid->getSelectedIds()]);
			};
		}

		if (isset($configuration['exportButton']) && $configuration['exportButton']) {
			$submit = $grid->getForm()->addSubmit('export', 'Exportovat (CSV)')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid) {
				$grid->getPresenter()->redirect('export', [$grid->getSelectedIds()]);
			};
		}

		$submit = $grid->getForm()->addSubmit('newsletterExport', 'Newsletter export')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid) {
			$grid->getPresenter()->redirect('newsletterExportSelect', [$grid->getSelectedIds()]);
		};

		$this->productGridFiltersFactory->addFilters($grid);
		$grid->addFilterButtons();

		return $grid;
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
