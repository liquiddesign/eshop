<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\PickupPoint>
 */
class PickupPointRepository extends \StORM\Repository implements IGeneralRepository
{
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('hidden', false);
		}

		return $collection->orderBy(['priority', "name$suffix"]);
	}


	public function filterName(string $q, Collection $collection): void
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection->where("name$suffix LIKE :q", "%$q%");
	}

	public function filterCity(string $q, Collection $collection): void
	{
		$collection->where("address.city LIKE :q", "%$q%");
	}

	public function getCitiesArrayForSelect(): array
	{
		/** @var \Eshop\DB\AddressRepository $addressRepo */
		$addressRepo = $this->getConnection()->findRepository(Address::class);

		return $addressRepo->many()
			->join(['point' => 'eshop_pickuppoint'], 'this.uuid = point.fk_address', [], 'INNER')
			->setGroupBy(['city'])
			->toArrayOf('city');
	}

	public function getOpeningHoursByPickupPoints(array $pickupPoints): array
	{
		/** @var \Eshop\DB\OpeningHoursRepository $openingHoursRepo */
		$openingHoursRepo = $this->getConnection()->findRepository(OpeningHours::class);

		$openingHours = [];

		foreach ($pickupPoints as $key => $point) {
			$openingHours[$key]['normal'] = $openingHoursRepo->many()
				->setIndex('day')
				->where('fk_pickupPoint', $key)
				->where('date IS NULL')
				->toArray();

			$openingHours[$key]['special'] = $openingHoursRepo->many()
				->setIndex('date')
				->where('fk_pickupPoint', $key)
				->where('date >= CURDATE()')
				->orderBy(['date'])
				->setTake(5)
				->toArray();
		}

		return $openingHours;
	}
}
