<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Base\ShopsConfig;
use Eshop\Common\Helpers;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductPrimaryCategoryRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\VisibilityListRepository;
use Eshop\Integration\Integrations;
use Grid\Datagrid;
use Nette\DI\Container;
use Nette\Forms\Controls\Checkbox;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use StORM\DIConnection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\PageRepository;

class ProductGridFactory
{
	public function __construct(
		protected readonly \Admin\Controls\AdminGridFactory $gridFactory,
		protected readonly Container $container,
		protected readonly PageRepository $pageRepository,
		protected readonly ProductRepository $productRepository,
		protected readonly ProductGridFiltersFactory $productGridFiltersFactory,
		protected readonly DIConnection $connection,
		protected readonly CategoryRepository $categoryRepository,
		protected readonly SupplierProductRepository $supplierProductRepository,
		protected readonly CategoryTypeRepository $categoryTypeRepository,
		protected readonly Integrations $integrations,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly VisibilityListRepository $visibilityListRepository,
		protected readonly ProductPrimaryCategoryRepository $productPrimaryCategoryRepository
	) {
	}

	public function create(array $configuration): Datagrid
	{
		$visibilityListsCollection = $this->visibilityListRepository->getCollection();

		if ($selectedShop = $this->shopsConfig->getSelectedShop()) {
			$visibilityListsCollection->where('this.fk_shop', $selectedShop->getPK());
		}

		$source = $this->productRepository->many()
			->setSmartJoin(false)
			->setGroupBy(['this.uuid'])
			->join(['photo' => 'eshop_photo'], 'this.uuid = photo.fk_product')
			->join(['file' => 'eshop_file'], 'this.uuid = file.fk_product')
			->join(['comment' => 'eshop_internalcommentproduct'], 'this.uuid = comment.fk_product')
			->join(['price' => 'eshop_price'], 'this.uuid = price.fk_product')
			->join(['pricelist' => 'eshop_pricelist'], 'pricelist.uuid=price.fk_pricelist')
			->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'nxnCategory.fk_product = this.uuid')
			->join(['visibilityListItem' => 'eshop_visibilitylistitem'], 'visibilityListItem.fk_product = this.uuid')
			->join(['visibilityList' => 'eshop_visibilitylist'], 'visibilityListItem.fk_visibilityList = visibilityList.uuid')
			->join(['primaryCategory' => 'eshop_productprimarycategory'], 'primaryCategory.fk_product = this.uuid')
			->where('visibilityList.uuid IN(:visibilityListIn) OR visibilityList.uuid IS NULL', [
				'visibilityListIn' => Helpers::arrayToSqlInStatement($visibilityListsCollection->toArrayOf('uuid', toArrayValues: true)),
			])
			->select([
				'photoCount' => 'COUNT(DISTINCT photo.uuid)',
				'fileCount' => 'COUNT(DISTINCT file.uuid)',
				'commentCount' => 'COUNT(DISTINCT comment.uuid)',
				'priceCount' => 'COUNT(DISTINCT price.uuid)',
				'categoryCount' => 'COUNT(DISTINCT nxnCategory.fk_category)',
				'pricelistActive' => 'MAX(pricelist.isActive)',
				'hidden' => "SUBSTRING_INDEX(GROUP_CONCAT(visibilityListItem.hidden ORDER BY visibilityList.priority), ',', 1)",
				'unavailable' => "SUBSTRING_INDEX(GROUP_CONCAT(visibilityListItem.unavailable ORDER BY visibilityList.priority), ',', 1)",
				'primaryCategoryPKs' => 'GROUP_CONCAT(primaryCategory.fk_category)',
			]);

