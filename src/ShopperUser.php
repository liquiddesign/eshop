<?php

namespace Eshop;

use Eshop\DB\Customer;
use Eshop\DB\Merchant;
use Nette\Security\User;

class ShopperUser
{
	protected ?Customer $customer = null;

	protected readonly CartManager $cartManager;

	public function __construct(private readonly User $user, CartManagerFactory $cartManagerFactory,)
	{
		$this->cartManager = $cartManagerFactory->create($this);
	}

	public function getCustomer(): ?Customer
	{
		if ($this->customer) {
			return $this->customer;
		}

		$identity = $this->user->getIdentity();

		if ($this->user->isLoggedIn()) {
			if ($identity instanceof Customer) {
				return $identity;
			}

			if ($identity instanceof Merchant) {
				if ($identity->activeCustomerAccount) {
					$identity->activeCustomer->setAccount($identity->activeCustomerAccount);
				}

				return $identity->activeCustomer;
			}
		}

		return null;
	}
}
