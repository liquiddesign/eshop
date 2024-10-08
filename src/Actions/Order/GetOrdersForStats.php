<?php

namespace Eshop\Actions\Order;

use Base\BaseAction;
use Base\ShopsConfig;
use Eshop\DB\Category;
use Eshop\DB\Currency;
use Eshop\DB\Customer;
use Eshop\DB\Merchant;
use Eshop\DB\OrderRepository;

class GetOrdersForStats extends BaseAction
{
	public function __construct(
		private readonly OrderRepository $orderRepository,
		private readonly ShopsConfig $shopsConfig
	) {
	}

	/**
	 * @param \Eshop\DB\Currency $currency
	 * @param \DateTime|null $statsTo
	 * @param \DateTime|null $statsFrom
	 * @param string $customerType
	 * @param \Eshop\DB\Customer|null $customer
	 * @param \Eshop\DB\Merchant|null $merchant
	 * @param \Eshop\DB\Category|null $category
	 * @return array<\Eshop\DB\Order>
	 */
	public function execute(
		Currency $currency,
		\DateTime|null $statsTo = null,
		\DateTime|null $statsFrom = null,
		string $customerType = 'all',
		Customer|null $customer = null,
		Merchant|null $merchant = null,
		Category|null $category = null,
	): array {
		$statsFrom?->setTime(0, 0);
		$statsTo?->setTime(23, 59, 59);
		$fromString = $statsFrom?->format('Y-m-d\TH:i:s');
		$toString = $statsTo?->format('Y-m-d\TH:i:s');

		$orders = $this->orderRepository->many()
			->where('this.receivedTs IS NOT NULL AND this.completedTs IS NOT NULL AND this.canceledTs IS NULL')
			->select(['date' => "DATE_FORMAT(this.createdTs, '%Y-%m')"])
			->where('this.createdTs >= :from AND this.createdTs <= :to', ['from' => $fromString, 'to' => $toString])
			->join(['purchase' => 'eshop_purchase'], 'purchase.uuid = this.fk_purchase')
			->where('this.fk_shop = :s OR this.fk_shop IS NULL', ['s' => $this->shopsConfig->getSelectedShop()])
			->where('purchase.fk_currency', $currency->getPK());

		if ($customerType !== 'all' && !$customer) {
			$subSelect = $this->orderRepository->many()
				->join(['purchase' => 'eshop_purchase'], 'purchase.uuid = this.fk_purchase')
				->setGroupBy(['purchase.fk_customer'], 'customerCount ' . ($customerType === 'new' ? '= 1' : '> 1'))
				->select(['customerCount' => 'COUNT(purchase.fk_customer)'])
				->select(['customerUuid' => 'purchase.fk_customer'])
				->where('this.receivedTs IS NOT NULL AND this.completedTs IS NOT NULL AND this.canceledTs IS NULL')
				->select(['date' => "DATE_FORMAT(this.createdTs, '%Y-%m')"])
				->where('this.createdTs >= :from AND this.createdTs <= :to', ['from' => $fromString, 'to' => $toString])
				->where('this.fk_shop = :s OR this.fk_shop IS NULL', ['s' => $this->shopsConfig->getSelectedShop()])
				->where('purchase.fk_currency', $currency->getPK());

			$orders->where('purchase.fk_customer', \array_values($subSelect->toArrayOf('customerUuid')));
		}

		if ($customer) {
			$orders->where('purchase.fk_customer', $customer->getPK());
		}

		if ($merchant) {
			$orders->join(['customerXmerchant' => 'eshop_merchant_nxn_eshop_customer'], 'customerXmerchant.fk_customer = purchase.fk_customer')
				->where('customerXmerchant.fk_merchant', $merchant->getPK());
		}

		$orders->join(['cart' => 'eshop_cart'], 'purchase.uuid = cart.fk_purchase')
			->join(['cartCurrency' => 'eshop_currency'], 'cartCurrency.uuid = cart.fk_currency')
			->select([
				'purchaseCart' => 'cart.uuid',
				'cartCurrency' => 'cartCurrency.uuid',
			]);

		if ($category) {
			$orders->join(['cartItem' => 'eshop_cartitem'], 'cart.uuid = cartItem.fk_cart', [], 'INNER')
				->join(['product' => 'eshop_product'], 'cartItem.fk_product = product.uuid', [], 'INNER')
				->join(['productXcategory' => 'eshop_product_nxn_eshop_category'], 'product.uuid = productXcategory.fk_product', [], 'INNER')
				->where('productXcategory.fk_category', $category->getPK());
		}

		return $orders->toArray();
	}
}
