<?php

namespace Eshop;

use Eshop\DB\Customer;
use Eshop\DB\CustomerGroup;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\Merchant;
use Eshop\DB\Product;
use Nette\Security\Authenticator;
use Nette\Security\Authorizator;
use Nette\Security\IAuthenticator;
use Nette\Security\IUserStorage;
use Nette\Security\User;
use Nette\Security\UserStorage;

class ShopperUser extends User
{
	protected const MERCHANT_CATALOG_PERMISSIONS = 'price';

	protected ?Customer $customer = null;

	protected readonly CartManager $cartManager;

	public function __construct(
		CartManagerFactory $cartManagerFactory,
		private readonly CustomerGroupRepository $customerGroupRepository,
		?IUserStorage $legacyStorage = null,
		?IAuthenticator $authenticator = null,
		?Authorizator $authorizator = null,
		?UserStorage $storage = null
	) {
		parent::__construct($legacyStorage, $authenticator, $authorizator, $storage);

		$this->cartManager = $cartManagerFactory->create($this);
	}

	public function getCustomer(): ?Customer
	{
		if ($this->customer) {
			return $this->customer;
		}

		$identity = $this->getIdentity();

		if ($this->isLoggedIn()) {
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

	public function canBuyProductAmount(Product $product, $amount): bool
	{
		return !($amount < $product->minBuyCount || ($product->maxBuyCount !== null && $amount > $product->maxBuyCount));
	}

	public function getMerchant(): ?Merchant
	{
		return $this->isLoggedIn() && $this->getIdentity() instanceof Merchant ? $this->getIdentity() : null;
	}

	public function canBuyProduct(Product $product): bool
	{
		return !$product->unavailable && $product->getValue('price') !== null && $this->getBuyPermission();
	}

	public function getBuyPermission(): bool
	{
		$customer = $this->getCustomer();
		$merchant = $this->getMerchant();

		if ($merchant && (!$customer && !$merchant->activeCustomerAccount)) {
			return false;
		}

		if (!$customer || !$catalogPermission = $customer->getCatalogPermission()) {
			return $this->getCustomerGroup() ? $this->getCustomerGroup()->defaultBuyAllowed : false;
		}

		return $catalogPermission->buyAllowed;
	}

	public function getCustomerGroup(): ?CustomerGroup
	{
		$customer = $this->getCustomer();

		return $this->customerGroup ??= $customer ? $customer->group : $this->customerGroupRepository->getUnregisteredGroup();
	}

	public function getCatalogPermission(): string
	{
		$customer = $this->getCustomer();
		$merchant = $this->getMerchant();

		if ($merchant && (!$customer && !$merchant->activeCustomerAccount)) {
			return self::MERCHANT_CATALOG_PERMISSIONS;
		}

		if (!$customer) {
			return $this->getCustomerGroup()->defaultCatalogPermission;
		}

		if (!$catalogPermission = $customer->getCatalogPermission()) {
			return 'none';
		}

		return $catalogPermission->catalogPermission;
	}
}
