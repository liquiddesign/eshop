<?php

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\CartRepository;
use StORM\Collection;
use StORM\ICollection;

class CartList extends \Grid\Datalist
{
	private CheckoutManager $checkoutManager;

	private CartRepository $cartRepository;

	public function __construct(Collection $carts, CheckoutManager $checkoutManager, CartRepository $cartRepository)
	{
		parent::__construct($carts);

		$this->setDefaultOnPage(20);

		$this->addFilterExpression('customer', function (ICollection $collection, $value): void {
			$collection->join(['customerTable' => 'eshop_customer'], 'this.fk_customer = customerTable.uuid');
			$collection->where('customerTable.fullname LIKE :query OR customerTable.email LIKE :query', ['query' => '%' . $value . '%']);
		}, '');

		$this->getFilterForm()->addText('customer');
		$this->getFilterForm()->addSubmit('submit');

		$this->checkoutManager = $checkoutManager;
		$this->cartRepository = $cartRepository;
	}

	public function render(): void
	{
		$this->template->paginator = $this->getPaginator();
		$this->template->render($this->template->getFile() ?: __DIR__ . '/cartList.latte');
	}

	public function handleReset(): void
	{
		$this->setFilters(null);
		$this->setOrder($this->getDefaultOrder()[0], $this->getDefaultOrder()[1]);
		$this->getPresenter()->redirect('this');
	}

	public function handleApprove($cart): void
	{
		$cart = $this->cartRepository->one($cart, true);
		$cart->update(['approved' => 'yes']);
		$this->redirect('this');
	}

	public function handleDeny($cart): void
	{
		$cart = $this->cartRepository->one($cart, true);
		$cart->update(['approved' => 'no']);
		$this->redirect('this');
	}

	public function handleInsertIntoMyCart($cart): void
	{
		$cart = $this->cartRepository->one($cart, true);
		$this->checkoutManager->addItemsFromCart($cart);
		$this->redirect('this');
	}
}
