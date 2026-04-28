<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Repository;

/**
 * @extends \StORM\Repository<\Eshop\DB\DeliveryRegionZip>
 */
class DeliveryRegionZipRepository extends Repository
{
	/**
	 * Vrátí seznam unikátních okresů (sloupec `district`) seřazený abecedně.
	 * @return array<string, string>
	 */
	public function getDistrictsForSelect(): array
	{
		$rows = $this->many()
			->where('this.district IS NOT NULL')
			->where('this.district != \'\'')
			->setGroupBy(['this.district'])
			->orderBy(['this.district' => 'ASC'])
			->fetchColumns('this.district', true);

		$result = [];

		foreach ($rows as $district) {
			$value = (string) $district;
			$result[$value] = $value;
		}

		return $result;
	}
}
