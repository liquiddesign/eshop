<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\Cart>
 */
class CartRepository extends \StORM\Repository
{
	public function deleteCart(Cart $cart): void
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
	 * @return \Eshop\DB\Cart[]
	 */
	public function getLostCarts(bool $mark = false): array
	{
		$carts = $this->many()
			->where('(this.fk_purchase IS NOT NULL AND this.fk_customer IS NULL AND TIMESTAMPDIFF(DAY,this.createdTs,NOW()) > ' . Cart::EXPIRATION_DAYS . ') OR 
							  (this.fk_customer IS NOT NULL AND TIMESTAMPDIFF(DAY,this.createdTs,NOW()) > ' . Cart::EXPIRATION_DAYS . ')')
			->where('this.lostMark', false)
			->toArray();

		//array_chunk
		if ($mark) {
			$this->many()->where('uuid', \array_keys($carts))->update(['lostMark' => true]);
		}

		return $carts;
	}
}
