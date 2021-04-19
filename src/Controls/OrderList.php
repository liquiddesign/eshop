<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\Shopper;
use Eshop\DB\OrderRepository;
use Grid\Datalist;
use StORM\Collection;
use StORM\Expression;
use StORM\ICollection;

/**
 * Class Products
 * @package Eshop\Controls
 */
class OrderList extends Datalist
{
	public function __construct(OrderRepository $orderRepository, Shopper $shopper, ?Collection $orders = null)
	{
		parent::__construct($orders ?? $orderRepository->getFinishedOrders($shopper->getCustomer(), $shopper->getMerchant()));

		$this->setDefaultOnPage(10);
		$this->setDefaultOrder('this.createdTs', 'DESC');

		$this->addFilterExpression('search', function (ICollection $collection, $value) use ($orderRepository, $shopper): void {
			$suffix = $orderRepository->getConnection()->getMutationSuffix();

			$or = "this.code = :code OR items.productName$suffix LIKE :string";

			if ($shopper->getMerchant()) {
				$or .= ' OR purchase.accountFullname LIKE :string OR account.fullname LIKE :string';
				$or .= ' OR purchase.fullname LIKE :string OR customer.fullname LIKE :string OR customer.company LIKE :string';
			}

			$collection->where($or, ['code' => $value, 'string' => '%' . $value . '%'])
				->join(['purchase' => 'eshop_purchase'], 'purchase.uuid=this.fk_purchase')
				->join(['carts' => 'eshop_cart'], 'purchase.uuid=carts.fk_purchase')
				->join(['items' => 'eshop_cartitem'], 'carts.uuid=items.fk_cart')
				->join(['customer' => 'eshop_customer'], 'customer.uuid=purchase.fk_customer')
				->join(['account' => 'security_account'], 'account.uuid=purchase.fk_account');
		}, '');

		$this->getFilterForm()->addText('search');
		$this->getFilterForm()->addSubmit('submit');
	}

	public function render(): void
	{
		$this->template->paginator = $this->getPaginator();
		$this->template->render($this->template->getFile() ?: __DIR__ . '/orderList.latte');
	}
}