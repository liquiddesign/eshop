<?php

namespace Eshop\Controls;

use Eshop\Common\CartListEditMode;
use Eshop\DB\CartItemRepository;
use Eshop\DB\CartRepository;
use Eshop\ShopperUser;
use Nette\Utils\Arrays;
use StORM\Collection;
use StORM\ICollection;

class CartList extends \Grid\Datalist
{
	/**
	 * @var array<callable(\Eshop\DB\Cart $cart): void>
	 */
	public array $onAfterApprove = [];

	/**
	 * @var array<callable(\Eshop\DB\Cart $cart): void>
	 */
	public array $onAfterDeny = [];

	public function __construct(
		Collection $carts,
		protected readonly CartRepository $cartRepository,
		protected readonly ShopperUser $shopperUser,
		protected readonly CartItemRepository $cartItemRepository,
		protected readonly CartListEditMode $editMode = CartListEditMode::NONE
	) {
		parent::__construct($carts);

		$this->setDefaultOnPage(20);

		$this->addFilterExpression('customer', function (ICollection $collection, $value): void {
			$collection->join(['customerTable' => 'eshop_customer'], 'this.fk_customer = customerTable.uuid');
			$collection->where('customerTable.fullname LIKE :query OR customerTable.email LIKE :query', ['query' => '%' . $value . '%']);
		}, '');

		/** @var \Forms\Form $form */
		$form = $this->getFilterForm();

		$form->addText('customer');
		$form->addSubmit('submit');
	}

	public function render(): void
	{
		$this->template->editMode = $this->editMode;
		$this->template->paginator = $this->getPaginator();

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/cartList.latte');
	}

	public function handleChangeAmount($cartItem, int $amount): void
	{
		if ($this->editMode === CartListEditMode::NONE) {
			return;
		}

		/** @var \Eshop\DB\CartItem $cartItem */
		$cartItem = $this->cartItemRepository->one($cartItem, true);

		if ($amount <= 0) {
			$amount = 1;
		}

		$cartItem->update(['amount' => $amount]);

		foreach ($cartItem->getPackageItems() as $packageItem) {
			$packageItem->update(['amount' => $amount]);
		}
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

		Arrays::invoke($this->onAfterApprove, $cart);

		$this->redirect('this');
	}

	public function handleDeny($cart): void
	{
		$cart = $this->cartRepository->one($cart, true);
		$cart->update(['approved' => 'no']);

		Arrays::invoke($this->onAfterDeny, $cart);

		$this->redirect('this');
	}

	public function handleInsertIntoMyCart($cart): void
	{
		$cart = $this->cartRepository->one($cart, true);
		$this->shopperUser->getCheckoutManager()->addItemsFromCart($cart);
		$this->redirect('this');
	}
}
