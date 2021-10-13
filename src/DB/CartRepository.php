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

	/**
	 * Return lost carts if they have user info.
	 * @param bool $mark mark carts as lost, will not return this carts next time
	 * @return array
	 */
	public function getLostCarts(bool $mark = false): array
	{
		$carts = $this->many()
			->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid')
			->where('(this.fk_purchase IS NOT NULL AND this.fk_customer IS NULL AND this.expirationTs IS NOT NULL AND this.expirationTs < NOW()) OR 
							  (this.fk_customer IS NOT NULL AND TIMESTAMPDIFF(DAY,this.createdTs,NOW()) > 30)')
			->where('this.lostMark', false)
			->toArray();

		if ($mark) {
			$this->many()->where('uuid', \array_keys($carts))->update(['lostMark' => true]);
		}

		return $carts;
	}
}
