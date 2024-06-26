<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\DIConnection;
use StORM\Repository;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Supplier>
 */
class SupplierRepository extends Repository implements IGeneralRepository
{
	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		private readonly SupplierProductRepository $supplierProductRepository,
		private readonly PriceRepository $priceRepository,
		private readonly SupplierCategoryRepository $supplierCategoryRepository,
		private readonly ImportResultRepository $importResultRepository
	) {
		parent::__construct($connection, $schemaManager);
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		unset($includeHidden);

		return $this->many()->orderBy(['name'])->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('hidden', false);
		}

		return $collection->orderBy(['priority', 'name']);
	}

	public function catalogEntry(Supplier $supplier, $logDirectory, bool $onlyNew = false, bool $importImages = true): void
	{
		$this->importResultRepository->createLog($supplier, $logDirectory, 'entry');

		$currency = 'CZK';
		$mutation = 'cs';
		$country = 'CZ';

		$result = $this->supplierProductRepository->syncProducts($supplier, $mutation, $country, !$onlyNew, $importImages);

		$this->importResultRepository->log("Products entered: inserted: $result[inserted], updated: $result[updated], locked: $result[locked]");
		
		$this->priceRepository->many()
			->join(['pricelist' => 'eshop_pricelist'], 'pricelist.uuid=this.fk_pricelist')
			->where('pricelist.fk_supplier', $supplier)
			->delete();

		$availablePriceCount = null;
		$unavailablePriceCount = null;

		if ($supplier->splitPricelists) {
			$pricelist = $this->syncPricelist($supplier, $currency, $country, '2', 3, true);
			$availablePriceCount = $this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)
				->where('amount IS NULL OR amount > 0'), $supplier, $pricelist);

			$pricelist = $this->syncPricelist($supplier, $currency, $country, '1', 4, true, 'Nedostupné');
			$unavailablePriceCount = $this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)
				->where('amount = 0'), $supplier, $pricelist);
		} else {
			$pricelist = $this->syncPricelist($supplier, $currency, $country, '0', 3, true);
			$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier), $supplier, $pricelist);
		}

		if (!$this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('purchasePrice IS NOT NULL')->isEmpty()) {
			$pricelist = $this->syncPricelist($supplier, $currency, $country, '3', 3, false, 'Nákupní');
			$this->supplierProductRepository->syncPrices($this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('purchasePrice IS NOT NULL'), $supplier, $pricelist);
		}

		$this->importResultRepository->log("Pricelist entered: available: $availablePriceCount, unavailable: $unavailablePriceCount");

		$total = 0;

		$store = $this->syncStore($supplier, $mutation);

		if (!$this->supplierProductRepository->many()->where('fk_supplier', $supplier)->where('amount IS NOT NULL')->isEmpty()) {
			$total = $this->supplierProductRepository->syncAmounts($this->supplierProductRepository->many()
				->where('fk_supplier', $supplier)
				->where('amount IS NOT NULL')
				->where('fk_product IS NOT NULL'), $store);
		}

		$this->importResultRepository->log("Store entered: total: $total");

		$this->supplierCategoryRepository->syncAttributeCategoryAssigns($supplier);

		$this->importResultRepository->markAsEntered($result['inserted'], $result['updated'], $result['locked'], $result['images']);
	}

	public function syncPricelist(Supplier $supplier, string $currency, string $country, string $id, int $priority, bool $active, ?string $label = null): Pricelist
	{
		/** @var \Eshop\DB\PricelistRepository $pricelistRepository */
		$pricelistRepository = $this->getConnection()->findRepository(Pricelist::class);

		return $pricelistRepository->syncOne([
			'uuid' => DIConnection::generateUuid($supplier->getPK(), $id),
			'code' => "$supplier->code-$id",
			'name' => $supplier->name . ($label === null ? '' : " ($label)"),
			'isActive' => $active,
			'currency' => $currency,
			'country' => $country,
			'supplier' => $supplier,
			'priority' => $priority,
		], ['currency', 'country']);
	}

	public function syncStore(Supplier $supplier, string $mutation, string $id = '1', ?string $label = null): Store
	{
		/** @var \Eshop\DB\StoreRepository $storeRepository */
		$storeRepository = $this->getConnection()->findRepository(Store::class);

		return $storeRepository->syncOne([
			'uuid' => DIConnection::generateUuid($supplier->getPK(), $id),
			'code' => "$supplier->code-$id",
			'name' => [$mutation => $supplier->name . ($label === null ? '' : " ($label)")],
			'supplier' => $supplier,
		], []);
	}
}
