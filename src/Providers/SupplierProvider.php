<?php

declare(strict_types=1);

namespace Eshop\Providers;

use Eshop\DB\ImportResultRepository;
use Eshop\DB\Supplier;
use Eshop\DB\SupplierCategory;
use Eshop\DB\SupplierDisplayAmount;
use Eshop\DB\SupplierPaymentType;
use Eshop\DB\SupplierPaymentTypeRepository;
use Eshop\DB\SupplierProducer;
use Eshop\DB\SupplierProduct;
use Eshop\DB\SupplierRepository;
use Nette\Application\Application;
use Nette\DI\Container;
use Nette\IOException;
use Nette\Utils\DateTime;
use Nette\Utils\FileSystem;
use Nette\Utils\Image;
use StORM\DIConnection;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;

abstract class SupplierProvider
{
	protected const CATEGORY_SEPARATOR = ' > ';

	public bool $importImages = true;
	
	public bool $importIgnore = false;
	
	public int $insertedCount = 0;
	
	public int $updatedCount = 0;
	
	public int $skippedCount = 0;
	
	public int $imageDownloadCount = 0;
	
	public int $imageErrorCount = 0;

	protected SupplierRepository $supplierRepository;
	
	protected ImportResultRepository $importResultRepository;
	
	protected DIConnection $connection;

	protected Application $application;

	protected SupplierPaymentTypeRepository $supplierPaymentTypeRepository;
	
	protected string $imageDirectory;
	
	protected string $logDirectory;

	protected string $tempDirectory;

	/** @var string[] */
	private array $eans = [];
	
	/** @var string[] */
	private array $codes = [];

	abstract public function getDataProperties(array &$data, array $item): void;
	
	abstract public function getImageUrl(array $item): ?string;

	abstract public function getSupplierId(): string;
	
	abstract public function getProductCodePrefix(): string;
	
	abstract public function getName(): string;
	
	abstract public function import(): Supplier;
	
	public function __construct(
		Container $container,
		DIConnection $connection,
		SupplierRepository $supplierRepository,
		ImportResultRepository $importResultRepository
	) {
		$this->supplierRepository = $supplierRepository;
		$this->importResultRepository = $importResultRepository;
		$this->connection = $connection;

		/** @var \Nette\Application\Application $application */
		$application = $container->getByType(Application::class);
		$this->application = $application;

		/** @var \Eshop\DB\SupplierPaymentTypeRepository $supplierPaymentTypeRepository */
		$supplierPaymentTypeRepository = $connection->findRepository(SupplierPaymentType::class);
		$this->supplierPaymentTypeRepository = $supplierPaymentTypeRepository;

		$this->imageDirectory = $container->parameters['wwwDir'] . '/userfiles/supplier_images';
		$this->logDirectory = $container->parameters['tempDir'] . '/log/import';
		$this->tempDirectory = $container->parameters['tempDir'];
	}
	
