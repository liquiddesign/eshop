<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\Shopper;
use Eshop\DB\OrderRepository;
use Grid\Datalist;
use StORM\ICollection;

/**
 * Class Products
 * @package Eshop\Controls
 */
class OrderList extends Datalist
{
	public function __construct(OrderRepository $orderRepository, Shopper $shopper)
	{
		parent::__construct($orderRepository->getFinishedOrdersByCustomer($shopper->getCustomer()->getPK()));
		
		$this->setDefaultOnPage(10);
		$this->setDefaultOrder('this.createdTs', 'DESC');
		
		$this->addFilterExpression('search', function (ICollection $collection, $value) use ($orderRepository): void {
			$suffix = $orderRepository->getConnection()->getMutationSuffix();
			$collection->where("this.code = :code OR items.productName$suffix LIKE :productName", ['code' => $value, 'productName' => '%'.$value.'%'])
				->join(['purchase' => 'user_purchase'], 'purchase.uuid=this.fk_purchase')
				->join(['carts' => 'user_cart'], 'purchase.uuid=carts.fk_purchase')
				->join(['items' => 'user_cartitem'], 'carts.uuid=items.fk_cart');
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