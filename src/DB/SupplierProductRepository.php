<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\ShopsConfig;
use Eshop\Admin\SettingsPresenter;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Image;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\DIConnection;
use StORM\ICollection;
use StORM\Literal;
use StORM\SchemaManager;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\Page;
use Web\DB\Setting;
use Web\DB\SettingRepository;

/**
 * @extends \StORM\Repository<\Eshop\DB\SupplierProduct>
 */
class SupplierProductRepository extends \StORM\Repository
{
	private Container $container;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		Container $container,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly SettingRepository $settingRepository
	) {
		parent::__construct($connection, $schemaManager);

		$this->container = $container;
	}

	/**
	 * @param \Eshop\DB\Supplier $supplier
	 * @param string $mutation
	 * @param string $country
	 * @param bool $overwrite
	 * @param bool $importImages
	 * @return array<int>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function syncProducts(Supplier $supplier, string $mutation, string $country, bool $overwrite, bool $importImages = false): array
	{
		$result = [
			'updated' => 0,
			'locked' => 0,
			'inserted' => 0,
			'images' => 0,
		];

		$sep = \DIRECTORY_SEPARATOR;
		$sourceImageDirectory = $this->container->parameters['wwwDir'] . $sep . 'userfiles' . $sep . 'supplier_images';
		$galleryImageDirectory = $this->container->parameters['wwwDir'] . $sep . 'userfiles' . $sep . 'product_gallery_images';

		$vatLevels = $this->getConnection()->findRepository(VatRate::class)->many()->where('fk_country', $country)->setBufferedQuery(false)->setIndex('rate')->toArrayOf('uuid');
		$supplierProductRepository = $this->getConnection()->findRepository(SupplierProduct::class);
		$productRepository = $this->getConnection()->findRepository(Product::class);
		$pagesRepository = $this->getConnection()->findRepository(Page::class);
		$productContentRepository = $this->getConnection()->findRepository(ProductContent::class);
		$productPrimaryCategoryRepository = $this->getConnection()->findRepository(ProductPrimaryCategory::class);
		$categoryRepository = $this->getConnection()->findRepository(Category::class);
		$visibilityListRepository = $this->getConnection()->findRepository(VisibilityList::class);
		$visibilityListItemRepository = $this->getConnection()->findRepository(VisibilityListItem::class);
		$supplierId = $supplier->getPK();
		$attributeAssignRepository = $this->getConnection()->findRepository(AttributeAssign::class);
		$supplierAttributeValueAssignRepository = $this->getConnection()->findRepository(SupplierAttributeValueAssign::class);
		$photoRepository = $this->getConnection()->findRepository(Photo::class);
		$mutationSuffix = $this->getConnection()->getAvailableMutations()[$mutation];
		$riboonId = 'novy_import';

		$visibilityLists = $visibilityListRepository->many()->toArray();

		if ($overwrite) {
			$updates = ["name$mutationSuffix", 'unit', 'imageFileName', 'vatRate'];
			$updates = \array_fill_keys($updates, null);

			foreach (\array_keys($updates) as $name) {
				$updates[$name] = new Literal("IF
                (
                    (
                        supplierContentLock = 0 && 
                        (
                            (VALUES(supplierLock) >= supplierLock && supplierContentMode = 'priority') ||
                            (supplierContentMode = 'content' && fk_supplierContent = '$supplierId')
                        )
                    ),
                    VALUES($name),
                    $name
                )");
			}

			$updates['fk_producer'] = new Literal('IF(fk_producer IS NULL, VALUES(fk_producer), fk_producer)');
		} else {
			$updates = [];
		}

		$allCategories = $categoryRepository->many()->select(['typePK' => 'this.fk_type'])->fetchArray(\stdClass::class);

		$productsMap = $productRepository->many()
			->setSelect(['contentLock' => 'supplierContentLock', 'sourcePK' => 'fk_supplierSource'], [], true)
			->setBufferedQuery(false)
			->fetchArray(\stdClass::class);

		$eanProductsMap = $productRepository->many()
			->setSelect(['ean', 'uuid'], [], true)
			->setBufferedQuery(false)
			->setIndex('ean')
			->fetchArray(\stdClass::class);

		$drafts = $supplierProductRepository->many()
			->setGroupBy(['this.uuid'])
			->select(['realCategories' => 'GROUP_CONCAT(supplierCategoryXCategory.fk_category)'])
			->select(['realDisplayAmount' => 'displayAmount.fk_displayAmount'])
			->select(['realProducer' => 'producer.fk_producer'])
			->join(['supplierCategoryXCategory' => 'eshop_suppliercategory_nxn_eshop_category'], 'this.fk_category = supplierCategoryXCategory.fk_supplierCategory', type: 'INNER')
			->where('this.fk_supplier', $supplier)
			->where('supplierCategoryXCategory.fk_category IS NOT NULL')
			->where('this.active', true);

		$this->setDraftsCollection($drafts);

		/** @var array<\stdClass> $existingPrimaryCategories */
		$existingPrimaryCategories = $productPrimaryCategoryRepository->many()
			->setSelect([
				'productPK' => 'this.fk_product',
				'categories' => 'GROUP_CONCAT(this.fk_category)',
				'categoryTypes' => 'GROUP_CONCAT(this.fk_categoryType)',
			])
			->where('this.fk_category IS NOT NULL')
			->setGroupBy(['this.fk_product'])
			->setIndex('productPK')
			->fetchArray(\stdClass::class);

		/** @var array<array<\stdClass>> $existingProductContents By product -> shop -> mutations */
		$existingProductContents = [];

		foreach ($productContentRepository->many()
					 ->select(['productPK' => 'this.fk_product', 'shopPK' => 'this.fk_shop', 'content' => "this.content$mutationSuffix"])
					 ->fetchArray(\stdClass::class) as $productContent) {
			$existingProductContents[$productContent->productPK][$productContent->shopPK] = $productContent;
		}

		$productsWithDontAssignSupplierCategoryInternalRibbon = $productRepository->many()
			->where('internalRibbons.uuid', 'dont_assign_supplier_category')
			->setSelect(['this.uuid'], keepIndex: true)
			->toArrayOf('uuid');

		$supplierAttributeValueAssignQuery = $supplierAttributeValueAssignRepository->many()
			->join(['sav' => 'eshop_supplierattributevalue'], 'this.fk_supplierAttributeValue = sav.uuid')
			->setSelect([
				'supplierProductPK' => 'this.fk_supplierProduct',
				'attributeValuePK' => 'sav.fk_attributeValue',
			])
			->where('sav.fk_attributeValue IS NOT NULL');

		$supplierAttributeValuesByProduct = [];

		while ($supplierAttributeValueAssign = $supplierAttributeValueAssignQuery->fetch(\stdClass::class)) {
			/** @var \stdClass $supplierAttributeValueAssign */
			$supplierAttributeValuesByProduct[$supplierAttributeValueAssign->supplierProductPK][] = $supplierAttributeValueAssign->attributeValuePK;
		}

		$supplierAttributeValueAssignQuery->__destruct();
		unset($supplierAttributeValueAssignQuery);

		$existingAttributeValuesByProductQuery = $attributeAssignRepository->many()->setSelect([
			'attributeValue' => 'this.fk_value',
			'product' => 'this.fk_product',
		]);
		$existingAttributeValuesByProduct = [];

		while ($existingAttributeValue = $existingAttributeValuesByProductQuery->fetch(\stdClass::class)) {
			/** @var \stdClass $existingAttributeValue */
			$existingAttributeValuesByProduct[$existingAttributeValue->product][$existingAttributeValue->attributeValue] = true;
		}

		$existingAttributeValuesByProductQuery->__destruct();
		unset($existingAttributeValuesByProductQuery);

		$productContentsToSync = [];

		while ($draft = $drafts->fetch()) {
			/** @var \stdClass|\Eshop\DB\SupplierProduct $draft */
			$categories = \array_filter(\explode(',', $draft->realCategories), fn (string|null $v) => (bool) $v);
			$displayAmount = $draft->realDisplayAmount;
			$producer = $draft->realProducer;
			$currentUpdates = $updates;

			if (!$categories) {
				continue;
			}

			$code = $draft->productCode ?: ($supplier->productCodePrefix ?: '') . $draft->code;
			$uuid = ProductRepository::generateUuid($draft->ean, $draft->getProductFullCode() ?: $supplier->code . '-' . $draft->code);

			if ($draft->getValue('product') && $draft->getValue('product') !== $uuid) {
				$uuid = $draft->getValue('product');
			}

			$primary = isset($productsMap[$uuid]) && $productsMap[$uuid]->sourcePK === $supplierId;

			$values = [
				'uuid' => $uuid,
				'ean' => $draft->ean ?: null,
				'mpn' => $draft->mpn ?: null,
				'code' => $code,
				'subCode' => $draft->productSubCode,
				// jen pokud neni mozne parovat
				'supplierCode' => $draft->code,
				'name' => [$mutation => $draft->name],
				'unit' => $draft->unit,
				'vatRate' => $vatLevels[(int) $draft->vatRate] ?? 'standard',
				'producer' => $producer,
				'displayDelivery' => $supplier->getValue('defaultDisplayDelivery'),
				'displayAmount' => $displayAmount ?: $supplier->getValue('defaultDisplayAmount'),
				'storageDate' => $draft->storageDate,
				'defaultBuyCount' => $draft->defaultBuyCount,
				'minBuyCount' => $draft->minBuyCount,
				'buyStep' => $draft->buyStep,
				'inPackage' => $draft->inPackage,
				'inCarton' => $draft->inCarton,
				'inPalett' => $draft->inPalett,
				'weight' => $draft->weight,
				'supplierLock' => $supplier->importPriority,
				'supplierSource' => $supplier,
			];

			$importImage = true;

			if (!$importImages ||
				!$supplier->importImages ||
				!\is_file($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName) ||
				!isset($productsMap[$uuid])
			) {
				$importImage = false;
			}

			if ($primary && $importImage) {
				$values['imageFileName'] = $draft->fileName;
			} else {
				unset($currentUpdates['imageFileName']);
			}

			/** @var \Eshop\DB\Product $product */
			$product = $productRepository->syncOne($values, $currentUpdates, false, false, ['categories' => false]);

			if (!isset($productsWithDontAssignSupplierCategoryInternalRibbon[$product->getPK()])) {
				$product->categories->relate($categories, false);
			}

			$updated = $product->getParent() instanceof ICollection;

			if ($updated) {
				$result['updated']++;
			} else {
				$result['inserted']++;

				$productRepository->getConnection()->syncRow('eshop_product_nxn_eshop_internalribbon', [
					'fk_product' => $product->getPK(),
					'fk_internalribbon' => $riboonId,
				]);
			}

			$existingProductPrimaryCategoriesByType = [];
			$productPrimaryCategories = isset($existingPrimaryCategories[$product->getPK()]) ? \explode(',', $existingPrimaryCategories[$product->getPK()]->categories) : [];

			foreach ($productPrimaryCategories as $categoryPK) {
				$category = $allCategories[$categoryPK];

				$existingProductPrimaryCategoriesByType[$category->typePK] = $categoryPK;
			}

			foreach ($categories as $category) {
				$category = $allCategories[$category];

				if (isset($existingProductPrimaryCategoriesByType[$category->typePK])) {
					continue;
				}

				$productPrimaryCategoryRepository->syncOne([
					'product' => $product->getPK(),
					'category' => $category->uuid,
					'categoryType' => $category->typePK,
				], checkKeys: ['product' => false,]);
			}

			foreach ($visibilityLists as $visibilityList) {
				$visibilityListItemRepository->syncOne([
					'visibilityList' => $visibilityList->getPK(),
					'product' => $product->getPK(),
					'hidden' => $supplier->defaultHiddenProduct,
					'unavailable' => $draft->unavailable,
				], []);
			}

			if ($draft->content) {
				$productContents = $existingProductContents[$product->getPK()] ?? null;

				if ($this->shopsConfig->getAvailableShops()) {
					foreach ($this->shopsConfig->getAvailableShops() as $shop) {
						if (isset($productContents[$shop->getPK()]) && $productContents[$shop->getPK()]->content) {
							continue;
						}

						$productContentsToSync[] = [
							'product' => $product->getPK(),
							'shop' => $shop->getPK(),
							'content' => [$mutation => $draft->content],
						];
					}
				} else {
					$productContent = Arrays::first($productContents);

					if (!$productContent || !$productContent->content) {
						$productContentsToSync[] = [
							'product' => $product->getPK(),
							'content' => [$mutation => $draft->content],
						];
					}
				}
			}

			if (isset($productsMap[$uuid]) && $productsMap[$uuid]->contentLock) {
				$result['locked']++;
			}

			foreach ($supplierAttributeValuesByProduct[$draft->getPK()] ?? [] as $attributeValue) {
				if (isset($existingAttributeValuesByProduct[$product->getPK()][$attributeValue])) {
					continue;
				}

				$attributeAssignRepository->syncOne([
					'value' => $attributeValue,
					'product' => $product->getPK(),
				]);
			}

			if ($draft->getValue('product') !== $uuid && \is_string($uuid)) {
				try {
					$draft->update(['product' => $uuid]);
				} catch (\Throwable $x) {
					unset($x);

					try {
						if (isset($eanProductsMap[$draft->ean])) {
							$draft->update(['product' => $eanProductsMap[$draft->ean]->uuid]);
						}
					} catch (\Throwable $e) {
						unset($e);
					}
				}
			}

			if ($this->shopsConfig->getAvailableShops()) {
				foreach ($this->shopsConfig->getAvailableShops() as $shop) {
					$pagesRepository->syncOne([
						'uuid' => DIConnection::generateUuid((string) $shop->getPK(), $uuid),
						'url' => ['cs' => Strings::webalize($draft->name) . '-' . Strings::webalize($code)],
						'title' => ['cs' => $draft->name],
						'params' => "product=$uuid&",
						'type' => 'product_detail',
						'shop' => $shop->getPK(),
					], []);
				}
			} else {
				$pagesRepository->syncOne([
					'uuid' => $uuid,
					'url' => ['cs' => Strings::webalize($draft->name) . '-' . Strings::webalize($code)],
					'title' => ['cs' => $draft->name],
					'params' => "product=$uuid&",
					'type' => 'product_detail',
				], []);
			}

			if (!$importImage) {
				continue;
			}

			$photoRepository->syncOne([
				'uuid' => $draft->getPK(),
				'product' => $product->getPK(),
				'supplier' => $supplierId,
				'fileName' => $draft->fileName,
			]);

            // phpcs:ignore
            $mtime = @\filemtime($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName);

            // phpcs:ignore
            $copyImage = !(!$overwrite || !$draft->fileName || $mtime === @\filemtime($galleryImageDirectory . $sep . 'origin' . $sep . $draft->fileName));

			if (!$copyImage) {
				continue;
			}

			try {
				FileSystem::copy($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName, $galleryImageDirectory . $sep . 'origin' . $sep . $draft->fileName);
				\touch($galleryImageDirectory . $sep . 'origin' . $sep . $draft->fileName, $mtime);

				if (\is_file($sourceImageDirectory . $sep . 'detail' . $sep . $draft->fileName)) {
					FileSystem::copy($sourceImageDirectory . $sep . 'detail' . $sep . $draft->fileName, $galleryImageDirectory . $sep . 'detail' . $sep . $draft->fileName);
				} else {
                    // phpcs:ignore
                    $image = @Image::fromFile($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName);
					$image->resize(600, null);
					$image->save($galleryImageDirectory . $sep . 'detail' . $sep . $draft->fileName);
				}

				if (\is_file($sourceImageDirectory . $sep . 'thumb' . $sep . $draft->fileName)) {
					FileSystem::copy($sourceImageDirectory . $sep . 'thumb' . $sep . $draft->fileName, $galleryImageDirectory . $sep . 'thumb' . $sep . $draft->fileName);
				} else {
                    // phpcs:ignore
                    $image = @Image::fromFile($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName);
					$image->resize(300, null);
					$image->save($galleryImageDirectory . $sep . 'thumb' . $sep . $draft->fileName);
				}
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		}

		$productsToFetch = [];

		foreach ($productContentsToSync as $item) {
			$productsToFetch[] = $item['product'];
		}

		$products = $productRepository->many()
			->setSelect([
				'uuid',
				'supplierLock',
				'supplierContentLock',
				'supplierContentMode',
				'supplierContent' => 'fk_supplierContent',
			], keepIndex: true)
			->where('this.uuid', $productsToFetch)
			->fetchArray(\stdClass::class);

		$contentLocksToUpdate = [];

		foreach ($productContentsToSync as $item) {
			$product = $products[$item['product']] ?? null;

			if (!$product) {
				continue;
			}

            // phpcs:ignore
            if ( $product->supplierContentLock === 0 ||
				($product->supplierLock >= $supplier->importPriority && $product->supplierContentMode === 'priority')) {
				$productContentRepository->syncOne([
					'product' => $product->uuid,
					'shop' => $item['shop'],
					'content' => $item['content'],
				]);

				$contentLocksToUpdate[] = $product->uuid;
			}
		}

		if ($contentLocksToUpdate) {
			$productRepository->many()->where('this.uuid', $contentLocksToUpdate)->update(['supplierLock' => $supplier->importPriority]);
		}

		return $result;
	}

	/**
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function syncPrices(Collection $products, Supplier $supplier, Pricelist $pricelist, string $property = 'price', int $precision = 2): int
	{
		$priceRepository = $this->getConnection()->findRepository(Price::class);

		$price = $property;
		$priceVat = $property . 'Vat';

		$products->setBufferedQuery(false);
		$array = [];

		while ($draft = $products->fetch()) {
			if ($draft->$price === null || $draft->getValue('product') === null) {
				continue;
			}

			$array[] = [
				'product' => $draft->getValue('product'),
				'pricelist' => $pricelist->getPK(),
				'price' => \round($draft->$price * $supplier->importPriceRatio / 100, $precision),
				'priceVat' => \round($draft->$priceVat * $supplier->importPriceRatio / 100, $precision),
			];
		}

		$priceRepository->syncMany($array);

		return \count($array);
	}

	/**
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function syncAmounts(Collection $products, Store $store): int
	{
		$amountRepository = $this->getConnection()->findRepository(Amount::class);
		$products->setBufferedQuery(false);
		$array = [];

		$amountRepository->many()->where('this.fk_store', $store->getPK())->update([
			'inStock' => 0,
			'reserved' => null,
			'ordered' => null,
		]);

		while ($draft = $products->fetch()) {
			/** @var \Eshop\DB\SupplierProduct $draft */
			$array[] = [
				'product' => $draft->getValue('product'),
				'store' => $store,
				'inStock' => $draft->amount,
			];
		}

		$amountRepository->syncMany($array);

		return \count($array);
	}

	/**
	 * @param \Eshop\DB\Supplier $supplier
	 * @return array<string, \stdClass>
	 */
	public function getBySupplierForImportAmount(Supplier $supplier): array
	{
		return $this->many()
			->where('this.fk_supplier', $supplier->getPK())
			->setSelect([
				'supplierProductPK' => 'this.uuid',
				'supplierProductSupplier' => 'this.fk_supplier',
				'supplierProductDisplayAmount' => 'this.fk_displayAmount',
				'supplierProductDisplayAmountProductAmount' => 'displayAmount.fk_displayAmount',
				'productPK' => 'product.uuid',
				'productDisplayAmount' => 'product.fk_displayAmount',
				'productSupplierContentLock' => 'product.supplierContentLock',
				'productSupplierLock' => 'product.supplierLock',
				'productSupplierContentMode' => 'product.supplierContentMode',
				'productSupplierContent' => 'product.fk_supplierContent',
			], [], true)->fetchArray(\stdClass::class);
	}

	/**
	 * @param callable|null $customCallback
	 * @param (callable(array<string> $notInStockProducts, string $notInStockSetting): int)|null $notInStockCallback
	 * @return array{'positivelyUpdated': int, 'negativelyUpdated': int}
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function syncDisplayAmounts(?callable $customCallback = null, ?callable $notInStockCallback = null): array
	{
		$result = [
			'positivelyUpdated' => 0,
			'negativelyUpdated' => 0,
		];

		/** @var \Eshop\DB\ProductRepository $productRepository */
		$productRepository = $this->getConnection()->findRepository(Product::class);

		$productsMapXSupplierProductsXDisplayAmount = [];

		$this->loadProductsMapXSupplierProductsXDisplayAmount($productsMapXSupplierProductsXDisplayAmount);

		$mergedProductsMap = $productRepository->getGroupedMergedProducts();

		/** @var array<mixed> $productsXDisplayAmounts Contains products paired with all supplier display amounts */
		$productsXDisplayAmounts = [];

		$this->loadProductsXDisplayAmounts($productsXDisplayAmounts, $mergedProductsMap, $productsMapXSupplierProductsXDisplayAmount);

		/** @var \Web\DB\SettingRepository $settingRepository */
		$settingRepository = $this->getConnection()->findRepository(Setting::class);

		$inStockSetting = $settingRepository->getValueByName(SettingsPresenter::SUPPLIER_IN_STOCK_DISPLAY_AMOUNT);
		$notInStockSetting = $settingRepository->getValueByName(SettingsPresenter::SUPPLIER_NOT_IN_STOCK_DISPLAY_AMOUNT);

		if (!$inStockSetting || !$notInStockSetting) {
			return $result;
		}

		$inStockProducts = [];
		$notStockProducts = [];

		$this->loadStock($inStockProducts, $notStockProducts, $productsXDisplayAmounts, $customCallback);

		$result['positivelyUpdated'] = $productRepository->many()
			->where('this.supplierDisplayAmountLock', false)
			->where('this.uuid', $inStockProducts)
			->update([
				'fk_displayAmount' => $inStockSetting,
				'lastInStockTs' => new Literal('NOW()'),
			]);

		$result['negativelyUpdated'] = $notInStockCallback ? $notInStockCallback($notStockProducts, $notInStockSetting) : $productRepository->many()
				->where('this.supplierDisplayAmountLock', false)
				->where('this.uuid', $notStockProducts)
				->update(['fk_displayAmount' => $notInStockSetting]);

		return $result;
	}

	protected function setDraftsCollection(Collection $collection): void
	{
		unset($collection);
	}

	private function loadProductsMapXSupplierProductsXDisplayAmount(array &$productsMapXSupplierProductsXDisplayAmount): void
	{
		foreach ($this->many()->setSelect([
			'uuid' => 'this.uuid',
			'realDisplayAmount' => 'displayAmount.fk_displayAmount',
			'product' => 'this.fk_product',
		])->fetchArray(\stdClass::class) as $supplierProduct) {
			$productsMapXSupplierProductsXDisplayAmount[$supplierProduct->product][$supplierProduct->uuid] = $supplierProduct->realDisplayAmount;
		}
	}

	private function loadProductsXDisplayAmounts(array &$productsXDisplayAmounts, array &$mergedProductsMap, array &$productsMapXSupplierProductsXDisplayAmount): void
	{
		/** @var \Eshop\DB\ProductRepository $productRepository */
		$productRepository = $this->getConnection()->findRepository(Product::class);

		$supplierProducts = $this->many()
			->setSelect([
				'realDisplayAmount' => 'displayAmount.fk_displayAmount',
				'realProducer' => 'producer.fk_producer',
				'supplierDisplayAmountMergedLock' => 'product.supplierDisplayAmountMergedLock',
				'product' => 'this.fk_product',
			])
			->where('this.active', true);

		while ($supplierProduct = $supplierProducts->fetch(\stdClass::class)) {
			/** @var \stdClass $supplierProduct */
			if (!isset($productsXDisplayAmounts[$supplierProduct->product])) {
				$productsXDisplayAmounts[$supplierProduct->product] = [];
			}

			$productsXDisplayAmounts[$supplierProduct->product][] = $supplierProduct->realDisplayAmount;

			if ($supplierProduct->supplierDisplayAmountMergedLock) {
				continue;
			}

			foreach ($mergedProductsMap[$supplierProduct->product] ?? [] as $mergedProduct) {
				foreach ($productsMapXSupplierProductsXDisplayAmount[$mergedProduct] ?? [] as $realDisplayAmount) {
					if (!$realDisplayAmount) {
						continue;
					}

					$productsXDisplayAmounts[$supplierProduct->product][] = $realDisplayAmount;
				}
			}
		}

		$supplierProducts->__destruct();
		unset($supplierProducts);

		$productsWithoutSupplierProducts = $productRepository->many()
			->setSelect(['this.uuid'])
			->join(['e_sp' => 'eshop_supplierproduct'], 'this.uuid = e_sp.fk_product')
			->where('e_sp.uuid IS NULL');

		while ($product = $productsWithoutSupplierProducts->fetch(\stdClass::class)) {
			/** @var \stdClass $product */
			/** @var string $productPK */
			$productPK = $product->uuid;

			foreach ($mergedProductsMap[$productPK] ?? [] as $mergedProduct) {
				foreach ($productsMapXSupplierProductsXDisplayAmount[$mergedProduct] ?? [] as $realDisplayAmount) {
					if (!$realDisplayAmount) {
						continue;
					}

					$productsXDisplayAmounts[$productPK][] = $realDisplayAmount;
				}
			}
		}

		$productsWithoutSupplierProducts->__destruct();
		unset($productsWithoutSupplierProducts);
	}

	private function loadStock(array &$inStockProducts, array &$notStockProducts, array &$productsXDisplayAmounts, ?callable $customCallback = null): void
	{
		/** @var \Eshop\DB\DisplayAmountRepository $displayAmountRepository */
		$displayAmountRepository = $this->getConnection()->findRepository(DisplayAmount::class);

		/** @var array<\Eshop\DB\DisplayAmount> $displayAmounts */
		$displayAmounts = $displayAmountRepository->getCollection()->toArray();

		/** @var \Eshop\DB\ProductRepository $productRepository */
		$productRepository = $this->getConnection()->findRepository(Product::class);

		$allProducts = $productRepository->many()
			->setSelect(['this.uuid'], [], true)
			->toArrayOf('uuid');

		foreach ($allProducts as $productPK) {
			if (!isset($productsXDisplayAmounts[$productPK])) {
				$notStockProducts[] = $productPK;

				continue;
			}

			$draftDisplayAmounts = $productsXDisplayAmounts[$productPK];
			$inStock = false;

			foreach ($draftDisplayAmounts as $displayAmount) {
				if (!isset($displayAmounts[$displayAmount])) {
					continue;
				}

				$displayAmount = $displayAmounts[$displayAmount];

				if (!$displayAmount->isSold) {
					$inStock = true;

					break;
				}
			}

			if ($customCallback) {
				$inStock = $customCallback($inStock, $productPK);
			}

			if ($inStock) {
				$inStockProducts[] = $productPK;
			} else {
				$notStockProducts[] = $productPK;
			}
		}
	}
}
