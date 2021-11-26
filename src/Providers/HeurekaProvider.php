<?php

declare(strict_types=1);

namespace Eshop\Providers;

use Eshop\DB\ImportResultRepository;
use Eshop\DB\Supplier;
use Eshop\DB\SupplierCategory;
use Eshop\DB\SupplierProducer;
use Eshop\DB\SupplierProduct;
use Eshop\DB\SupplierRepository;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\DateTime;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use StORM\DIConnection;

class HeurekaProvider extends SupplierProvider
{
	private const ROUND_PRECISION = 2;

	public bool $importImages;

	private Supplier $supplier;
	
	public function __construct(
		Container $container,
		DIConnection $connection,
		SupplierRepository $supplierRepository,
		ImportResultRepository $importResultRepository,
		string $supplierCode,
		bool $images = false
	) {
		parent::__construct($container, $connection, $supplierRepository, $importResultRepository);

		$this->supplier = $supplierRepository->one(['code' => $supplierCode], true);
		$this->importImages = $images;
	}
	
	public function getSupplierId(): string
	{
		return $this->supplier->getPK();
	}
	
	public function getName(): string
	{
		return $this->supplier->name ?? '';
	}
	
	public function getProductCodePrefix(): string
	{
		return $this->supplier->productCodePrefix ?? '';
	}

	public function initSupplier(): Supplier
	{
		FileSystem::createDir($this->imageDirectory . '/origin');
		FileSystem::createDir($this->imageDirectory . '/detail');
		FileSystem::createDir($this->imageDirectory . '/thumb');
		FileSystem::createDir($this->logDirectory);

		$this->supplier->update(['lastImportTs' => (new DateTime())->format('Y-m-d G:i')]);

		return $this->supplier;
	}
	
	public function getDataProperties(array &$data, array $item): void
	{
		$vat = isset($item['VAT']) ? \round(Helpers::parsePrice($item['VAT']), self::ROUND_PRECISION) : 21.0;

		$data[SupplierProduct::class] = [
			'uuid' => DIConnection::generateUuid($this->getSupplierId(), $item['ITEM_ID']),
			'name' => $item['PRODUCT'],
			'content' => $item['DESCRIPTION'] ?? null,
			'ean' => $item['EAN'] ?? null,
			'code' => $item['PRODUCTNO'],
			'unit' => 'ks',
			'vatRate' => $vat,
			'price' => \round(Helpers::getNoVatPrice(Helpers::parsePrice($item['PRICE_VAT']), $vat), self::ROUND_PRECISION),
			'priceVat' => \round(Helpers::parsePrice($item['PRICE_VAT']), self::ROUND_PRECISION),
			'unavailable' => false,
			'supplier' => $this->getSupplierId(),
		];

		if (isset($item['MANUFACTURER'])) {
			$data[SupplierProducer::class] = [
				'uuid' => DIConnection::generateUuid($this->getSupplierId(), Strings::upper(Strings::webalize($item['MANUFACTURER']))),
				'name' => Strings::upper(Strings::webalize($item['MANUFACTURER'])),
				'supplier' => $this->getSupplierId(),
			];
		}
		
		$catTree = \explode(' | ', $item['CATEGORYTEXT']);
		
		if (\count($catTree) <= 1) {
			return;
		}

		\array_shift($catTree);

		$data[SupplierCategory::class] = [
			'uuid' => DIConnection::generateUuid($this->getSupplierId(), Arrays::last($catTree)),
			'categoryNameL1' => $catTree[0],
			'categoryNameL2' => $catTree[1] ?? null,
			'categoryNameL3' => $catTree[2] ?? null,
			'categoryNameL4' => $catTree[3] ?? null,
			'categoryNameL5' => $catTree[4] ?? null,
			'categoryNameL6' => $catTree[5] ?? null,
			'supplier' => $this->getSupplierId(),
		];
	}
	
	public function getImageUrl(array $item): ?string
	{
		return $item['IMGURL'] ?? null;
	}
	
	/**
	 * @throws \Nette\Application\ApplicationException
	 */
	public function import(): Supplier
	{
		$supplier = $this->initSupplier();
		
		try {
			$this->importResultRepository->createLog($supplier, $this->logDirectory);
			
			$xml = \simplexml_load_file($supplier->url);
			
			if ($xml === false) {
				$this->importResultRepository->markAsError('cannot get or parse xml file');
			}
			
			$this->importResultRepository->markAsReceived($xml);

			foreach ($xml->SHOPITEM as $item) {
				$this->importDataItem(Helpers::convertToArray($item));
			}
			
			$this->importResultRepository->markAsImported($this);
		} catch (\Throwable $exception) {
			$this->importResultRepository->markAsError($exception->getMessage());
		}
		
		return $supplier;
	}
}
