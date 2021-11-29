<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminGridFactory;
use Eshop\DB\CategoryRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Grid\Datagrid;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use StORM\Connection;
use Web\DB\PageRepository;

class ProductGridFactory
{
	private ProductRepository $productRepository;

	private AdminGridFactory $gridFactory;

	private CategoryRepository $categoryRepository;

	private PageRepository $pageRepository;

	private Container $container;

	private ProductGridFiltersFactory $productGridFiltersFactory;

	private Connection $connection;

	public function __construct(
		\Admin\Controls\AdminGridFactory $gridFactory,
		Container $container,
		PageRepository $pageRepository,
		ProductRepository $productRepository,
		CategoryRepository $categoryRepository,
		ProductGridFiltersFactory $productGridFiltersFactory,
		Connection $connection
	) {
		$this->gridFactory = $gridFactory;
		$this->categoryRepository = $categoryRepository;
		$this->pageRepository = $pageRepository;
		$this->container = $container;
		$this->productGridFiltersFactory = $productGridFiltersFactory;
		$this->productRepository = $productRepository;
		$this->connection = $connection;
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
				'commentCount' => 'COUNT(DISTINCT comment.uuid)',
			]);

		$grid = $this->gridFactory->create($source, 20, 'this.priority', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnImage('imageFileName', Product::GALLERY_DIR);

		$grid->addColumn('Kód a EAN', function (Product $product) {
			return $product->getFullCode() . ($product->ean ? "<br><small>EAN $product->ean</small>" : '');
		}, '%s', 'code', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumn('Název', function (Product $product, $grid) {
			$suppliers = [];

			/** @var \Eshop\DB\SupplierProduct $supplierProduct */
			foreach ($product->supplierProducts as $supplierProduct) {
				$supplier = $supplierProduct->getValue('supplier');
				$code = $supplierProduct->code;
				$link = $grid->getPresenter()->link(':Eshop:Admin:SupplierProduct:default', ['tab' => $supplier, 'grid-search' => $code]);

				$suppliers[] = "<a href='$link' class='badge badge-light' style='font-weight: normal;' target='_blank'>" .
					($supplierProduct->supplier->url ? $supplierProduct->supplier->name : $supplier) . "</a>";
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

		$grid->addColumnLinkDetail('edit');
		$grid->addColumnActionDelete([$this, 'onDelete']);

		$grid->addButtonSaveAll([], [], null, false, null, null, true, null, function (): void {
			$this->categoryRepository->clearCategoriesCache();
		});
		$grid->addButtonDeleteSelected([$this, 'onDelete'], false, null, 'this.uuid');

		$bulkColumns = ['producer', 'categories', 'ribbons', 'internalRibbons', 'displayAmount', 'displayDelivery', 'vatRate', 'taxes', 'hidden', 'unavailable', 'discountLevelPct'];

		if (isset($configuration['buyCount']) && $configuration['buyCount']) {
			$bulkColumns = \array_merge($bulkColumns, ['buyCount']);
		}

		$grid->addButtonBulkEdit(
			'productForm',
			$bulkColumns,
			'productGrid',
			'bulkEdit',
			'Hromadná úprava',
			'bulkEdit',
			'default',
			null,
			function ($id, Product $object, $values, $relations) {
				$allCategories = [];

				foreach ($relations as $relationName => $categories) {
					$name = \explode('_', $relationName);

					if (\count($name) !== 2 || $name[0] !== 'categories') {
						continue;
					}

					$this->connection->rows(['nxn' => 'eshop_product_nxn_eshop_category'])
						->join(['category' => 'eshop_category'], 'nxn.fk_category = category.uuid')
						->where('category.fk_type', $name[1])
						->where('nxn.fk_product', $id)
						->delete();

					unset($relations[$relationName]);

					if (\count($categories) === 0) {
						continue;
					}

					$allCategories += $categories;

					$object->categories->relate($categories);
				}

				$values['values']['primaryCategory'] = \count($allCategories) > 0 ? Arrays::first($allCategories) : null;

				return [$values, $relations];
			},
		);

		$submit = $grid->getForm()->addSubmit('join', 'Sloučit')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid): void {
			$grid->getPresenter()->redirect('joinSelect', [$grid->getSelectedIds()]);
		};

		if (isset($configuration['buyCount']) && $configuration['buyCount']) {
			$submit = $grid->getForm()->addSubmit('generateRandomBuyCounts', 'Generovat zakoupení')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid): void {
				$grid->getPresenter()->redirect('generateRandomBuyCounts', [$grid->getSelectedIds()]);
			};
		}

		if (isset($configuration['exportButton']) && $configuration['exportButton']) {
			$submit = $grid->getForm()->addSubmit('export', 'Exportovat (CSV)')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid): void {
				$grid->getPresenter()->redirect('export', [$grid->getSelectedIds()]);
			};
		}

		$submit = $grid->getForm()->addSubmit('newsletterExport', 'Newsletter export')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid): void {
			$grid->getPresenter()->redirect('newsletterExportSelect', [$grid->getSelectedIds()]);
		};

		$this->productGridFiltersFactory->addFilters($grid);
		$grid->addFilterButtons();

		return $grid;
	}

	public function onDelete(Product $product): void
	{
		/** @var \Web\DB\Page|null $page */
		$page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, ['product' => $product]);

		if ($page) {
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

		$this->categoryRepository->clearCategoriesCache();
	}
}
