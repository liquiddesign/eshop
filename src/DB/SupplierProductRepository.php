<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Collection;
use Web\DB\Page;
use Nette\DI\Container;
use Nette\Utils\Strings;
use StORM\DIConnection;
use StORM\ICollection;
use StORM\InsertResult;
use StORM\Literal;
use StORM\SchemaManager;

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
		$targetImageDirectory = $this->container->parameters['wwwDir'] . $sep . 'userfiles' . $sep . 'product_images';
		$galleryImageDirectory = $this->container->parameters['wwwDir'] . $sep . 'userfiles' . $sep . 'product_gallery_images';
		
		$vatLevels = $this->getConnection()->findRepository(VatRate::class)->many()->where('fk_country', $country)->setIndex('rate')->toArrayOf('uuid');
		$supplierProductRepository = $this->getConnection()->findRepository(SupplierProduct::class);
		$productRepository = $this->getConnection()->findRepository(Product::class);
		$pagesRepository = $this->getConnection()->findRepository(Page::class);
		$supplierId = $supplier->getPK();
		$attributeAssignRepository = $this->getConnection()->findRepository(AttributeAssign::class);
		$photoRepository = $this->getConnection()->findRepository(Photo::class);
		$mutationSuffix = $this->getConnection()->getAvailableMutations()[$mutation];
		
		if ($overwrite) {
			$updates = ["name$mutationSuffix", "content$mutationSuffix", "unit", 'imageFileName', 'vatRate', 'fk_displayAmount'];
			$updates = \array_fill_keys($updates, null);
			
			foreach (\array_keys($updates) as $name) {
				$updates[$name] = new Literal("IF((supplierContentLock = 0 && VALUES(supplierLock) >= supplierLock) || fk_supplierContent='$supplierId', VALUES($name), $name)");
			}
			
		} else {
			$updates = [];
		}
		
		$productsMap = $productRepository->many()->setSelect(['contentLock' => 'supplierContentLock', 'sourcePK' => 'fk_supplierSource'], [], true)->setFetchClass(\stdClass::class)->toArray();
		
		$drafts = $supplierProductRepository->many()
			->where('this.fk_supplier', $supplier)
			->where('category.fk_category IS NOT NULL')
			->where('this.active', true)
			->setBuffedQuery(false);
		
		while ($draft = $drafts->fetch()) {
			$category = $draft->category ? $draft->category->getValue('category') : null;
			$currentUpdates = $updates;
			
			if (!$category) {
				continue;
			}
			
			$code = $draft->productCode ?: ($supplier->productCodePrefix ?: $supplier->code) . $draft->code;
			$uuid = ProductRepository::generateUuid($draft->ean, $draft->getProductFullCode() ?: $supplier->code . '-' . $draft->code);
			$primary = isset($productsMap[$uuid]) ? $productsMap[$uuid]->sourcePK === $supplierId : false;
			
			$values = [
				'uuid' => $uuid,
				'ean' => $draft->ean ?: null,
				'code' => $code,
				'subCode' => $draft->productSubCode,
				'supplierCode' => $draft->code, // jen pokud neni mozne parovat
				'name' => [$mutation => $draft->name],
				//'perex' => [$mutation => substr($draft->content, 0, 150)],
				'content' => [$mutation => $draft->content],
				'unit' => $draft->unit,
				'unavailable' => $draft->unavailable,
				'hidden' => $supplier->defaultHiddenProduct,
				'vatRate' => $vatLevels[(int)$draft->vatRate] ?? 'standard',
				'producer' => $draft->producer ? $draft->producer->getValue('producer') : null,
				'displayDelivery' => $supplier->getValue('defaultDisplayDelivery'),
				'displayAmount' => $draft->displayAmount ? $draft->displayAmount->getValue('displayAmount') : $supplier->getValue('defaultDisplayAmount'),
				'categories' => $category ? [$category] : [],
				'primaryCategory' => $category,
				'supplierLock' => $supplier->importPriority,
				'supplierSource' => $supplier,
			];
			
			if ($primary) {
				$values['imageFileName'] = $draft->fileName;
			} else {
				unset($currentUpdates['imageFileName']);
			}
			
			/** @var \Eshop\DB\Product $product */
			$product = $productRepository->syncOne($values, $currentUpdates, false, null, ['categories' => false]);
			
			$updated = $product->getParent() instanceof ICollection;
			
			if ($updated) {
				$result['updated']++;
			} else {
				$result['inserted']++;
			}
			
			if (isset($productsMap[$uuid]) && $productsMap[$uuid]->contentLock) {
				$result['locked']++;
			}
			
			foreach ($this->getConnection()->findRepository(SupplierAttributeValueAssign::class)->many()->where('fk_supplierProduct', $draft) as $attributeValue) {
				$attributeAssignRepository->syncOne([
					'value' => $attributeValue->getValue('attributeValue'),
					'product' => $product,
				]);
			}
			
			if ($draft->getValue('product') !== $uuid && \is_string($uuid)) {
				try {
					$draft->update(['product' => $uuid]);
				} catch (\PDOException $x) {
					unset($x);
				}
			}
			
			$pagesRepository->syncOne([
				'uuid' => $uuid,
				'url' => ['cs' => Strings::webalize($draft->name) . '-' . Strings::webalize($code)],
				'title' => ['cs' => $draft->name],
				'params' => "product=$uuid&",
				'type' => 'product_detail',
			], []);
			
			//dump(memory_get_usage(true));
			
			if (!$importImages || !$supplier->importImages || !\is_file($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName) || !isset($productsMap[$uuid]) || $productsMap[$uuid]->contentLock) {
				continue;
			}
			
			$currentTargetImageDirectory = $primary ? $targetImageDirectory : $galleryImageDirectory;
			
			$mtime = \filemtime($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName);
			
			if (!$primary) {
				$photoRepository->syncOne([
					'uuid' => $draft->getPK(),
					'product' => $product->getPK(),
					'supplier' => $supplierId,
					'fileName' => $draft->fileName
				]);
			}
			
			if ($overwrite && $draft->fileName && $mtime !== @\filemtime($currentTargetImageDirectory . $sep . 'origin' . $sep . $draft->fileName)) {
				@\copy($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName, $currentTargetImageDirectory . $sep . 'origin' . $sep . $draft->fileName);
				@\copy($sourceImageDirectory . $sep . 'detail' . $sep . $draft->fileName, $currentTargetImageDirectory . $sep . 'detail' . $sep . $draft->fileName);
				@\copy($sourceImageDirectory . $sep . 'thumb' . $sep . $draft->fileName, $currentTargetImageDirectory . $sep . 'thumb' . $sep . $draft->fileName);
				@\touch($currentTargetImageDirectory . $sep . 'origin' . $sep . $draft->fileName, $mtime);
				@\touch($currentTargetImageDirectory . $sep . 'detail' . $sep . $draft->fileName, $mtime);
				@\touch($currentTargetImageDirectory . $sep . 'thumb' . $sep . $draft->fileName, $mtime);
			}
		}
		
		return $result;
	}

	public function syncPrices(Collection $products, Supplier $supplier, Pricelist $pricelist, string $property = 'price', int $precision = 2): int
	{
		$priceRepository = $this->getConnection()->findRepository(Price::class);

		$i = 0;
		$price = $property;
		$priceVat = $property . 'Vat';
		
		$products->setBuffedQuery(false);
		
		while ($draft = $products->fetch()) {
			if ($draft->$price === null) {
				continue;
			}
			
			$priceRepository->syncOne([
				'product' => $draft->getValue('product'),
				'pricelist' => $pricelist,
				'price' => \round($draft->$price * ($supplier->importPriceRatio / 100), $precision),
				'priceVat' => \round($draft->$priceVat * ($supplier->importPriceRatio / 100), $precision),
			]);
			
			$i++;
		}
		
		return $i;
	}

	public function syncAmounts(Collection $products, Store $store): int
	{
		$i = 0;
		$amountRepository = $this->getConnection()->findRepository(Amount::class);
		$products->setBuffedQuery(false);
		
		while ($draft = $products->fetch()) {
			if ($draft->amount === null || $draft->amount === 0) {
				continue;
			}

			$amountRepository->syncOne([
				'product' => $draft->getValue('product'),
				'store' => $store,
				'inStock' => $draft->amount,
			]);
			
			$i++;
		}
	}
}
