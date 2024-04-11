<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\ICollection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Price>
 */
class PriceRepository extends \StORM\Repository
{
	public function getPricesByPriceList(Pricelist $priceList): ICollection
	{
		return $this->many()
			->select(['rate' => 'rates.rate'])
			->join(['products' => 'eshop_product'], 'products.uuid=this.fk_product')
			->join(['pricelists' => 'eshop_pricelist'], 'pricelists.uuid=this.fk_pricelist')
			->join(['rates' => 'eshop_vatrate'], 'rates.uuid = products.vatRate AND rates.fk_country=pricelists.fk_country')
			->where('fk_pricelist', $priceList->getPK());
	}
	
	public function getPricesCountByPriceList(Pricelist $priceList): int
	{
		return $this->many()->where('fk_pricelist', $priceList->getPK())->count();
	}

	public function filterRibbon($value, ICollection $collection): void
	{
		$collection->join(['ribbons' => 'eshop_product_nxn_eshop_ribbon'], 'ribbons.fk_product=this.fk_product');

		$value === false ? $collection->where('ribbons.fk_ribbon IS NULL') : $collection->where('ribbons.fk_ribbon', $value);
	}

	public function filterInternalRibbon($value, ICollection $collection): void
	{
		$collection->join(['internalRibbons' => 'eshop_product_nxn_eshop_internalribbon'], 'internalRibbons.fk_product=this.fk_product');

		$value === false ? $collection->where('internalRibbons.fk_internalribbon IS NULL') : $collection->where('internalRibbons.fk_internalribbon', $value);
	}
}
