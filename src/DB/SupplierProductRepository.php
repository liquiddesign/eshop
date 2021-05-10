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
	
	public function syncProducts(Supplier $supplier, string $mutation, string $country, bool $overwrite): void
	{
		$sep = \DIRECTORY_SEPARATOR;
		$sourceImageDirectory = $this->container->parameters['wwwDir'] . $sep . 'userfiles' . $sep . 'supplier_images';
		$targetImageDirectory = $this->container->parameters['wwwDir'] . $sep . 'userfiles' . $sep . 'product_images';
		
		$vatLevels = $this->getConnection()->findRepository(VatRate::class)->many()->where('fk_country', $country)->setIndex('rate')->toArrayOf('uuid');
		$supplierProductRepository = $this->getConnection()->findRepository(SupplierProduct::class);
		$productRepository = $this->getConnection()->findRepository(Product::class);
		$pagesRepository = $this->getConnection()->findRepository(Page::class);
		$supplierId = $supplier->getPK();
		
		$mutationSuffix = $this->getConnection()->getAvailableMutations()[$mutation];
		
		if ($overwrite) {
			//  "perex$mutationSuffix",
			$updates = ["name$mutationSuffix", "content$mutationSuffix", "unit", 'imageFileName', 'vatRate', 'fk_producer', 'fk_primaryCategory', 'fk_displayAmount'];
			$updates = \array_fill_keys($updates, null);
			
			foreach (\array_keys($updates) as $name) {
				$updates[$name] = new Literal("IF((supplierContentLock = 0 && VALUES(supplierLock) >= supplierLock) || fk_supplierContent='$supplierId', VALUES($name), $name)");
			}
			
		} else {
			$updates = [];
		}
		
		$drafts = $supplierProductRepository->many()
			->where('this.fk_supplier', $supplier)
			->where('category.fk_category IS NOT NULL')
			->where('this.active', true);
		
		foreach ($drafts as $draft) {
			$category = $draft->category ? $draft->category->getValue('category') : null;
			
			if (!$category) {
				continue;
			}
			
			$code = $draft->productCode ?: $supplier->code . $draft->code;
			$uuid = ProductRepository::generateUuid($draft->ean, $draft->getProductFullCode() ?: $supplier->code . '-' . $draft->code);
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
				'imageFileName' => $draft->fileName,
				'vatRate' => $vatLevels[(int) $draft->vatRate] ?? 'standard',
				'producer' => $draft->producer ? $draft->producer->getValue('producer') : null,
				'displayDelivery' => $supplier->getValue('defaultDisplayDelivery'),
				'displayAmount' => $draft->displayAmount ? $draft->displayAmount->getValue('displayAmount') : $supplier->getValue('defaultDisplayAmount'),
				'categories' => $category ? [$category] : [],
				'primaryCategory' => $category,
				'supplierLock' => $supplier->importPriority,
				'supplierSource' => $supplier,
			];
			
			/** @var \Eshop\DB\Product $product */
			$product = $productRepository->syncOne($values, $updates, false, null, ['categories' => false]);
			
			$updated = $product->getParent() instanceof ICollection && $product->getParent()->getAffectedNumber() === InsertResult::UPDATE_AFFECTED_COUNT;
			
			if ($overwrite && $updated) {
				$product->categories->unrelateAll();
				
				if ($draft->category->getValue('category')) {
					$product->categories->relate([$category], false);
				}
			}
			
			if ($draft->getValue('product') !== $uuid && \is_string($uuid)) {
				try {
					$draft->update(['product' => $uuid]);
				} catch (\PDOException $x) {
					;
				}
			}
			
			if (!is_file($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName)) {
				continue;
			}
			
			$mtime = \filemtime($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName);
			
			if (!$updated && $overwrite && $draft->fileName && $mtime !== @\filemtime($targetImageDirectory .  $sep . 'origin' . $sep . $draft->fileName)) {
				\copy($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName, $targetImageDirectory . $sep . 'origin' . $sep . $draft->fileName);
				\copy($sourceImageDirectory . $sep . 'detail' . $sep . $draft->fileName, $targetImageDirectory . $sep . 'detail' . $sep . $draft->fileName);
				\copy($sourceImageDirectory . $sep . 'thumb' . $sep . $draft->fileName, $targetImageDirectory . $sep . 'thumb' . $sep . $draft->fileName);
				\touch($targetImageDirectory . $sep . 'origin' . $sep . $draft->fileName, $mtime);
				\touch($targetImageDirectory . $sep . 'detail' . $sep . $draft->fileName, $mtime);
				\touch($targetImageDirectory . $sep . 'thumb' . $sep . $draft->fileName, $mtime);
			}
			
			$pagesRepository->syncOne([
				'uuid' => $uuid,
				'url' => ['cs' => Strings::webalize($draft->name) . '-' . Strings::webalize($code)],
				'title' => ['cs' => $draft->name],
				'params' => "product=$uuid&",
				'type' => 'product_detail',
			], []);
		}
	}
	
	public function syncPrices(Collection $products, Supplier $supplier, Pricelist $pricelist, string $property = 'price', int $precision = 2): void
	{
		$priceRepository = $this->getConnection()->findRepository(Price::class);
		
		$price = $property;
		$priceVat = $property . 'Vat';
		
		foreach ($products as $draft) {
			if ($draft->$price === null) {
				continue;
			}
			
			$priceRepository->syncOne([
				'product' => $draft->getValue('product'),
				'pricelist' => $pricelist,
				'price' => \round($draft->$price * ($supplier->importPriceRatio / 100), $precision),
				'priceVat' => \round($draft->$priceVat * ($supplier->importPriceRatio / 100), $precision),
			]);
		}
	}
}
