<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\GiftRuleProduct>
 */
class GiftRuleProductRepository extends \StORM\Repository
{
	/**
	 * Získá produkty pro dané pravidlo
	 * @return \StORM\Collection<\Eshop\DB\GiftRuleProduct>
	 */
	public function getProductsForRule(GiftRule $rule): Collection
	{
		return $this->many()
			->where('this.fk_giftRule', $rule->getPK())
			->orderBy(['this.priority' => 'ASC']);
	}

	/**
	 * Získá všechny produkty pro pravidlo s načtenými relacemi (pouze neskryté produkty)
	 * @return \StORM\Collection<\Eshop\DB\GiftRuleProduct>
	 */
	public function getProductsForRuleWithProducts(GiftRule $rule): Collection
	{
		return $this->many()
			->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid')
			->join(['eshop_displayamount'], 'eshop_displayamount.uuid = product.fk_displayAmount')
			->where('this.fk_giftRule', $rule->getPK())
			->where('product.hidden', false)
			->where('product.fk_displayAmount IS NULL OR eshop_displayamount.isSold = 0')
			->orderBy(['this.priority' => 'ASC']);
	}
}