	/**
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function initSupplier(): Supplier
	{
		FileSystem::createDir($this->imageDirectory . '/origin');
		FileSystem::createDir($this->imageDirectory . '/detail');
		FileSystem::createDir($this->imageDirectory . '/thumb');
		FileSystem::createDir($this->logDirectory);
		
		$this->supplierRepository->syncOne([
			'uuid' => $this->getSupplierId(),
			'code' => $this->getSupplierId(),
			'name' => $this->getName(),
			'productCodePrefix' => $this->getProductCodePrefix(),
			'providerClass' => static::class,
			'lastImportTs' => (new DateTime())->format('Y-m-d G:i'),
		], ['uuid', 'lastImportTs', 'providerClass', 'productCodePrefix']);
		
		return $this->supplierRepository->one($this->getSupplierId(), true);
	}
	
	public function importImage(string $imageUrl, string $id): ?string
	{
		$fileName = $imageUrl ? $this->getFileName($id, $imageUrl) : null;
		$origin = null;

		if ($fileName) {
			$origin = $this->imageDirectory . \DIRECTORY_SEPARATOR . 'origin' . \DIRECTORY_SEPARATOR . $fileName;
			
			try {
				$filemtime = Helpers::getRemoteFilemtime($imageUrl);
				
				if (\file_exists($origin) && \filemtime($origin) === $filemtime) {
					return $fileName;
				}
				
				if ($filemtime === null) {
					return $fileName;
				}
				
				try {
					FileSystem::copy($imageUrl, $origin);
				} catch (IOException $x) {
					return $fileName;
				}
				
				if (!\file_exists($origin)) {
					$this->imageErrorCount++;
					$this->importResultRepository->log("Image $origin not found");
					
					return null;
				}
				
				\touch($origin, $filemtime);
				
				$image = Image::fromFile($origin);
				$image->resize(600, null);
				$image->save($this->imageDirectory . \DIRECTORY_SEPARATOR . 'detail' . \DIRECTORY_SEPARATOR . $fileName);
				
				$image = Image::fromFile($origin);
				$image->resize(300, null);
				$image->save($this->imageDirectory . \DIRECTORY_SEPARATOR . 'thumb' . \DIRECTORY_SEPARATOR . $fileName);
				
				$this->imageDownloadCount++;
			} catch (\Exception $x) {
				try {
					FileSystem::delete($origin);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}

				try {
					FileSystem::delete($this->imageDirectory . \DIRECTORY_SEPARATOR . 'detail' . \DIRECTORY_SEPARATOR . $fileName);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}

				try {
					FileSystem::delete($this->imageDirectory . \DIRECTORY_SEPARATOR . 'thumb' . \DIRECTORY_SEPARATOR . $fileName);
				} catch (\Throwable $e) {
					Debugger::log($e, ILogger::WARNING);
				}
				
				$this->imageErrorCount++;
				$origin = null;
				
				$this->importResultRepository->log($x->getMessage());
			}
		}
		
		return $origin && \is_file($origin) ? $fileName : null;
	}
	
	public function importDataItem(array $item): void
	{
		$data = [
			SupplierProducer::class => [],
			SupplierCategory::class => [],
			SupplierDisplayAmount::class => [],
			SupplierProduct::class => [],
		];
		
		$this->getDataProperties($data, $item);
		
		if (!$data[SupplierProduct::class]) {
			return;
		}
		
		if ((!isset($data[SupplierProduct::class]['ean']) || $data[SupplierProduct::class]['ean'] === false) || (\is_string($data[SupplierProduct::class]['ean']) &&
				\trim($data[SupplierProduct::class]['ean']) === '')) {
			$data[SupplierProduct::class]['ean'] = null;
		}
		
		if ((!isset($data[SupplierProduct::class]['code']) || $data[SupplierProduct::class]['code'] === false) || (\is_string($data[SupplierProduct::class]['code']) &&
				\trim($data[SupplierProduct::class]['code']) === '')) {
			$data[SupplierProduct::class]['code'] = null;
		}
		
		if (($data[SupplierProduct::class]['code'] && isset($this->codes[$data[SupplierProduct::class]['code']])) ||
			($data[SupplierProduct::class]['ean'] && isset($this->eans[$data[SupplierProduct::class]['ean']]))) {
			$this->skippedCount++;
			
			return;
		}
		
		if ($data[SupplierProduct::class]['code']) {
			$this->codes[$data[SupplierProduct::class]['code']] = $data[SupplierProduct::class]['code'];
		}
		
		if ($data[SupplierProduct::class]['ean']) {
			$this->eans[$data[SupplierProduct::class]['ean']] = $data[SupplierProduct::class]['ean'];
		}
		
		if ($data[SupplierProducer::class]) {
			$data[SupplierProduct::class]['producer'] = $this->connection->findRepository(SupplierProducer::class)->syncOne($data[SupplierProducer::class], ['name']);
		}
		
		if ($data[SupplierCategory::class]) {
			$data[SupplierProduct::class]['category'] =
				$this->connection->findRepository(SupplierCategory::class)->syncOne($data[SupplierCategory::class], ['code', 'categoryNameL1', 'categoryNameL2', 'categoryNameL3', 'categoryNameL4']);
		}
		
		if ($data[SupplierDisplayAmount::class]) {
			$data[SupplierProduct::class]['displayAmount'] = $this->connection->findRepository(SupplierDisplayAmount::class)->syncOne($data[SupplierDisplayAmount::class], ['name'], null, false);
		}
		
		if ($this->importImages && $image = $this->getImageUrl($item)) {
			$data[SupplierProduct::class]['fileName'] = $this->importImage($image, $data[SupplierProduct::class]['uuid']);
		}
		
		if (\mb_strlen($data[SupplierProduct::class]['name']) > 255) {
			$data[SupplierProduct::class]['name'] = \mb_substr($data[SupplierProduct::class]['name'], 0, 255);
		}
		
		try {
			/** @var \Eshop\DB\SupplierProduct $supplierProduct */
			$supplierProduct = $this->connection->findRepository(SupplierProduct::class)->syncOne($data[SupplierProduct::class], null, null, $this->importIgnore);
			$supplierProduct->getParent() instanceof ICollection ? $this->updatedCount++ : $this->insertedCount++;
		} catch (\PDOException $x) {
			if ((int) $x->getCode() === 23000 && \strpos($x->getMessage(), 'supplier_product_ean') !== false) {
				$this->importResultRepository->log('Duplicate item: code - ' . $data[SupplierProduct::class]['code'] . '/ ean - ' . $data[SupplierProduct::class]['ean']);
				
				return;
			}
			
			throw $x;
		}
	}

	protected function getFileName(string $id, string $imageUrl): ?string
	{
		$ext = \pathinfo($imageUrl, \PATHINFO_EXTENSION);
		
		return $ext ? $id . '.' . $ext : null;
	}
}
