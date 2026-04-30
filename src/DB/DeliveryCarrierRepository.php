<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Repository;

/**
 * @extends \StORM\Repository<\Eshop\DB\DeliveryCarrier>
 */
class DeliveryCarrierRepository extends Repository
{
	/**
	 * @return array<string, string>
	 */
	public function getArrayForSelect(bool $includeHidden = false): array
	{
		$collection = $this->many()->orderBy(['priority' => 'ASC', 'code' => 'ASC']);

		if (!$includeHidden) {
			$collection->where('this.hidden', false);
		}

		return $collection->toArrayOf('name');
	}

	/**
	 * Najde aktivního dopravce dle PSČ doručení a shopu — přes mapování PSČ → kraj a pivot dopravce ↔ kraj.
	 * Vrací dopravce s nejvyšší prioritou (nejnižší číslo). Vrací null pokud:
	 *  - PSČ není v mapě DeliveryRegionZip,
	 *  - žádný neskrytý dopravce nepokrývá daný kraj v daném shopu.
	 */
	public function findByZipcode(string|null $zipcode, string $shopPK): DeliveryCarrier|null
	{
		$region = $this->getConnection()->findRepository(DeliveryRegion::class)
			->many()
			->join(['regionZip' => 'eshop_deliveryregionzip'], 'regionZip.fk_region = this.uuid')
			->where('regionZip.zipcode', $zipcode !== null ? \str_replace(' ', '', $zipcode) : null)
			->first();

		if ($region === null) {
			return null;
		}

		return $this->many()
			->join(['carrierRegion' => 'eshop_deliverycarrier_nxn_eshop_deliveryregion'], 'carrierRegion.fk_deliverycarrier = this.uuid')
			->where('carrierRegion.fk_deliveryregion', $region->getPK())
			->where('this.fk_shop', $shopPK)
			->where('this.hidden', false)
			->orderBy(['this.priority' => 'ASC', 'this.code' => 'ASC'])
			->first();
	}
}