		$grid = $this->gridFactory->create($source, 20, 'this.uuid', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumn('', function (Product $object, Datagrid $datagrid) {
			if ($object->isHidden()) {
				$label = 'Neviditelný: Skrytý';
				$color = 'danger';
			} elseif ($object->getValue('priceCount') === 0) {
				$label = 'Neviditelný: Bez ceny';
				$color = 'danger';
			} elseif ($object->getValue('pricelistActive') === 0) {
				$label = 'Neviditelný: Žádné aktivní ceny';
				$color = 'danger';
			} elseif ($object->isUnavailable()) {
				$label = 'Viditelný: Neprodejný';
				$color = 'warning';
			} elseif ($object->getValue('categoryCount') === 0) {
				$label = 'Viditelný: Bez kategorie';
				$color = 'warning';
			} else {
				$label = 'Viditelný';
				$color = 'success';
			}

			return '<i title="' . $label . '" class="fa fa-circle fa-sm text-' . $color . '">';
		}, '%s', null, ['class' => 'fit']);
		$grid->addColumnImage('imageFileName', Product::GALLERY_DIR);

		$grid->addColumn('Kód a EAN', function (Product $product) {
			return $product->getFullCode() . ($product->ean ? "<br><small>EAN $product->ean</small>" : '') . ($product->mpn ? "<br><small>P/N $product->mpn</small>" : '');
		}, '%s', 'code', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumn('Název', function (Product $product, $grid) {
			$suppliers = [];

			if ($product->masterProduct) {
				$link = $grid->getPresenter()->link(':Eshop:Admin:Product:default', ['productGrid-code' => $product->masterProduct->code]);

				$suppliers[] = "<a href='$link' class='badge badge-secondary' style='font-weight: normal;'><i class='fas fa-angle-up fa-sm mr-1'></i>" . $product->masterProduct->code . '</a>';
			}

			$mergedProducts = $product->getAllMergedProducts();
			$mergedProductsCodes = null;

			foreach ($mergedProducts as $mergedProduct) {
				$mergedProductsCodes .= $mergedProduct->code . ',';
			}

			if ($mergedProductsCodes) {
				$mergedProductsCodes = Strings::substring($mergedProductsCodes, 0, -1);

				$suppliers[] = "<a href='#' class='badge badge-secondary' style='font-weight: normal;'><i class='fas fa-angle-down fa-sm mr-1'></i>" . $mergedProductsCodes . '</a>';
			}

			$productSuppliers = $product->supplierProducts->setIndex('this.fk_supplier')->toArray();

			if ($product->supplierSource && !isset($productSuppliers[$product->getValue('supplierSource')])) {
				$suppliers[] = "<a href='#' class='badge badge-light' style='font-weight: normal;'>" . $product->supplierSource->name . '</a>';
			}

			/** @var \Eshop\DB\SupplierProduct $supplierProduct */
			foreach ($productSuppliers as $supplierProduct) {
				$supplier = $supplierProduct->getValue('supplier');
				$code = $supplierProduct->code;
				$link = $grid->getPresenter()->link(':Eshop:Admin:SupplierProduct:default', ['tab' => $supplier, 'grid-search' => $code]);

				$suppliers[] = "<a href='$link' class='badge badge-light' style='font-weight: normal;' target='_blank'>" .
					($supplierProduct->supplier->url ? $supplierProduct->supplier->name : $supplier) . '</a>';
			}

			foreach ($mergedProducts as $mergedProduct) {
				$mergedProductSuppliers = $mergedProduct->supplierProducts->toArray();

				foreach ($mergedProductSuppliers as $supplierProduct) {
					$supplierRealProduct = $supplierProduct->product;
					$supplier = $supplierProduct->getValue('supplier');
					$link = $grid->getPresenter()->link('this', ['productGrid-code' => $mergedProduct->code]);

					$suppliers[] = "<a href='$link' class='badge badge-light' style='font-weight: normal;' target='_blank' title='{$supplierRealProduct->getFullCode()} - $supplierRealProduct->name'>
<i class='fas fa-level-down-alt fa-sm mr-1'></i>" . ($supplierProduct->supplier->url ? $supplierProduct->supplier->name : $supplier) . '</a>';
				}
			}

			$tempProduct = $product;

			while ($masterProduct = $tempProduct->masterProduct) {
				$mergedProductSuppliers = $masterProduct->supplierProducts->toArray();

				foreach ($mergedProductSuppliers as $supplierProduct) {
					$supplierRealProduct = $supplierProduct->product;
					$supplier = $supplierProduct->getValue('supplier');
					$link = $grid->getPresenter()->link('this', ['productGrid-code' => $masterProduct->code]);

					$suppliers[] = "<a href='$link' class='badge badge-light' style='font-weight: normal;' target='_blank' title='{$supplierRealProduct->getFullCode()} - $supplierRealProduct->name'>
<i class='fas fa-level-up-alt fa-sm mr-1'></i>" . ($supplierProduct->supplier->url ? $supplierProduct->supplier->name : $supplier) . '</a>';
				}

				$tempProduct = $masterProduct;
			}

			$ribbons = null;

			foreach ($product->ribbons as $ribbon) {
				$ribbons .= "<div class=\"badge\" style=\"font-weight: normal; background-color: $ribbon->backgroundColor; color: $ribbon->color\">$ribbon->name</div> ";
			}

			foreach ($product->internalRibbons as $ribbon) {
				$ribbons .= "<div class=\"badge\" style=\"font-weight: normal; font-style: italic; background-color: $ribbon->backgroundColor; color: $ribbon->color\">$ribbon->name</div> ";
			}

			if (!$product->imageFileName) {
				$ribbons .= '<div class="badge" style="font-weight: normal; font-style: italic; background-color: orangered; color: white;">chybí hlavní obrázek</div> ';
			}

			return [
				$grid->getPresenter()->link(':Eshop:Product:detail', ['product' => (string) $product]),
				$product->name,
				\implode(' &nbsp;', $suppliers),
				$ribbons,
			];
		}, '<a href="%s" target="_blank"> %s</a> <a href="" class="badge badge-light" style="font-weight: normal;">%s</a> %s', 'name');

		$mutationSuffix = $this->categoryRepository->getConnection()->getMutationSuffix();

		$grid->addColumnText('Výrobce', 'producer.name', '%s');
		$grid->addColumn('Kategorie', function (Product $product, $grid) use ($mutationSuffix) {
			$categories = $this->categoryRepository->getTreeArrayForSelect();
			/** @var array<string> $productCategories */
			$productCategories = $product->categories->orderBy(['LENGTH(this.path)', "this.name$mutationSuffix"])->toArrayOf('name');

			$finalStr = '';
			$last = Arrays::last(\array_keys($productCategories));
			$primaryCategories = \explode(',', $product->getValue('primaryCategoryPKs') ?? '');

			foreach ($productCategories as $productCategoryPK => $productCategoryName) {
				$finalStr .= '<abbr title="' . $categories[$productCategoryPK] . '">';
				$finalStr .= $productCategoryName;
				$finalStr .= '</abbr>';
				$finalStr .= Arrays::contains($primaryCategories, $productCategoryPK) ?
					'&nbsp;<i class="fas fa-star fa-sm"></i>' :
					'<i class="far fa-star fa-sm"></i></a>';
				$finalStr .= $last !== $productCategoryPK ? '&nbsp;|&nbsp;' : null;
			}

			return $finalStr;
		});
		
		$grid->addColumnText('Sleva', 'discountLevelPct', '%s %%', 'discountLevelPct', ['class' => 'fit']);
		$grid->addColumn('Obsah', function (Product $object, Datagrid $datagrid) {
			if ($object->supplierContentLock && $object->getContent()) {
				$label = 'Vlastní obsah';
				$icon = 'fas fa-file-alt';
			} elseif ($object->supplierContentLock && !$object->getContent()) {
				$label = 'Žádný obsah';
				$icon = 'fas fa-file-excel';
			} elseif ($object->supplierContentMode === Product::SUPPLIER_CONTENT_MODE_PRIORITY || (!$object->supplierContent && $object->supplierContentMode === Product::SUPPLIER_CONTENT_MODE_NONE)) {
				$label = 'Ze zdroje s nejvyšší prioritou';
				$icon = 'fas fa-file-upload';
			} elseif ($object->supplierContent) {
				$label = 'Obsah z: ' . $object->supplierContent->name;
				$icon = 'fas fa-file-download';
			} else {
				$label = 'Neznámý stav';
				$icon = 'fas fa-question';
			}

			return '<i title="' . $label . '" class="' . $icon . ' fa-lg text-primary">';
		}, '%s', null, ['class' => 'fit']);

//		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);

//		$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');
//		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');
//		$grid->addColumnInputCheckbox('<i title="Skryto v menu a vyhledávání" class="far fa-minus-square"></i>', 'hiddenInMenu', '', '', 'hiddenInMenu');
//		$grid->addColumnInputCheckbox('<i title="Neprodejné" class="fas fa-ban"></i>', 'unavailable', '', '', 'unavailable');

		$grid->addColumnInputCheckbox('<i title="Skrýt ve všech feedech" class="fas fa-minus-circle"></i>', 'exportNone', function (Checkbox $checkbox, Product $product): void {
			$checkbox->setDisabled(!$product->exportHeureka && !$product->exportGoogle && !$product->exportZbozi);
			$checkbox->setDefaultValue(!$product->exportHeureka && !$product->exportGoogle && !$product->exportZbozi);
		});

		$grid->addColumnLinkDetail('edit');
		$grid->addColumnActionDelete([$this, 'onDelete']);

		$grid->addButtonSaveAll([], [], null, false, null, function (string $id, array &$data, Product $product): void {
			$data['exportHeureka'] = $data['exportNone'] ? false : $product->exportHeureka;
			$data['exportGoogle'] = $data['exportNone'] ? false : $product->exportGoogle;
			$data['exportZbozi'] = $data['exportNone'] ? false : $product->exportZbozi;

			unset($data['exportNone']);
		}, true, null, function (): void {
			$this->categoryRepository->clearCategoriesCache();
			$this->productRepository->clearCache();
		});
		$grid->addButtonDeleteSelected([$this, 'onDelete'], false, null, 'this.uuid');

		$bulkColumns = [
			'producer',
			'categories',
			'ribbons',
			'internalRibbons',
			'displayAmount',
			'displayDelivery',
			'vatRate',
			'taxes',
			'unavailable',
			'primaryCategories',
			'defaultReviewsCount',
			'defaultReviewsScore',
			'supplierDisplayAmountLock',
			'supplierDisplayAmountMergedLock',
			'weight',
			'width',
			'length',
			'depth',
			'dimension',
			'exportHeureka',
			'exportZbozi',
			'exportGoogle',
		];

		if (isset($configuration['isManager']) && $configuration['isManager']) {
			$bulkColumns = \array_merge($bulkColumns, ['discountLevelPct']);
		}

		if (isset($configuration['buyCount']) && $configuration['buyCount']) {
			$bulkColumns = \array_merge($bulkColumns, ['buyCount']);
		}

		if (isset($configuration['suppliers']) && $configuration['suppliers']) {
			$bulkColumns = \array_merge($bulkColumns, ['supplierContent']);
		}

		if (isset($configuration['karsa']) && $configuration['karsa']) {
			$bulkColumns = \array_merge($bulkColumns, ['karsaAllowRepricing']);
		}

		if ($this->integrations->getService(Integrations::ALGOLIA)) {
			$bulkColumns = \array_merge($bulkColumns, ['algoliaPriority']);
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
				if (isset($values['keep']['supplierContent']) && $values['keep']['supplierContent'] === false) {
					if ($values['values']['supplierContent'] === 'none') {
						$values['values']['supplierContent'] = null;
						$values['values']['supplierContentLock'] = true;
						$values['values']['supplierContentMode'] = Product::SUPPLIER_CONTENT_MODE_NONE;
					} else {
						$values['values']['supplierContentLock'] = false;
						$values['values']['supplierContentMode'] = Product::SUPPLIER_CONTENT_MODE_PRIORITY;
					}
				}

				$updatePrimaryCategoriesTypes = \array_filter($values['keep'], function ($value, $key) use (&$values): bool {
					$true = Strings::startsWith($key, 'primaryCategories_primaryCategory_') && $value === false;

					if ($true) {
						unset($values['keep'][$key]);
					}

					return $true;
				}, \ARRAY_FILTER_USE_BOTH);

				$realProductCategories = $object->categories->toArray();

				foreach (\array_keys($updatePrimaryCategoriesTypes) as $primaryCategoriesType) {
					$categoryType = \explode('_', $primaryCategoriesType)[2];
					$category = $values['values'][$primaryCategoriesType];
					unset($values['values'][$primaryCategoriesType]);

					$this->productPrimaryCategoryRepository->many()
						->where('this.fk_product', $object->getPK())
						->where('this.fk_categoryType', $categoryType)
						->delete();

					if (!isset($realProductCategories[$category])) {
						continue;
					}

					$this->productPrimaryCategoryRepository->syncOne([
						'categoryType' => $categoryType,
						'category' => $category,
						'product' => $object->getPK(),
					]);
				}

				return [$values, $relations];
			},
			[],
			function ($form): AdminForm {
				/** @var array<\Eshop\DB\CategoryType> $categoryTypes */
				$categoryTypes = $this->categoryTypeRepository->getCollection(true)->toArray();

				foreach ($categoryTypes as $categoryType) {
					/** @var \Nette\Forms\Controls\SelectBox $primaryCategorySelect */
					$primaryCategorySelect = $form['values']['primaryCategories_primaryCategory_' . $categoryType->getPK()];

					$primaryCategorySelect->setItems($this->categoryRepository->getTreeArrayForSelect(true, $categoryType->getPK()));
					$primaryCategorySelect->setPrompt(false);
				}

				return $form;
			},
		);

		$submit = $grid->getForm()->addSubmit('join', 'Sloučit')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

		$submit->onClick[] = function ($button) use ($grid): void {
			$grid->getPresenter()->redirect('mergeSelect', [$grid->getSelectedIds()]);
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
		$this->categoryRepository->clearCategoriesCache();

		$this->supplierProductRepository->many()->where('this.fk_product', $product->getPK())->update(['active' => false]);

		/** @var \Web\DB\Page|null $page */
		$page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, ['product' => $product]);

		if ($page) {
			$page->delete();
		}

		$subDirs = ['origin', 'detail', 'thumb'];
		$dir = $this->container->parameters['wwwDir'] . '/userfiles/' . Product::GALLERY_DIR;

		foreach ($product->photos as $photo) {
			foreach ($subDirs as $subDir) {
				try {
					FileSystem::delete("$dir/$subDir/$photo->fileName");
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}
			}
		}

		$product->update(['imageFileName' => null]);
	}
}
