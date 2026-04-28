<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Repository;

/**
 * @extends \StORM\Repository<\Eshop\DB\DeliveryRegion>
 */
class DeliveryRegionRepository extends Repository
{
	/**
	 * Najde kraj podle PSČ (přes mapovací tabulku DeliveryRegionZip).
	 * Vrací null, pokud PSČ není v mapě.
	 */
	public function findByZipcode(string|null $zipcode): DeliveryRegion|null
	{
		if ($zipcode === null) {
			return null;
		}

		$normalized = \str_replace(' ', '', $zipcode);

		if ($normalized === '' || !\ctype_digit($normalized)) {
			return null;
		}

		$mapping = $this->getConnection()->findRepository(DeliveryRegionZip::class)
			->many()
			->where('this.zipcode', $normalized)
			->first();

		return $mapping?->region;
	}

	/**
	 * @return array<string, string>
	 */
	public function getArrayForSelect(): array
	{
		return $this->many()->orderBy(['priority' => 'ASC', 'name' => 'ASC'])->toArrayOf('name');
	}
}
