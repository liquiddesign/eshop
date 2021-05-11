<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\DIConnection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Supplier>
 */
class SupplierRepository extends \StORM\Repository implements IGeneralRepository
{
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->many()->orderBy(["name"])->toArrayOf('name');
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		$collection = $this->many();
		
		if (!$includeHidden) {
			$collection->where('hidden', false);
		}
		
		return $collection->orderBy(['priority', "name"]);
	}
	
	public function syncPricelist(Supplier $supplier, string $currency, string $country, string $id, int $priority, bool $active, ?string $label = null): Pricelist
	{
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
}
