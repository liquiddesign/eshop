<?php

namespace Eshop\Actions\Customer;

use Base\BaseAction;
use Eshop\DB\CartItemRepository;
use Eshop\DB\Customer;

class GetLastPurchasedProducts extends BaseAction
{
	public function __construct(private readonly CartItemRepository $cartItemRepository)
	{
	}

	/**
	 * @param \Eshop\DB\Customer $customer
	 * @param int $take
	 * @return array<string|int>
	 */
	public function execute(Customer $customer, int $take = 20): array
	{
		return $this->getLocalCachedOutput($customer->getPK(), function () use ($customer, $take) {
			return $this->cartItemRepository->many()
				->join(['cart' => 'eshop_cart'], 'this.fk_cart = cart.uuid')
				->join(['purchase' => 'eshop_purchase'], 'cart.fk_purchase = purchase.uuid')
				->join(['orderTable' => 'eshop_order'], 'purchase.uuid = orderTable.fk_purchase')
				->where('purchase.fk_customer', $customer->getPK())
				->where('orderTable.completedTs IS NOT NULL AND orderTable.canceledTs IS NULL')
				->where('this.fk_product IS NOT NULL')
				->setSelect(['productPK' => 'this.fk_product'])
				->orderBy(['orderTable.createdTs' => 'DESC'])
				->setTake($take)
				->toArrayOf('productPK', toArrayValues: true);
		});
	}
}
