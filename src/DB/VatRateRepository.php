<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\VatRate>
 */
class VatRateRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @return float[]
	 */
	public function getDefaultVatRates(): array
	{
		return $this->many()->where('fk_country', 'CZ')->orderBy(['priority'])->toArrayOf('rate');
	}

	/**
	 * @param \Eshop\DB\Country|null $country
	 * @return float[]
	 */
	public function getVatRatesByCountry(?Country $country = null): array
	{
		return $country ? $this->many()->where('fk_country', $country->getPK())->orderBy(['priority'])->toArrayOf('rate') : $this->getDefaultVatRates();
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		$collection = $this->many();

		return $collection->orderBy(['priority', 'name']);
	}
}
