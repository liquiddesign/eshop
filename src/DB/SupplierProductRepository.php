<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\ShopsConfig;
use Eshop\Admin\SettingsPresenter;
use Eshop\ShopperUser;
use Nette\DI\Container;
use Nette\InvalidArgumentException;
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
		protected readonly SettingRepository $settingRepository,
		protected readonly ShopperUser $shopperUser,
		protected readonly SupplierProductPhotoRepository $supplierProductPhotoRepository,
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

		// Vytvořit základní složky pro všechny 3 varianty obrázků
		FileSystem::createDir($galleryImageDirectory . $sep . 'origin');
		FileSystem::createDir($galleryImageDirectory . $sep . 'detail');
		FileSystem::createDir($galleryImageDirectory . $sep . 'thumb');

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

		// Sync important data directly without conditions
		$drafts = $supplierProductRepository->many()
			->setGroupBy(['this.uuid'])
			->where('this.fk_product IS NOT NULL')
			->where('product.vatRate = "zero" AND this.vatRate > 0')
			->where('this.fk_supplier', $supplier)
			->where('this.active', true)
			->setSelect([
				'this.uuid',
				'product' => 'this.fk_product',
				'this.vatRate',
				'productVatRate' => 'product.vatRate',
			])
			->fetchGenerator(\stdClass::class);

		$vatLevelsByName = $this->shopperUser->getVatRates();

		foreach ($drafts as $draft) {
			if (\abs($draft->vatRate - $vatLevelsByName[$draft->productVatRate]) > \PHP_FLOAT_EPSILON) {
				$productRepository->many()->where('this.uuid', $draft->product)->update([
					'vatRate' => $vatLevels[(int) $draft->vatRate] ?? 'standard',
				]);
			}

			continue;
		}

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
			->setSelect([
				'contentLock' => 'supplierContentLock',
				'importImages' => 'importSupplierImages',
				'sourcePK' => 'fk_supplierSource',
				'imageFileName' => 'imageFileName',
			], [], true)
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

		$productContentQuery = $productContentRepository->many()
			->select(['productPK' => 'this.fk_product', 'shopPK' => 'this.fk_shop', 'content' => "this.content$mutationSuffix"]);

		while ($productContent = $productContentQuery->fetch(\stdClass::class)) {
			/** @var \stdClass $productContent */
			$existingProductContents[$productContent->productPK][$productContent->shopPK] = $productContent;
		}

		$productContentQuery->__destruct();
		unset($productContentQuery);

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
				'length' => $draft->length,
				'width' => $draft->width,
				'depth' => $draft->depth,
				'supplierLock' => $supplier->importPriority,
				'supplierSource' => $supplier,
			];

			$importImage = true;

			if (!$importImages ||
				!$supplier->importImages ||
				!isset($productsMap[$uuid])
			) {
				$importImage = false;
			}

			// NOVÁ KONTROLA: pokud je importSupplierImages = false, NEIMPORTOVAT
			if ($importImage && $productsMap[$uuid]->importImages === false) {
				$importImage = false;
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

			// Načíst všechny SupplierProductPhoto pro tento dodavatelský produkt
			/** @var array<\Eshop\DB\SupplierProductPhoto> $supplierProductPhotos */
			$supplierProductPhotos = $this->supplierProductPhotoRepository->many()
				->where('fk_supplierProduct', $draft->getPK())
				->orderBy(['priority' => 'ASC'])
				->toArray();

			if (!$supplierProductPhotos) {
				continue;
			}

			// Nastavit primární obrázek (imageFileName), pokud ještě není vyplněný
			if (!isset($productsMap[$uuid]->imageFileName) || !$productsMap[$uuid]->imageFileName) {
				$firstPhoto = Arrays::first($supplierProductPhotos);

				if ($firstPhoto instanceof \Eshop\DB\SupplierProductPhoto) {
					$product->update(['imageFileName' => $firstPhoto->fileName]);
				}
			}

			// Najít existující Photo od dodavatele (starý systém - 1 SupplierProduct = 1 Photo)
			$existingPhotos = $photoRepository->many()
				->where('fk_product', $product->getPK())
				->where('fk_supplier', $supplierId)
				->orderBy(['priority' => 'ASC', 'uuid' => 'ASC'])
				->toArray();

			$firstExistingPhoto = Arrays::first($existingPhotos);
			$first = true;

			// Pro každou dodavatelskou fotku vytvořit Photo entitu
			foreach ($supplierProductPhotos as $supplierPhoto) {
				if (!\is_file($sourceImageDirectory . $sep . 'origin' . $sep . $supplierPhoto->fileName)) {
					continue;
				}

				// PRVNÍ fotka: Propojit s existující starým Photo (zachovat SEO a fileName)
				if ($firstExistingPhoto && $first) {
					$firstExistingPhoto->update([
						'supplierProductPhoto' => $supplierPhoto->getPK(),
						'priority' => $supplierPhoto->priority,
					]);

					// Zkontrolovat existenci souborů v galerii a nakopírovat chybějící
					$sourceOrigin = $sourceImageDirectory . $sep . 'origin' . $sep . $supplierPhoto->fileName;
					$targetOrigin = $galleryImageDirectory . $sep . 'origin' . $sep . $firstExistingPhoto->fileName;

					// Origin - prostě zkopírovat
					if (\is_file($sourceOrigin) && !\is_file($targetOrigin)) {
						FileSystem::copy($sourceOrigin, $targetOrigin);
					}

					// Detail (600px) - zkopírovat nebo vytvořit z origin
					$targetDetail = $galleryImageDirectory . $sep . 'detail' . $sep . $firstExistingPhoto->fileName;

					if (!\is_file($targetDetail)) {
						$sourceDetail = $sourceImageDirectory . $sep . 'detail' . $sep . $supplierPhoto->fileName;

						if (\is_file($sourceDetail)) {
							FileSystem::copy($sourceDetail, $targetDetail);
						} elseif (\is_file($sourceOrigin)) {
							try {
								// phpcs:ignore
								$image = @Image::fromFile($sourceOrigin);
								$image->resize(600, null);
								// Normalize problematic extensions to .jpg for Nette Image compatibility
								$targetDetailNormalized = \preg_replace('/\.asp\?.*$/i', '.jpg', $targetDetail);
								$targetDetailNormalized = \preg_replace('/\.jfif$/i', '.jpg', $targetDetailNormalized);
								$image->save($targetDetailNormalized, 100);
							} catch (\Throwable $e) {
								Debugger::log($e, ILogger::WARNING);
							}
						}
					}

					// Thumb (300px) - zkopírovat nebo vytvořit z origin
					$targetThumb = $galleryImageDirectory . $sep . 'thumb' . $sep . $firstExistingPhoto->fileName;

					if (!\is_file($targetThumb)) {
						$sourceThumb = $sourceImageDirectory . $sep . 'thumb' . $sep . $supplierPhoto->fileName;

						if (\is_file($sourceThumb)) {
							FileSystem::copy($sourceThumb, $targetThumb);
						} elseif (\is_file($sourceOrigin)) {
							try {
								// phpcs:ignore
								$image = @Image::fromFile($sourceOrigin);
								$image->resize(300, null);
								// Normalize problematic extensions to .jpg for Nette Image compatibility
								$targetThumbNormalized = \preg_replace('/\.asp\?.*$/i', '.jpg', $targetThumb);
								$targetThumbNormalized = \preg_replace('/\.jfif$/i', '.jpg', $targetThumbNormalized);
								$image->save($targetThumbNormalized, 100);
							} catch (\Throwable $e) {
								Debugger::log($e, ILogger::WARNING);
							}
						}
					}

					$first = false;

					// NEPŘIDÁVAT nové Photo pro první obrázek
					continue;
				}

				// DALŠÍ fotky nebo NOVÝ produkt: Vytvořit nové Photo entity
				$photoRepository->syncOne([
					'uuid' => $supplierPhoto->getPK(),
					'product' => $product->getPK(),
					'supplier' => $supplierId,
					'fileName' => $supplierPhoto->fileName,
					'priority' => $supplierPhoto->priority,
					'supplierProductPhoto' => $supplierPhoto->getPK(),
				]);

				// Zkontrolovat, jestli kopírovat soubory
				// phpcs:ignore
				$mtime = @\filemtime($sourceImageDirectory . $sep . 'origin' . $sep . $supplierPhoto->fileName);

				// phpcs:ignore
				$copyImage = !(!$overwrite || !$supplierPhoto->fileName || $mtime === @\filemtime($galleryImageDirectory . $sep . 'origin' . $sep . $supplierPhoto->fileName));

				if (!$copyImage) {
					continue;
				}

				try {
					// Kopírovat origin
					FileSystem::copy(
						$sourceImageDirectory . $sep . 'origin' . $sep . $supplierPhoto->fileName,
						$galleryImageDirectory . $sep . 'origin' . $sep . $supplierPhoto->fileName
					);
					\touch($galleryImageDirectory . $sep . 'origin' . $sep . $supplierPhoto->fileName, $mtime);

					// Vytvořit/zkopírovat detail (600px)
					if (\is_file($sourceImageDirectory . $sep . 'detail' . $sep . $supplierPhoto->fileName)) {
						FileSystem::copy(
							$sourceImageDirectory . $sep . 'detail' . $sep . $supplierPhoto->fileName,
							$galleryImageDirectory . $sep . 'detail' . $sep . $supplierPhoto->fileName
						);
					} else {
						// phpcs:ignore
						$image = @Image::fromFile($sourceImageDirectory . $sep . 'origin' . $sep . $supplierPhoto->fileName);
						$image->resize(600, null);
						// Normalize problematic extensions to .jpg for Nette Image compatibility
						$detailFileName = \preg_replace('/\.asp\?.*$/i', '.jpg', $supplierPhoto->fileName);
						$detailFileName = \preg_replace('/\.jfif$/i', '.jpg', $detailFileName);
						$image->save($galleryImageDirectory . $sep . 'detail' . $sep . $detailFileName, 100);
					}

					// Vytvořit/zkopírovat thumb (300px)
					if (\is_file($sourceImageDirectory . $sep . 'thumb' . $sep . $supplierPhoto->fileName)) {
						FileSystem::copy(
							$sourceImageDirectory . $sep . 'thumb' . $sep . $supplierPhoto->fileName,
							$galleryImageDirectory . $sep . 'thumb' . $sep . $supplierPhoto->fileName
						);
					} else {
						// phpcs:ignore
						$image = @Image::fromFile($sourceImageDirectory . $sep . 'origin' . $sep . $supplierPhoto->fileName);
						$image->resize(300, null);
						// Normalize problematic extensions to .jpg for Nette Image compatibility
						$thumbFileName = \preg_replace('/\.asp\?.*$/i', '.jpg', $supplierPhoto->fileName);
						$thumbFileName = \preg_replace('/\.jfif$/i', '.jpg', $thumbFileName);
						$image->save($galleryImageDirectory . $sep . 'thumb' . $sep . $thumbFileName, 100);
					}
				} catch (\Throwable $e) {
					if ($e instanceof InvalidArgumentException && \str_starts_with($e->getMessage(), 'Unsupported file extension')) {
						Debugger::log($e, ILogger::INFO);
					} else {
						Debugger::log($e, ILogger::WARNING);
					}
				}
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
				($product->supplierLock >= $supplier->importPriority && $product->supplierContentMode === 'priority')
			) {
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

	public function syncLogisticsData(Supplier $supplier): void
	{
		/** @var \StORM\Collection<\Eshop\DB\SupplierProduct> $drafts */
		$drafts = $this->many()
			->where('this.fk_supplier', $supplier->getPK())
			->where('this.active', true)
			->where('this.fk_product IS NOT NULL')
			->selectAliases(['product'])
			->where('this.weight IS NOT NULL OR this.width IS NOT NULL OR this.length IS NOT NULL OR this.depth IS NOT NULL')
			->where('product.weight IS NULL OR product.width IS NULL OR product.length IS NULL OR product.depth IS NULL');

		$productRepository = $this->getConnection()->findRepository(Product::class);

		while ($draft = $drafts->fetch()) {
			$productRepository->syncOne([
				'uuid' => $draft->getValue('product'),
				'weight' => $draft->getValue('product_weight') ?: $draft->weight,
				'width' => $draft->getValue('product_width') ?: $draft->width,
				'length' => $draft->getValue('product_length') ?: $draft->length,
				'depth' => $draft->getValue('product_depth') ?: $draft->depth,
				], ['weight', 'width', 'length', 'depth',], ignore: false);
		}

		$drafts->__destruct();
		unset($drafts);
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
			])->fetchArray(\stdClass::class) as $supplierProduct
		) {
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
