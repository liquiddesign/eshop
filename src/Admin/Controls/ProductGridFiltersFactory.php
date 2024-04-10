<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminGrid;
use Base\ShopsConfig;
use Eshop\Admin\SettingsPresenter;
use Eshop\Common\Helpers;
use Eshop\DB\CategoryRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\InternalRibbon;
use Eshop\DB\InternalRibbonRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\Product;
use Eshop\DB\RibbonRepository;
use Eshop\DB\SupplierCategoryRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\Expression;
use StORM\ICollection;
use Web\DB\SettingRepository;

class ProductGridFiltersFactory
{
	public function __construct(
		private readonly ProducerRepository $producerRepository,
		private readonly SupplierRepository $supplierRepository,
		private readonly SupplierCategoryRepository $supplierCategoryRepository,
		private readonly CategoryRepository $categoryRepository,
		private readonly RibbonRepository $ribbonRepository,
		private readonly InternalRibbonRepository $internalRibbonRepository,
		private readonly PricelistRepository $pricelistRepository,
		private readonly DisplayAmountRepository $displayAmountRepository,
		private readonly SupplierProductRepository $supplierProductRepository,
		private readonly ShopsConfig $shopsConfig,
		private readonly SettingRepository $settingRepository,
	) {
	}

	public function addFilters(AdminGrid $grid): void
	{
		$columns = [
			'this.code',
			'this.ean',
			'this.mpn',
		];

		$mutationColumns = [
			'this.name',
		];

		foreach ($this->categoryRepository->getConnection()->getAvailableMutations() as $mutationSuffix) {
			foreach ($mutationColumns as $column) {
				$columns[] = $column . $mutationSuffix;
			}
		}

		$grid->addFilterTextInput('code', $columns, null, 'Název, EAN, kód, P/N', '');

		if ($shops = $this->shopsConfig->getAvailableShops()) {
			$categoryTypes = [];

			foreach ($shops as $shop) {
				$setting = $this->settingRepository->getValueByName(SettingsPresenter::MAIN_CATEGORY_TYPE . '_' . $shop->getPK());

				if (!$setting) {
					continue;
				}

				$categoryTypes[] = $setting;
			}

			$categories = $categoryTypes ? $this->categoryRepository->getTreeArrayForSelect(true, $categoryTypes) : [];
		} else {
			$categories = $this->categoryRepository->getTreeArrayForSelect();
		}

		if ($categories) {
			$exactCategories = $categories;
			$categories += ['0' => 'X - bez kategorie'];

			foreach ($exactCategories as $key => $value) {
				$categories += ['.' . $key => $value . ' (bez podkategorií)'];
			}

			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				if (\str_starts_with($value, '.')) {
					$subSelect = $this->categoryRepository->getConnection()->rows(['eshop_product_nxn_eshop_category'])
						->where('this.uuid = eshop_product_nxn_eshop_category.fk_product')
						->where('eshop_product_nxn_eshop_category.fk_category', Strings::substring($value, 1));

					$source->where('EXISTS (' . $subSelect->getSql() . ')', $subSelect->getVars());
				} else {
					$category = $this->categoryRepository->one($value);

					if (!$category && $value !== '0') {
						$source->where('1=0');

						return;
					}

					if ($value === '0') {
						return;
					}

					$allSubCategoriesForCategory = $this->categoryRepository->many()
						->where('this.path LIKE :path', ['path' => "$category->path%"])
						->setSelect(['uuid'])
						->toArrayOf('uuid', toArrayValues: true);

					$subSelect = $this->categoryRepository->getConnection()->rows(['eshop_product_nxn_eshop_category'])
						->where('this.uuid = eshop_product_nxn_eshop_category.fk_product')
						->where('eshop_product_nxn_eshop_category.fk_category', $allSubCategoriesForCategory);

					$source->where('EXISTS (' . $subSelect->getSql() . ')', $subSelect->getVars());
				}
			}, '', 'category', null, $categories)->setPrompt('- Kategorie -');
		}

		if ($producers = $this->producerRepository->getArrayForSelect()) {
			$producers += ['0' => 'X - bez výrobce'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->filter(['producer' => Helpers::replaceArrayValue($value, '0', null)]);
			}, '', 'producers', null, $producers, ['placeholder' => '- Výrobci -']);
		}

		if ($suppliers = $this->supplierRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $suppliers): void {
				$expression = new Expression();

				foreach ($suppliers as $supplier) {
					$expression->add('OR', 'this.fk_supplierSource=%1$s', [$supplier]);
				}

				$subSelect = $this->supplierProductRepository->getConnection()
					->rows(['eshop_supplierproduct']);

				$subSelect->setBinderName('eshop_supplierproductFilterDataMultiSelectSupplier');

				$subSelect
					->where('this.uuid = eshop_supplierproduct.fk_product')
					->where('eshop_supplierproduct.fk_supplier', $suppliers);

				$source->where('EXISTS (' . $subSelect->getSql() . ') OR ' . $expression->getSql(), $subSelect->getVars() + $expression->getVars());
			}, '', 'suppliers', null, $suppliers, ['placeholder' => '- Zdroje -']);

			if ($supplierCategories = $this->supplierCategoryRepository->getArrayForSelect(true)) {
				$grid->addFilterDataMultiSelect(function (ICollection $source, $categories): void {
					$subSelect = $this->supplierProductRepository->getConnection()->rows(['eshop_supplierproduct']);

					$subSelect->setBinderName('eshop_supplierproductFilterDataMultiSelectCategory');

					$subSelect
						->where('this.uuid = eshop_supplierproduct.fk_product')
						->where('eshop_supplierproduct.fk_category', $categories);

					$source->where('EXISTS (' . $subSelect->getSql() . ')', $subSelect->getVars());
				}, '', 'supplier_categories', null, $supplierCategories, ['placeholder' => '- Rozřazení -']);
			}

			$grid->addFilterDataSelect(function (ICollection $source, $value): void {
				if ($value === 'locked') {
					$source->where('this.supplierContentLock = 1');
				} else {
					$source->where('this.supplierContentLock != 1');
				}
			}, '', 'supplierLock', null, ['unlocked' => 'Odemknuté', 'locked' => 'Zamknuté'])->setPrompt('- Zámek -');
		}

		if ($ribbons = $this->ribbonRepository->getArrayForSelect()) {
			$ribbons += ['0' => 'X - bez štítků'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->filter(['ribbon' => Helpers::replaceArrayValue($value, '0', null)]);
			}, '', 'ribbons', null, $ribbons, ['placeholder' => '- Veř. štítky -']);
		}

		if ($ribbons = $this->internalRibbonRepository->getArrayForSelect(type: InternalRibbon::TYPE_PRODUCT)) {
			$ribbons += ['0' => 'X - bez štítků'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->filter(['internalRibbon' => Helpers::replaceArrayValue($value, '0', null)]);
			}, '', 'internalRibbon', null, $ribbons, ['placeholder' => '- Int. štítky -']);
		}

		if ($pricelists = $this->pricelistRepository->getArrayForSelect()) {
			$pricelists += ['0' => 'X - bez ceniků'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->filter(['pricelist' => Helpers::replaceArrayValue($value, '0', null)]);
			}, '', 'pricelists', null, $pricelists, ['placeholder' => '- Ceníky -']);
		}

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			if ($value === 'mainImage') {
				$source->where('this.imageFileName IS NOT NULL');
			}

			if ($value === 'noMainImage') {
				$source->where('this.imageFileName IS NULL');
			}

			if ($value === 'fiximage') {
				$source->where('this.imageNeedFix = 1');
			}

			if ($value === 'ean') {
				$source->where('this.ean IS NOT NULL');
			}

			if ($value === 'noean') {
				$source->where('this.ean IS NULL');
			}

			if ($value === 'content') {
				$source->where("this.content_cs IS NOT NULL AND this.content_cs != ''");
			}

			if ($value === 'nocontent') {
				$source->where("this.content_cs IS NULL OR this.content_cs=''");
			}

			if ($value !== 'fixcontent') {
				return;
			}

			$thresholdLength = 1000;
			$suffix = '_cs';
			$expression = new Expression();
			$expression->add('AND', "LOCATE(%s, this.content$suffix)=0", ['<div>']);
			$expression->add('AND', "LOCATE(%s, this.content$suffix)=0", ['<br>']);
			$expression->add('AND', "LOCATE(%s, this.content$suffix)=0", ['<p>']);
			$expression->add('AND', "LOCATE(%s, this.content$suffix)=0", ['<table>']);

			$source->where("LENGTH(this.content$suffix) > :length", ['length' => $thresholdLength])->where($expression->getSql(), $expression->getVars());
		}, '', 'image', null, [
			'mainImage' => 'S hlavním obrázkem',
			'noMainImage' => 'Chybí hlavní obrázek',
			'fiximage' => 'Chybný obrázek',
			'ean' => 'S EANem',
			'noean' => 'Bez EANu',
			'content' => 'S obsahem',
			'nocontent' => 'Bez obsahu',
			'fixcontent' => 'Chybný text',
		])->setPrompt('- Obsah -');

		if ($displayAmounts = $this->displayAmountRepository->getArrayForSelect()) {
			$displayAmounts += ['0' => 'X - nepřiřazená'];
			$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
				$source->filter(['displayAmount' => Helpers::replaceArrayValue($value, '0', null)]);
			}, '', 'displayAmount', null, $displayAmounts, ['placeholder' => '- Dostupnost -']);
		}

