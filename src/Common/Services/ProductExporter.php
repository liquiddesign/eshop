<?php

namespace Eshop\Common\Services;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Admin\Controls\AdminGrid;
use Base\ShopsConfig;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\AttributeRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\VisibilityListRepository;
use League\Csv\EncloseField;
use League\Csv\Writer;
use Nette\Application\LinkGenerator;
use Nette\Application\Responses\FileResponse;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\DIConnection;
use Web\DB\PageRepository;

class ProductExporter
{
	protected string $tempDir;

	public function __construct(
		private readonly ProductRepository $productRepository,
		private readonly AdminFormFactory $formFactory,
		private readonly LinkGenerator $linkGenerator,
		private readonly Container $container,
		private readonly \Nette\Http\Request $httpRequest,
		private readonly SupplierRepository $supplierRepository,
		private readonly AttributeRepository $attributeRepository,
		private readonly DIConnection $connection,
		private readonly ShopsConfig $shopsConfig,
		private readonly AttributeAssignRepository $attributeAssignRepository,
		private readonly SupplierProductRepository $supplierProductRepository,
		private readonly PageRepository $pageRepository,
		private readonly VisibilityListItemRepository $visibilityListItemRepository,
		private readonly VisibilityListRepository $visibilityListRepository,
	) {
		$this->tempDir = $this->container->getParameters()['tempDir'];

		$this->startUp();
	}

