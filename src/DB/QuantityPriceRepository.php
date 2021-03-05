<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\ICollection;
use StORM\Repository;

/**
 * @extends \StORM\Repository<\Eshop\DB\QuantityPrice>
 */
class QuantityPriceRepository extends Repository
{
	public function getPricesByPriceList(Pricelist $priceList): ICollection
	{
		return $this->many()
			->select(['rate' => 'rates.rate'])
			->join(['products' => 'eshop_product'], 'products.uuid=this.fk_product')
			->join(['pricelists' => 'eshop_pricelist'], 'pricelists.uuid=this.fk_pricelist')
			->join(['rates' => 'eshop_vatRate'], 'rates.uuid = products.vatRate AND rates.fk_country=pricelists.fk_country')
			->where('fk_pricelist', $priceList->getPK());
	}
	
	public function getPricesCountByPriceList(Pricelist $priceList): int
	{
		return $this->many()->where('fk_pricelist', $priceList->getPK())->count();
	}
}
