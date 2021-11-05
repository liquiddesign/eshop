<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\DI\Container;
use Nette\Utils\Strings;
use PDOException;
use StORM\Collection;
use StORM\DIConnection;
use StORM\ICollection;
use StORM\Literal;
use StORM\SchemaManager;
use Web\DB\Page;

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
	 * @return int[]
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
		$targetImageDirectory = $this->container->parameters['wwwDir'] . $sep . 'userfiles' . $sep . 'product_images';
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
			$updates = ["name$mutationSuffix", "content$mutationSuffix", "unit", 'imageFileName', 'vatRate', 'fk_displayAmount'];
			$updates = \array_fill_keys($updates, null);

			foreach (\array_keys($updates) as $name) {
				$updates[$name] = new Literal("IF((supplierContentLock = 0 && VALUES(supplierLock) >= supplierLock) || fk_supplierContent='$supplierId', VALUES($name), $name)");
			}
		} else {
			$updates = [];
		}
		
		$productsMap = $productRepository->many()
			->setSelect(['contentLock' => 'supplierContentLock', 'sourcePK' => 'fk_supplierSource'], [], true)
			->setBufferedQuery(false)
			->setFetchClass(\stdClass::class)
			->toArray();

		$drafts = $supplierProductRepository->many()
			->select(['realCategory' => 'category.fk_category'])
			->select(['realDisplayAmount' => 'displayAmount.fk_displayAmount'])
			->select(['realProducer' => 'producer.fk_producer'])
			->where('this.fk_supplier', $supplier)
			->where('category.fk_category IS NOT NULL')
			->where('this.active', true);

		while ($draft = $drafts->fetch()) {
			$category = $draft->realCategory;
			$displayAmount = $draft->realDisplayAmount;
			$producer = $draft->realProducer;
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
				// jen pokud neni mozne parovat
				'supplierCode' => $draft->code,
				'name' => [$mutation => $draft->name],
				//'perex' => [$mutation => substr($draft->content, 0, 150)],
				'content' => [$mutation => $draft->content],
				'unit' => $draft->unit,
				'unavailable' => $draft->unavailable,
				'hidden' => $supplier->defaultHiddenProduct,
				'vatRate' => $vatLevels[(int)$draft->vatRate] ?? 'standard',
				'producer' => $producer,
				'displayDelivery' => $supplier->getValue('defaultDisplayDelivery'),
				'displayAmount' => $displayAmount ?: $supplier->getValue('defaultDisplayAmount'),
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
				} catch (PDOException $x) {
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

			if (!$importImages ||
				!$supplier->importImages ||
				!\is_file($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName) ||
				!isset($productsMap[$uuid]) ||
				$productsMap[$uuid]->contentLock
			) {
				continue;
			}

			$currentTargetImageDirectory = $primary ? $targetImageDirectory : $galleryImageDirectory;

			$mtime = \filemtime($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName);

			if (!$primary) {
				$photoRepository->syncOne([
					'uuid' => $draft->getPK(),
					'product' => $product->getPK(),
					'supplier' => $supplierId,
					'fileName' => $draft->fileName,
				]);
			}

			if (!$overwrite || !$draft->fileName || $mtime === @\filemtime($currentTargetImageDirectory . $sep . 'origin' . $sep . $draft->fileName)) {
				continue;
			}

			@\copy($sourceImageDirectory . $sep . 'origin' . $sep . $draft->fileName, $currentTargetImageDirectory . $sep . 'origin' . $sep . $draft->fileName);
			@\copy($sourceImageDirectory . $sep . 'detail' . $sep . $draft->fileName, $currentTargetImageDirectory . $sep . 'detail' . $sep . $draft->fileName);
			@\copy($sourceImageDirectory . $sep . 'thumb' . $sep . $draft->fileName, $currentTargetImageDirectory . $sep . 'thumb' . $sep . $draft->fileName);
			@\touch($currentTargetImageDirectory . $sep . 'origin' . $sep . $draft->fileName, $mtime);
			@\touch($currentTargetImageDirectory . $sep . 'detail' . $sep . $draft->fileName, $mtime);
			@\touch($currentTargetImageDirectory . $sep . 'thumb' . $sep . $draft->fileName, $mtime);
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

		while ($draft = $products->fetch()) {
			if ($draft->amount === null || $draft->amount === 0 || $draft->getValue('product') === null) {
				continue;
			}

			$array[] = [
				'product' => $draft->getValue('product'),
				'store' => $store,
				'inStock' => $draft->amount,
			];
		}

		$amountRepository->syncMany($array);

		return \count($array);
	}
}
