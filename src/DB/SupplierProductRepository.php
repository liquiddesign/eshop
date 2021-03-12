<?php

declare(strict_types=1);

namespace Eshop\DB;

use App\Web\DB\Page;
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
		
		$mutationSuffix = $this->getConnection()->getAvailableMutations()[$mutation];
		
		if ($overwrite) {
			//  "perex$mutationSuffix",
			$updates = ["name$mutationSuffix", "content$mutationSuffix", "unit", 'imageFileName', 'vatRate', 'fk_producer', 'fk_primaryCategory', 'fk_displayAmount'];
			$updates = \array_fill_keys($updates, null);
			
			foreach (\array_keys($updates) as $name) {
				$updates[$name] = new Literal("IF(VALUES(supplierLock) >= supplierLock, VALUES($name), $name)");
			}
		} else {
			$updates = [];
		}
		
		foreach ($supplierProductRepository->many()->where('fk_supplier', $supplier)->where('active', true) as $draft) {
			
			$code = $draft->productCode ?: $supplier->code . '-' . $draft->code;
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
				'imageFileName' => $draft->fileName,
				'vatRate' => $vatLevels[(int) $draft->vatRate] ?? 'standard',
				'producer' => $draft->producer->getValue('producer'),
				'displayAmount' => $draft->displayAmount ? $draft->displayAmount->getValue('displayAmount') : null,
				'categories' => $draft->category->getValue('category') ? [$draft->category->getValue('category')] : [],
				'primaryCategory' => $draft->category ? $draft->category->getValue('category') : null,
				'supplierLock' => $supplier->importPriority,
				'supplierSource' => $supplier,
			];
			
			/** @var \Eshop\DB\Product $product */
			$product = $productRepository->syncOne($values, $updates);
			
			if ($overwrite && $product->getParent() instanceof ICollection && $product->getParent()->getAffectedNumber() === InsertResult::UPDATE_AFFECTED_COUNT) {
				$product->categories->unrelateAll();
				
				if ($draft->category->getValue('category')) {
					$product->categories->relate([$draft->category->getValue('category')]);
				}
			}
			
			if ($draft->getValue('product') !== $uuid) {
				$draft->update(['product' => $uuid]);
			}
			
			if (!is_file($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName)) {
				continue;
			}
			
			$mtime = \filemtime($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName);
			
			if ($overwrite && $draft->fileName && $mtime !== @\filemtime($targetImageDirectory .  $sep . 'origin' . $sep . $draft->fileName)) {
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
	
	public function syncPrices(Supplier $supplier, Pricelist $pricelist, string $type): void
	{
		$priceRepository = $this->getConnection()->findRepository(Price::class);
		$property = "price$type";
		$propertyVat = "priceVat$type";
		$collection = $this->many()->where('fk_product IS NOT NULL');
		
		foreach ($collection as $draft) {
			if ($draft->$property === null) {
				continue;
			}
			
			$priceRepository->syncOne([
				'product' => $draft->getValue('product'),
				'pricelist' => $pricelist,
				'price' => $draft->$property,
				'priceVat' => $draft->$propertyVat,
			]);
		}
	}
}