//		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
//			$source->where('hidden', (bool) $value);
//		}, '', 'hidden', null, ['1' => 'Skryté', '0' => 'Viditelné'])->setPrompt('- Viditelnost -');
//
//		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
//			$source->where('recommended', (bool) $value);
//		}, '', 'recommended', null, ['1' => 'Doporučené', '0' => 'Normální'])->setPrompt('- Doporučené -');
//
//		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
//			$source->where('unavailable', (bool) $value);
//		}, '', 'unavailable', null, ['1' => 'Neprodejné', '0' => 'Prodejné'])->setPrompt('- Prodejnost -');

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			if ($value === 'green') {
				$source->setGroupBy(
					['this.uuid'],
					'hidden = "0" AND unavailable = "0" AND COUNT(DISTINCT price.uuid) > 0 AND COUNT(DISTINCT nxnCategory.fk_category) > 0 AND pricelistActive = "1"',
				);
			} elseif ($value === 'orange') {
				$source->setGroupBy(
					['this.uuid'],
					'hidden = "0" AND unavailable = "0" AND COUNT(DISTINCT price.uuid) > 0 AND COUNT(DISTINCT nxnCategory.fk_category) = 0 AND pricelistActive = "1"',
				);
			} else {
				$source->setGroupBy(
					['this.uuid'],
					'hidden = "1" OR unavailable = "1" OR COUNT(DISTINCT price.uuid) = 0 OR COUNT(DISTINCT nxnCategory.fk_category) = 0 OR pricelistActive = "0"',
				);
			}
		}, '', 'show', null, ['green' => 'Viditelné', 'orange' => 'Viditelné: bez kategorie', 'red' => 'Neviditelné'])->setPrompt('- Viditelnost v eshopu -');

		if (!$suppliers) {
			return;
		}

		$locks = [];
		$locks[Product::SUPPLIER_CONTENT_MODE_PRIORITY] = 'S nejvyšší prioritou';
		$locks += $suppliers;
		$locks[Product::SUPPLIER_CONTENT_MODE_CUSTOM_CONTENT] = 'Nikdy nepřebírat: Vlastní obsah';
		$locks[Product::SUPPLIER_CONTENT_MODE_NONE] = 'Nikdy nepřebírat: Žádný obsah';

		$grid->addFilterDataSelect(function (Collection $source, $value): void {
			$mutationSuffix = $source->getConnection()->getMutationSuffix();

			if ($value === Product::SUPPLIER_CONTENT_MODE_PRIORITY) {
				$source->where('this.supplierContentLock', false);
				$source->where('this.supplierContentMode = "priority" OR (this.fk_supplierContent IS NULL AND this.supplierContentMode = "none")');
			} elseif ($value === Product::SUPPLIER_CONTENT_MODE_CUSTOM_CONTENT) {
				$source->where('this.supplierContentLock', true);
				$source->where("this.content$mutationSuffix IS NOT NULL AND LENGTH(this.content$mutationSuffix) > 0");
			} elseif ($value === Product::SUPPLIER_CONTENT_MODE_NONE) {
				$source->where('this.supplierContentLock', true);
				$source->where("this.content$mutationSuffix IS NULL OR LENGTH(this.content$mutationSuffix) = 0");
			} else {
				$source->where('this.supplierContentLock', false);
				$source->where('this.fk_supplierContent', $value);
			}
		}, '', 'supplierContent', null, $locks)->setPrompt('- Přebírání obsahu -');
	}
}
