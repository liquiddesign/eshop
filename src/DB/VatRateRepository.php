<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\VatRate>
 */
class VatRateRepository extends \StORM\Repository
{
	public function getDefaultVatRates(): array
	{
		return $this->many()->where('fk_country','CZ')->toArrayOf('name');
	}

	public function getVatRatesByCountry(?Country $country = null): array
	{
		return $country ? $this->many()->where('fk_country',$country->getPK())->toArrayOf('name') : $this->getDefaultVatRates();
	}
}