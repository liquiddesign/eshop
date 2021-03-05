<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\Cart>
 */
class CartRepository extends \StORM\Repository
{
	public function deleteCart(Cart $cart)
	{
		$this->many()->where('this.uuid', $cart)->delete();
	}
	
	public function getUnattachedCart(string $cartToken): ?Cart
	{
		return $this->many()
			->where('this.uuid', $cartToken)
			->where('this.fk_customer IS NULL')
			->first();
	}
}
