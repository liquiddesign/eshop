<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Admin\SettingsPresenter;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
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

/**
 * @extends \StORM\Repository<\Eshop\DB\SupplierProduct>
 */
class SupplierProductRepository extends \StORM\Repository
{
	private Container $container;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, Container $container)
	{
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
		$supplierId = $supplier->getPK();
		$attributeAssignRepository = $this->getConnection()->findRepository(AttributeAssign::class);
		$photoRepository = $this->getConnection()->findRepository(Photo::class);
		$mutationSuffix = $this->getConnection()->getAvailableMutations()[$mutation];
		$riboonId = 'novy_import';

		if ($overwrite) {
			$updates = ["name$mutationSuffix", "content$mutationSuffix", 'unit', 'imageFileName', 'vatRate', 'fk_displayAmount'];
			$updates = \array_fill_keys($updates, null);

			foreach (\array_keys($updates) as $name) {
				$updates[$name] = new Literal("IF
				(
					(
						supplierContentLock = 0 && 
						(
							(VALUES(supplierLock) >= supplierLock && (supplierContentMode = 'priority' || (fk_supplierContent IS NULL && supplierContentMode = 'none'))) ||
							(supplierContentMode = 'length' && LENGTH(VALUES(content$mutationSuffix)) > LENGTH(content$mutationSuffix))
						)
					)
					|| fk_supplierContent='$supplierId',
					VALUES($name),
					$name
				)");
			}
		} else {
			$updates = [];
		}

		$productsMap = $productRepository->many()
			->setSelect(['contentLock' => 'supplierContentLock', 'sourcePK' => 'fk_supplierSource'], [], true)
			->setBufferedQuery(false)
			->fetchArray(\stdClass::class);

		$eanProductsMap = $productRepository->many()
			->setSelect(['ean', 'uuid'], [], true)
			->setBufferedQuery(false)
			->setIndex('ean')
			->toArrayOf('uuid');

		$drafts = $supplierProductRepository->many()
			->select(['realCategory' => 'category.fk_category'])
			->select(['realDisplayAmount' => 'displayAmount.fk_displayAmount'])
			->select(['realProducer' => 'producer.fk_producer'])
			->where('this.fk_supplier', $supplier)
			->where('category.fk_category IS NOT NULL')
			->where('this.active', true);

		while ($draft = $drafts->fetch()) {
			/** @var \stdClass|\Eshop\DB\SupplierProduct $draft */
			$category = $draft->realCategory;
			$displayAmount = $draft->realDisplayAmount;
			$producer = $draft->realProducer;
			$currentUpdates = $updates;

			if (!$category) {
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
				//'perex' => [$mutation => substr($draft->content, 0, 150)],
				'content' => [$mutation => $draft->content],
				'unit' => $draft->unit,
				'unavailable' => $draft->unavailable,
				'hidden' => $supplier->defaultHiddenProduct,
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
				'categories' => [$category],
				'primaryCategory' => $category,
				'supplierLock' => $supplier->importPriority,
				'supplierSource' => $supplier,
			];

			$importImagesResult = true;

			if (!$importImages ||
				!$supplier->importImages ||
				!\is_file($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName) ||
				!isset($productsMap[$uuid])
			) {
				$importImagesResult = false;
			}

			if ($importImagesResult) {
				$mtime = \filemtime($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName);

				if (!$overwrite || !$draft->fileName || $mtime === \filemtime($galleryImageDirectory . $sep . 'origin' . $sep . $draft->fileName)) {
					$importImagesResult = false;
				}
			}

			if ($primary && $importImagesResult) {
				$values['imageFileName'] = $draft->fileName;
			} else {
				unset($currentUpdates['imageFileName']);
			}

			$product = $productRepository->syncOne($values, $currentUpdates, false, null, ['categories' => false]);

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

			if (isset($productsMap[$uuid]) && $productsMap[$uuid]->contentLock) {
				$result['locked']++;
			}

			foreach ($this->getConnection()->findRepository(SupplierAttributeValue::class)->many()
						 ->join(['assign' => 'eshop_supplierattributevalueassign'], 'assign.fk_supplierAttributeValue=this.uuid')
						 ->where('assign.fk_supplierProduct', $draft)
						 ->where('this.fk_attributeValue IS NOT NULL') as $attributeValue) {
				$attributeAssignRepository->syncOne([
					'value' => $attributeValue->getValue('attributeValue'),
					'product' => $product,
				]);
			}

			if ($draft->getValue('product') !== $uuid && \is_string($uuid)) {
				try {
					$draft->update(['product' => $uuid]);
				} catch (\Throwable $x) {
					unset($x);

					try {
						if (isset($eanProductsMap[$draft->ean])) {
							$draft->update(['product' => $eanProductsMap[$draft->ean]]);
						}
					} catch (\Throwable $e) {
						unset($e);
					}
				}
			}

			$pagesRepository->syncOne([
				'uuid' => $uuid,
				'url' => ['cs' => Strings::webalize($draft->name) . '-' . Strings::webalize($code)],
				'title' => ['cs' => $draft->name],
				'params' => "product=$uuid&",
				'type' => 'product_detail',
			], []);

			if (!$importImagesResult || !isset($mtime)) {
				continue;
			}

			$photoRepository->syncOne([
				'uuid' => $draft->getPK(),
				'product' => $product->getPK(),
				'supplier' => $supplierId,
				'fileName' => $draft->fileName,
			]);

			$imageSizes = ['origin', 'detail', 'thumb'];

			foreach ($imageSizes as $imageSize) {
				try {
					FileSystem::copy($sourceImageDirectory . $sep . $imageSize . $sep . $draft->fileName, $galleryImageDirectory . $sep . $imageSize . $sep . $draft->fileName);
					\touch($galleryImageDirectory . $sep . $imageSize . $sep . $draft->fileName, $mtime);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}
			}
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

		$amountRepository->many()->where('this.fk_store', $store->getPK())->delete();

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
	 * @return array{'positivelyUpdated': int, 'negativelyUpdated': int}
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function syncDisplayAmounts(?callable $customCallback = null): array
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
			->update(['fk_displayAmount' => $inStockSetting]);

		$result['negativelyUpdated'] = $productRepository->many()
			->where('this.supplierDisplayAmountLock', false)
			->where('this.uuid', $notStockProducts)
			->update(['fk_displayAmount' => $notInStockSetting]);

		return $result;
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
		$supplierProducts = $this->many()
			->setSelect([
				'realDisplayAmount' => 'displayAmount.fk_displayAmount',
				'realProducer' => 'producer.fk_producer',
				'supplierDisplayAmountMergedLock' => 'product.supplierDisplayAmountMergedLock',
				'product' => 'this.fk_product',
			])
			->where('this.active', true);

		foreach ($supplierProducts->fetchArray(\stdClass::class) as $supplierProduct) {
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
	}

	private function loadStock(array &$inStockProducts, array &$notStockProducts, array &$productsXDisplayAmounts, ?callable $customCallback = null): void
	{
		/** @var \Eshop\DB\DisplayAmountRepository $displayAmountRepository */
		$displayAmountRepository = $this->getConnection()->findRepository(DisplayAmount::class);

		/** @var array<\Eshop\DB\DisplayAmount> $displayAmounts */
		$displayAmounts = $displayAmountRepository->getCollection()->toArray();

		/** @var \Eshop\DB\ProductRepository $productRepository */
		$productRepository = $this->getConnection()->findRepository(Product::class);

		$allProducts = $productRepository->many()->setSelect(['this.uuid'], [], true)->toArrayOf('uuid');

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