	/**
	 * @param \Admin\Controls\AdminGrid $productGrid
	 * @param string $formActionLink
	 * @param array|null $exportColumns
	 * @param array|null $defaultExportColumns
	 * @param array|null $exportAttributes
	 * @param (callable(\Eshop\DB\Product, string): string|null)|null $getSupplierCodeCallback
	 * @throws \Nette\Application\UI\InvalidLinkException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function createForm(
		AdminGrid $productGrid,
		string $formActionLink = 'this',
		array|null $exportColumns = null,
		array|null $defaultExportColumns = null,
		array|null $exportAttributes = null,
		callable|null $getSupplierCodeCallback = null,
	): AdminForm {
		$ids = $this->httpRequest->getUrl()->getQueryParameter('ids') ?: [];
		$totalNo = $productGrid->getPaginator()->getItemCount();
		$selectedNo = \count($ids);
		$mutationSuffix = $this->productRepository->getConnection()->getMutationSuffix();
		$form = $this->formFactory->create();
		$form->addRadioList('bulkType', 'Exportovat', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'   ' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		]);
		$form->addCheckbox('header', 'Hlavička')->setDefaultValue(true)->setHtmlAttribute('data-info', 'Pokud tuto možnost nepoužijete tak nebude možné tento soubor použít pro import!');

		$dataInfo = '<br><b>Vysvětlivky sloupců:</b><br>
Sloučené produkty: Sloučené produkty se exportují do sloupce "mergedProducts" jako kódy produktů oddělené znakem ":". Tento sloupec se <b>NEPOUŽÍVÁ</b> při importu!<br>
Nadřazený sloučený produkt: U každého produktu se exportuje jen kód produktu do sloupce "masterProduct" jako jeho předchůdce ve stromové struktuře sloučených produktů. 
Tento sloupec se <b>POUŽÍVÁ</b> při importu!';

		if ($this->shopsConfig->getAvailableShops()) {
			$dataInfo .= '<br><br>Váš eshop využívá více obchodů.<br>
Perex a Obsah budou exportovány vždy pro aktuálně zvolený obchod.';
		}

		$headerColumns = $form->addDataMultiSelect('columns', 'Sloupce')
			->setHtmlAttribute('data-info', $dataInfo);

		$form->addDataMultiSelect('visibilityLists', 'Viditelnosti', $this->visibilityListRepository->getArrayForSelect())
			->setHtmlAttribute('data-info', 'Pro dané seznamy viditelnosti budou exportovány všechny sloupce ve tvaru "HODNOTA#KOD_SEZNAMU".');

		$attributesColumns = $form->addDataMultiSelect('attributes', 'Atributy')->setHtmlAttribute('data-info', 'Zobrazují se pouze atributy, které mají alespoň jeden přiřazený produkt.');

		$items = [];
		$defaultItems = [];

		if (isset($exportColumns)) {
			$items += $exportColumns;

			if (isset($defaultExportColumns)) {
				$defaultItems = \array_merge($defaultItems, $defaultExportColumns);
			}
		}

		$headerColumns->setItems($items);
		$headerColumns->setDefaultValue($defaultItems);

		$attributes = [];
		$defaultAttributes = [];

		if (isset($exportAttributes)) {
			foreach ($exportAttributes as $key => $value) {
				if ($attribute = $this->attributeRepository->many()->where('code', $key)->first()) {
					$attributes[$attribute->getPK()] = "$value#$key";
					$defaultAttributes[] = $attribute->getPK();
				}
			}

			$attributes += $this->attributeRepository->many()
				->whereNot('this.code', \array_keys($exportAttributes))
				->join(['attributeValue' => 'eshop_attributevalue'], 'this.uuid = attributeValue.fk_attribute')
				->join(['assign' => 'eshop_attributeassign'], 'attributeValue.uuid = assign.fk_value')
				->where('assign.uuid IS NOT NULL')
				->orderBy(["this.name$mutationSuffix"])
				->select(['nameAndCode' => "CONCAT(this.name$mutationSuffix, '#', this.code)"])
				->toArrayOf('nameAndCode');
		}

		$attributesColumns->setItems($attributes);
		$attributesColumns->setDefaultValue($defaultAttributes);

		if ($suppliers = $this->supplierRepository->many()->where('code IS NOT NULL')->setIndex('code')->toArrayOf('name')) {
			$form->addMultiSelect2('suppliersCodes', 'Dodavatelské kódy', $suppliers);
		}

		$form->addSubmit('submit', 'Exportovat');

		$form->onValidate[] = function (AdminForm $form) use ($headerColumns): void {
			$values = $form->getValues();

			if (Arrays::contains($values['columns'], 'code') || Arrays::contains($values['columns'], 'ean')) {
				return;
			}

			$headerColumns->addError('Je nutné vybrat "Kód" nebo "EAN" pro jednoznačné označení produktu.');
		};

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $productGrid, $items, $attributes, $getSupplierCodeCallback): void {
			$values = $form->getValues('array');

			$products = $values['bulkType'] === 'selected' ? $this->productRepository->many()->where('this.uuid', $ids) : $productGrid->getFilteredSource();

			$tempFilename = \tempnam($this->tempDir, 'csv');

			$headerColumns = \array_filter($items, function ($item) use ($values) {
				return Arrays::contains($values['columns'], $item);
			}, \ARRAY_FILTER_USE_KEY);

			$attributeColumns = \array_filter($attributes, function ($item) use ($values) {
				return Arrays::contains($values['attributes'], $item);
			}, \ARRAY_FILTER_USE_KEY);

			$this->csvExport(
				$products,
				Writer::createFromPath($tempFilename),
				$headerColumns,
				$attributeColumns,
				$values['delimiter'],
				$values['header'] ? \array_merge(\array_values($headerColumns), \array_values($attributeColumns)) : null,
				$values['suppliersCodes'] ?? [],
				$getSupplierCodeCallback,
				$values['visibilityLists'],
			);

			$form->getPresenter()->sendResponse(new FileResponse($tempFilename, 'products.csv', 'text/csv'));
		};

		return $form;
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\Product> $products
	 * @param \League\Csv\Writer $writer
	 * @param array $columns
	 * @param array $attributes
	 * @param string $delimiter
	 * @param array|null $header
	 * @param array $supplierCodes
	 * @param (callable(\Eshop\DB\Product, string): string|null)|null $getSupplierCodeCallback
	 * @throws \League\Csv\CannotInsertRecord
	 * @throws \League\Csv\Exception
	 * @throws \League\Csv\InvalidArgument
	 * @throws \Nette\Application\UI\InvalidLinkException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function csvExport(
		Collection $products,
		Writer $writer,
		array $columns = [],
		array $attributes = [],
		string $delimiter = ';',
		?array $header = null,
		array $supplierCodes = [],
		?callable $getSupplierCodeCallback = null,
		array $visibilityLists = [],
	): void {
		$writer->setDelimiter($delimiter);

		EncloseField::addTo($writer, "\t\22");

		foreach ($visibilityLists as $visibilityListPK) {
			$visibilityList = $this->visibilityListRepository->one($visibilityListPK, true);

			$header[] = "Skryto#$visibilityList->code";
			$header[] = "Skryto v menu a vyhledávání#$visibilityList->code";
			$header[] = "Neprodejné#$visibilityList->code";
			$header[] = "Doporučeno#$visibilityList->code";
			$header[] = "Priorita#$visibilityList->code";

			$columns["hidden#$visibilityList->code"] = "Skryto#$visibilityList->code";
			$columns["hiddenInMenu#$visibilityList->code"] = "Skryto v menu a vyhledávání#$visibilityList->code";
			$columns["unavailable#$visibilityList->code"] = "Neprodejné#$visibilityList->code";
			$columns["recommended#$visibilityList->code"] = "Doporučeno#$visibilityList->code";
			$columns["priority#$visibilityList->code"] = "Priorita#$visibilityList->code";
		}

		$completeHeaders = \array_merge($header, $supplierCodes);

		if ($completeHeaders) {
			$writer->insertOne($completeHeaders);
		}

		$mutationSuffix = $this->connection->getMutationSuffix();
		$selectedShop = $this->shopsConfig->getSelectedShop();

		$productsByVisibilityLists = [];

		$visibilityListItemsCollection = $this->visibilityListItemRepository->many()->select(['code' => 'visibilityList.code']);

		while ($visibilityListItem = $visibilityListItemsCollection->fetch(\stdClass::class)) {
			$visibilityListItem = (array) $visibilityListItem;

			$productsByVisibilityLists[$visibilityListItem['fk_product']][$visibilityListItem['code']] = $visibilityListItem;
		}

		$visibilityListItemsCollection->__destruct();

		$products->setGroupBy(['this.uuid'])
			->join(['priceTable' => 'eshop_price'], 'this.uuid = priceTable.fk_product')
			->select([
				'priceMin' => 'MIN(priceTable.price)',
				'priceMax' => 'MAX(priceTable.price)',
			])
			->join(['producer' => 'eshop_producer'], 'this.fk_producer = producer.uuid')
			->join(['storeAmount' => 'eshop_amount'], 'storeAmount.fk_product = this.uuid')
			->join(['store' => 'eshop_store'], 'storeAmount.fk_store = store.uuid')
			->join(['categoryAssign' => 'eshop_product_nxn_eshop_category'], 'this.uuid = categoryAssign.fk_product')
			->join(['category' => 'eshop_category'], 'categoryAssign.fk_category = category.uuid')
			->join(['masterProduct' => 'eshop_product'], 'this.fk_masterProduct = masterProduct.uuid')
			->join(['productContent' => 'eshop_productcontent'], 'this.uuid = productContent.fk_product')
			->select([
				'producerCodeName' => "CONCAT(COALESCE(producer.name$mutationSuffix, ''), '#', COALESCE(producer.code, ''))",
				'amounts' => "GROUP_CONCAT(DISTINCT CONCAT(storeAmount.inStock, '#', store.code) SEPARATOR ':')",
				'groupedCategories' => "GROUP_CONCAT(DISTINCT CONCAT(category.name$mutationSuffix, '#',
                IF(category.code IS NULL OR category.code = '', category.uuid, category.code)) ORDER BY LENGTH(category.path) SEPARATOR ':')",
				'masterProductCode' => 'masterProduct.code',
				'content' => "productContent.content$mutationSuffix",
				'perex' => "productContent.perex$mutationSuffix",
			]);

		if ($selectedShop) {
			$products->where('productContent.fk_shop', $selectedShop->getPK());
		}

		$attributesByProductPK = [];

		foreach ($this->attributeAssignRepository->many()
					 ->join(['attributeValue' => 'eshop_attributevalue'], 'this.fk_value = attributeValue.uuid')
					 ->select([
						 'fk_attribute' => 'attributeValue.fk_attribute',
						 'label' => "attributeValue.label$mutationSuffix",
						 'code' => 'attributeValue.code',
					 ])
					 ->fetchArray(\stdClass::class) as $item) {
			// phpcs:ignore
			$attributesByProductPK[$item->fk_product][$item->fk_attribute][] = $item;
		}

		$supplierProductsArray = $this->supplierProductRepository->many()
			->join(['supplier' => 'eshop_supplier'], 'this.fk_supplier = supplier.uuid')
			->setSelect([
				'fkProduct' => 'this.fk_product',
				'supplier.importPriority',
				'this.recyclingFee',
			], [], true)
			->orderBy(['this.fk_product', 'supplier.importPriority'])
			->fetchArray(\stdClass::class);

		$supplierProductsArrayGroupedByProductPK = [];

		foreach ($supplierProductsArray as $supplierProduct) {
			$supplierProductsArrayGroupedByProductPK[$supplierProduct->fkProduct][] = $supplierProduct;
		}

		unset($supplierProductsArray);

		$mergedProductsMap = $this->productRepository->getGroupedMergedProducts();
		$productsXCode = $this->productRepository->many()->setSelect(['this.code'], [], true)->toArrayOf('code');
		$lazyLoadedProducts = [];

		while ($product = $products->fetch()) {
			/** @var \Eshop\DB\Product|\stdClass $product */
			$row = [];

