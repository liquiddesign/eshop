<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\DB\CategoryRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\InternalRibbon;
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
		Shopper $shopper
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
	}

	public function create(array $configuration): Datagrid
	{
		$source = $this->productRepository->many()->setGroupBy(['this.uuid'])
			->join(['photo' => 'eshop_photo'], 'this.uuid = photo.fk_product')
			->join(['file' => 'eshop_file'], 'this.uuid = file.fk_product')
			->select(['photoCount' => "COUNT(DISTINCT photo.uuid)"])
			->select(['fileCount' => "COUNT(DISTINCT file.uuid)"]);

		$grid = $this->gridFactory->create($source, 20, 'this.priority', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnImage('imageFileName', Product::IMAGE_DIR);

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

		if ($configuration['parameters']) {
			$grid->addColumnLink('Parameters', 'Atributy');
		}

		$grid->addColumnLink('Prices', 'Ceny');
		$grid->addColumn(null, function (Product $product, $grid) {
			return '<a class="btn btn-outline-primary btn-sm text-xs" style="white-space: nowrap" href="' . $grid->getPresenter()->link('photos', $product) . '"><i title="Obrázky" class="far fa-file-image"></i>&nbsp;' . $product->photoCount . '</a>';
		});
		$grid->addColumn(null, function (Product $product, $grid) {
			return '<a class="btn btn-outline-primary btn-sm text-xs" style="white-space: nowrap" href="' . $grid->getPresenter()->link('files', $product) . '"><i title="Soubory" class="far fa-file"></i>&nbsp;' . $product->fileCount . '</a>';
		});

		$grid->addColumnLinkDetail('edit');
		$grid->addColumnActionDelete([$this, 'onDelete']);

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected([$this, 'onDelete']);

		$bulkColumns = ['producer', 'categories', 'tags', 'ribbons', 'displayAmount', 'displayDelivery', 'vatRate', 'taxes', 'hidden', 'unavailable'];

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

		$this->addFilters($grid);
		$grid->addFilterButtons();

		return $grid;
	}

	private static function replaceArrayValue(array $array, $value, $replace): array
	{
		return \array_replace($array, \array_fill_keys(\array_keys($array, $value), $replace));
	}

	private function addFilters(Datagrid $grid)
	{
		$grid->addFilterTextInput('code', ['this.code', 'this.ean', 'this.name_cs'], null, 'Název, EAN, kód', '', '%s%%');

		if ($categories = $this->categoryRepository->getTreeArrayForSelect()) {
			$categories += ['0' => 'X - bez kategorie'];
			$grid->addFilterDataSelect(function (Collection $source, $value) {
				$source->filter(['category' => $value === '0' ? false : $this->categoryRepository->one($value)->path]);
			}, '', 'category', null, $categories)->setPrompt('- Kategorie -');
		}

		if ($producers = $this->producerRepository->getArrayForSelect()) {
			$producers += ['0' => 'X - bez výrobce'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value) {
				$source->filter(['producer' => self::replaceArrayValue($value, '0', null)]);
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

		if ($supplierCategories = $this->supplierCategoryRepository->getArrayForSelect(true)) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$source->where('supplierProducts.fk_category', $value);
			}, '', 'supplier_categories', null, $supplierCategories, ['placeholder' => '- Rozřazení -']);

		}

		/*if ($tags = $this->tagRepository->getListForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$this->productRepository->filterTag($value, $source);
			}, '', 'tags', null, $tags, ['placeholder' => '- Tagy -']);
		}*/

		if ($ribbons = $this->ribbonRepository->getArrayForSelect()) {
			$ribbons += ['0' => 'X - bez štítků'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value) {
				$source->filter(['ribbon' => self::replaceArrayValue($value, '0', null)]);
			}, '', 'ribbons', null, $ribbons, ['placeholder' => '- Veř. štítky -']);
		}
		
		if ($ribbons = $this->internalRibbonRepository->getArrayForSelect()) {
			$ribbons += ['0' => 'X - bez štítků'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value) {
				$source->filter(['internalRibbon' => self::replaceArrayValue($value, '0', null)]);
			}, '', 'internalRibbon', null, $ribbons, ['placeholder' => '- Int. štítky -']);
		}

		if ($pricelists = $this->pricelistRepository->getArrayForSelect()) {
			$pricelists += ['0' => 'X - bez ceniků'];
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$source->filter(['pricelist' => self::replaceArrayValue($value, '0', null)]);
			}, '', 'pricelists', null, $pricelists, ['placeholder' => '- Ceníky -']);
		}

		$grid->addFilterDataSelect(function (ICollection $source, $value) {
			if ($value === 'image') {
				$source->where('this.imageFileName IS NOT NULL');
			}

			if ($value === 'noimage') {
				$source->where('this.imageFileName IS NULL');
			}

			if ($value === 'fiximage') {
				$source->where('this.imageFileName IS NOT NULL AND this.imageNeedFix = 1');
			}

			if ($value === 'ean') {
				$source->where('this.ean IS NOT NULL');
			}

			if ($value === 'noean') {
				$source->where('this.ean IS NULL');
			}

			if ($value === 'content') {
				$source->where("this.content_cs IS NULL OR this.content_cs=''");
			}

			if ($value === 'fixcontent') {
				$thresholdLength = 1000;
				$suffix = '_cs';
				$expression = new Expression();
				$expression->add('AND', "LOCATE(%s, this.content$suffix)=0", ['<div>']);
				$expression->add('AND', "LOCATE(%s, this.content$suffix)=0", ['<br>']);
				$expression->add('AND', "LOCATE(%s, this.content$suffix)=0", ['<p>']);
				$expression->add('AND', "LOCATE(%s, this.content$suffix)=0", ['<table>']);

				$source->where("LENGTH(this.content$suffix) > :length", ['length' => $thresholdLength])->where($expression->getSql(), $expression->getVars());
			}
		}, '', 'image', null, [
			'image' => 'S obrázkem',
			'noimage' => 'Bez obrázku',
			'fiximage' => 'Chybný obrázek',
			'ean' => 'S EANem',
			'noean' => 'Bez EANu',
			'content' => 'S obsahem',
			'nocontent' => 'Bez obsahu',
			'fixcontent' => 'Chybný text',
		])->setPrompt('- Obsah -');

		if ($displayAmounts = $this->displayAmountRepository->getArrayForSelect()) {
			$displayAmounts += ['0' => 'X - nepřiřazená'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value) {
				$source->filter(['displayAmount' => self::replaceArrayValue($value, '0', null)]);
			}, '', 'displayAmount', null, $displayAmounts, ['placeholder' => '- Dostupnost -']);
		}

		$grid->addFilterDataSelect(function (ICollection $source, $value) {
			$source->where('this.hidden', (bool)$value);
		}, '', 'hidden', null, ['1' => 'Skryté', '0' => 'Viditelné'])->setPrompt('- Viditelnost -');

		$grid->addFilterDataSelect(function (ICollection $source, $value) {
			$source->where('this.unavailable', (bool)$value);
		}, '', 'unavailable', null, ['1' => 'Neprodejné', '0' => 'Prodejné'])->setPrompt('- Prodejnost -');
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