			foreach (\array_keys($columns) as $columnKey) {
				if ($columnKey === 'producer') {
					$row[] = $product->producerCodeName;
				} elseif ($columnKey === 'storeAmount') {
					$row[] = $product->amounts;
				} elseif ($columnKey === 'categories') {
					$row[] = $product->groupedCategories;
				} elseif ($columnKey === 'adminUrl') {
					$row[] = $this->linkGenerator->link('Eshop:Admin:Product:edit', [$product]);
				} elseif ($columnKey === 'frontUrl') {
					$page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, ['product' => $product->getPK()]);
					$row[] = $page ? $this->httpRequest->getUrl()->getBaseUrl() . $page->getUrl($this->connection->getMutation()) : null;
				} elseif ($columnKey === 'mergedProducts') {
					$codes = [];

					foreach ($mergedProductsMap[$product->getPK()] ?? [] as $mergedProduct) {
						$codes[] = $productsXCode[$mergedProduct] ?? null;
					}

					$row[] = \implode(':', $codes);
				} elseif ($columnKey === 'masterProduct') {
					$row[] = $product->getValue('masterProductCode');
				} elseif ($columnKey === 'recyclingFee') {
					$allMergedProducts = \array_merge([$product], ($mergedProductsMap[$product->getPK()] ?? []));

					$minSupplierPriority = \PHP_INT_MAX;
					$recyclingFee = null;

					foreach ($allMergedProducts as $allMergedProduct) {
						if (\is_string($allMergedProduct)) {
							if (!isset($lazyLoadedProducts[$allMergedProduct])) {
								$lazyLoadedProducts[$allMergedProduct] = $this->productRepository->one($allMergedProduct, true);
							}

							$allMergedProduct = $lazyLoadedProducts[$allMergedProduct];
						}

						foreach ($supplierProductsArrayGroupedByProductPK[$allMergedProduct->getPK()] ?? [] as $item) {
							$importPriority = $item->importPriority ?: 0;

							if ($importPriority >= $minSupplierPriority && $recyclingFee !== null) {
								continue;
							}

							$minSupplierPriority = $importPriority;
							$recyclingFee = $item->recyclingFee;
						}
					}

					$row[] = $recyclingFee;
				} elseif (Strings::startsWith($columnKey, 'hidden#') ||
					Strings::startsWith($columnKey, 'hiddenInMenu#') ||
					Strings::startsWith($columnKey, 'unavailable#') ||
					Strings::startsWith($columnKey, 'recommended#') ||
					Strings::startsWith($columnKey, 'priority#')) {
					[$property, $visibilityList] = \explode('#', $columnKey);
					$visibilityListItem = $productsByVisibilityLists[$product->getPK()][$visibilityList] ?? null;

					$row[] = $visibilityListItem ? $visibilityListItem[$property] : 0;
				} else {
					$row[] = $product->getValue($columnKey) === false ? '0' : $product->getValue($columnKey);
				}
			}

			foreach (\array_keys($attributes) as $attributePK) {
				if (!isset($attributesByProductPK[$product->getPK()][$attributePK])) {
					$row[] = null;

					continue;
				}

				$tmp = '';

				foreach ($attributesByProductPK[$product->getPK()][$attributePK] as $attributeAssignObject) {
					$tmp .= "$attributeAssignObject->label#$attributeAssignObject->code:";
				}

				$row[] = Strings::substring($tmp, 0, -1);
			}

			foreach ($supplierCodes as $supplierCode) {
				$row[] = $getSupplierCodeCallback ? $getSupplierCodeCallback($product, $supplierCode) : $this->productRepository->getSupplierCode($product, $supplierCode);
			}

			$writer->insertOne($row);
		}

		$products->__destruct();
	}

	protected function startUp(): void
	{
		// To be implemented
	}
}
