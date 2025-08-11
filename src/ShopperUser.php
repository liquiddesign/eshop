<?php

namespace Eshop;

use Admin\DB\RoleRepository;
use Base\ShopsConfig;
use Eshop\Actions\Customer\GetCurrentContextCatalogPermissionByCustomer;
use Eshop\Admin\SettingsPresenter;
use Eshop\DB\CartItem;
use Eshop\DB\CategoryType;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\Country;
use Eshop\DB\CountryRepository;
use Eshop\DB\Currency;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroup;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DiscountCoupon;
use Eshop\DB\Merchant;
use Eshop\DB\MerchantRepository;
use Eshop\DB\MinimalOrderValueRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\Product;
use Eshop\DB\VisibilityListRepository;
use Eshop\DTO\CurrentContextCatalogPermissions;
use Eshop\DTO\ProductWithFormattedPrices;
use Nette\DI\Container;
use Nette\Http\Session;
use Nette\Localization\Translator;
use Nette\Security\Authenticator;
use Nette\Security\Authorizator;
use Nette\Security\User;
use Nette\Security\UserStorage;
use Security\DB\Account;
use Security\DB\AccountRepository;
use StORM\Collection;
use StORM\Exception\NotExistsException;
use StORM\Expression;
use Web\DB\SettingRepository;

class ShopperUser extends User
{
	public const PERMISSIONS = [
		'none' => 'Nezobrazeno',
		'catalog' => 'Bez cen',
		'price' => 'S cenami',
	];

	public const PRICE_PRECISSION = 4;

	public const SESSION_SECTION_NAME = 'shopperUser';

	public const SESSION_ACTIVE_CUSTOMER_NAME = 'activeCustomer';

	protected const MERCHANT_CATALOG_PERMISSIONS = 'price';

	protected Customer|null|false $customer = false;

	protected Merchant|null|false $merchant = false;

	protected Customer|null|false $selectedCustomer = false;

	protected CustomerGroup|null|false $customerGroup = false;

	protected CheckoutManager $checkoutManager;

	protected Country $country;

	protected Currency $currency;

	/**
	 * @var array<\Eshop\DB\Customer>|false
	 */
	protected array|false $childrenCustomers = false;

	/**
	 * @var array<bool>
	 */
	protected array $registrationConfiguration = [
		'enabled' => true,
		'confirmation' => true,
		'emailAuthorization' => true,
	];

	/**
	 * @var array<\Eshop\DB\Currency>
	 */
	protected array $altCurrencies = [];

	/**
	 * @var array<float>
	 */
	protected array $vatRates;

	/**
	 * @var array<string>|false
	 */
	protected array|false $allPriceLists = false;

	/**
	 * @var array<string>|false
	 */
	protected array|null|false $favouritePriceLists = false;

	/**
	 * @var array<mixed>
	 */
	private array $config = [];

	private CategoryType|null|false $mainCategoryType = false;

	/**
	 * @var array<string, array<int|string, \Eshop\DB\Pricelist>>
	 */
	private array $priceLists = [];

	/**
	 * @var array<int|string, \Eshop\DB\VisibilityList>|false
	 */
	private array|false $visibilityLists = false;

	/**
	 * @var 'withVat'|'withoutVat'|false
	 */
	private string|false $mainPriceType = false;

	public function __construct(
		protected readonly PricelistRepository $pricelistRepository,
		protected readonly CurrencyRepository $currencyRepository,
		protected readonly CountryRepository $countryRepository,
		protected readonly CustomerRepository $customerRepository,
		protected readonly MerchantRepository $merchantRepository,
		protected readonly CustomerGroupRepository $customerGroupRepository,
		protected readonly MinimalOrderValueRepository $minimalOrderValueRepository,
		protected readonly AccountRepository $accountRepository,
		protected readonly RoleRepository $roleRepository,
		protected readonly SettingRepository $settingRepository,
		protected readonly CategoryTypeRepository $categoryTypeRepository,
		protected readonly Container $container,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly Translator $translator,
		protected readonly Session $session,
		protected readonly VisibilityListRepository $visibilityListRepository,
		protected readonly GetCurrentContextCatalogPermissionByCustomer $getCurrentContextCatalogPermissions,
		?Authenticator $authenticator,
		?Authorizator $authorizator,
		?UserStorage $storage,
	) {
		parent::__construct($storage, $authenticator, $authorizator);
	}

	public function getCheckoutManager(): CheckoutManager
	{
		if (isset($this->checkoutManager)) {
			return $this->checkoutManager;
		}

		/** @var \Eshop\CheckoutManager $checkoutManager */
		$checkoutManager = $this->container->createInstance(CheckoutManager::class);
		$this->checkoutManager = $checkoutManager;

		$checkoutManager->startup();

		return $checkoutManager;
	}

	public function setConfig(array $config): void
	{
		$this->config = $config;
	}

	public function getShowMaxCustomerOrderPrice(): bool
	{
		return $this->config['maxCustomerOrderPrice']->show;
	}

	public function getRestrictMaxCustomerOrderPrice(): bool
	{
		return $this->config['maxCustomerOrderPrice']->restrict;
	}

	public function getAllowBannedEmailOrder(): bool
	{
		return $this->config['allowBannedEmailOrder'];
	}

	/**
	 * @return array<mixed>
	 */
	public function getCategoriesImage(): array
	{
		return $this->config['categories']->image;
	}

	/**
	 * @return array<mixed>
	 */
	public function getCategoriesFallbackImage(): array
	{
		return $this->config['categories']->fallbackImage;
	}

	public function getInvoicesAutoTaxDateInDays(): int
	{
		return $this->config['invoices']->autoTaxDateInDays;
	}

	public function getInvoicesAutoDueDateInDays(): int
	{
		return $this->config['invoices']->autoDueDateInDays;
	}

	public function getReviewsType(): string
	{
		return $this->config['reviews']->type;
	}

	public function getReviewsMinScore(): float
	{
		return $this->config['reviews']->minScore;
	}

	public function getReviewsMaxScore(): float
	{
		return $this->config['reviews']->maxScore;
	}

	public function getReviewsMaxRemindersCount(): int
	{
		return $this->config['reviews']->maxRemindersCount;
	}

	public function getReviewsMiddleScore(): float
	{
		return ($this->getReviewsMinScore() + $this->getReviewsMaxScore()) / 2;
	}

	public function isIntegrationsEHub(): bool
	{
		return $this->config['integrations']->eHub;
	}

	/**
	 * @return array{categories: bool}
	 */
	public function getDiscountConditions(): array
	{
		return (array) $this->config['discountConditions'];
	}

	public function isAlwaysCreateCustomerOnOrderCreated(): bool
	{
		return $this->config['alwaysCreateCustomerOnOrderCreated'];
	}

	public function getProjectUrl(): string
	{
		return $this->config['projectUrl'];
	}

	public function getCountry(): Country
	{
		if (isset($this->country)) {
			return $this->country;
		}

		$country = $this->countryRepository->many()->where('code', $this->config['country']);

		$this->shopsConfig->filterShopsInShopEntityCollection($country);

		return $this->country = $country->first(true);
	}

	public function getShowZeroPrices(): bool
	{
		return $this->config['showZeroPrices'];
	}

	public function getShowWithoutVat(): bool
	{
		return $this->config['showWithoutVat'];
	}

	public function getPriorityPrice(): string
	{
		return $this->config['priorityPrice'];
	}

	public function getShowVat(): bool
	{
		return $this->config['showVat'];
	}

	public function getEditOrderAfterCreation(): bool
	{
		return $this->config['editOrderAfterCreation'];
	}

	public function getUseDiscountLevelCalculationInBeforePrice(): bool
	{
		return $this->config['useDiscountLevelCalculationInBeforePrice'];
	}

	public function getAutoFixCart(): bool
	{
		return $this->config['autoFixCart'];
	}

	/**
	 * @return array<int, string>
	 */
	public function getCheckoutSequence(): array
	{
		return $this->config['checkoutSequence'];
	}

	/**
	 * @return \Eshop\DB\Customer|null Always customer which is meant to be used for buying
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getCustomer(): ?Customer
	{
		if ($this->customer !== false) {
			return $this->customer;
		}

		$identity = $this->getIdentity();

		if ($this->isLoggedIn()) {
			if ($identity instanceof Customer) {
				$this->customer = $this->customerRepository->one($identity->getPK());

				if (!$this->customer) {
					$this->logout(true);

					return null;
				}

				$this->customer->setAccount($identity->getAccount());

				return $this->customer;
			}

			if ($merchant = $this->getMerchant()) {
				if ($merchant->activeCustomerAccount) {
					$merchant->activeCustomer->setAccount($merchant->activeCustomerAccount);
				}

				return $this->customer = $merchant->activeCustomer;
			}
		}

		return null;
	}

	/**
	 * @return array<\Eshop\DB\VisibilityList>
	 */
	public function getVisibilityLists(): array
	{
		if ($this->visibilityLists !== false) {
			return $this->visibilityLists;
		}

		$customer = $this->getCustomer();
		$merchant = $this->getMerchant();

		if (!$customer && $merchant) {
			$visibilityLists = $merchant->getVisibilityLists();

			$this->shopsConfig->filterShopsInShopEntityCollection($visibilityLists, $merchant->shop);
		} else {
			$visibilityLists = $customer ? $customer->getVisibilityLists() : $this->getCustomerGroup()->getDefaultVisibilityLists();

			$this->shopsConfig->filterShopsInShopEntityCollection($visibilityLists, $customer->shop ?? $this->getCustomerGroup()->shop ?? []);
		}

		$visibilityLists->select(['this.id'])->where('this.hidden', false)->orderBy(['this.priority' => 'ASC', 'this.uuid' => 'ASC']);

		return $this->visibilityLists = $visibilityLists->toArray();
	}

	public function canBuyProductAmount(Product $product, $amount): bool
	{
		return !($amount < $product->minBuyCount || ($product->maxBuyCount !== null && $amount > $product->maxBuyCount));
	}

	public function getMerchant(): Merchant|null
	{
		if ($this->merchant !== false) {
			return $this->merchant;
		}

		if ($this->isLoggedIn() && $this->getIdentity() instanceof Merchant) {
			$merchant = $this->merchantRepository->one($this->getIdentity()->getPK());

			if (!$merchant) {
				$this->logout(true);

				return null;
			}

			return $this->merchant = $merchant;
		}

		return null;
	}

	/**
	 * @return \Eshop\DB\Customer|null Selected customer from session.
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getSessionSelectedCustomer(): Customer|null
	{
		if ($this->selectedCustomer !== false) {
			return $this->selectedCustomer;
		}

		$customer = $this->session->getSection($this::SESSION_SECTION_NAME)->get($this::SESSION_ACTIVE_CUSTOMER_NAME);

		return $this->selectedCustomer = ($customer ? $this->customerRepository->one($customer) : null);
	}

	/**
	 * Change selected customer
	 * @param \Eshop\DB\Customer|string|null $customer
	 */
	public function setSelectedCustomer(Customer|string|null $customer): void
	{
		$this->selectedCustomer = false;

		if (!$customer) {
			$this->session->getSection($this::SESSION_SECTION_NAME)->remove($this::SESSION_ACTIVE_CUSTOMER_NAME);

			return;
		}

		$customer = $customer instanceof Customer ? $customer->getPK() : $customer;

		if ($this->getCustomer() && $customer === $this->getCustomer()->getPK()) {
			$this->session->getSection($this::SESSION_SECTION_NAME)->remove($this::SESSION_ACTIVE_CUSTOMER_NAME);

			return;
		}

		$this->session->getSection($this::SESSION_SECTION_NAME)->set($this::SESSION_ACTIVE_CUSTOMER_NAME, $customer);
	}

	/**
	 * @return \StORM\Collection<\Eshop\DB\Customer> Available customers aka children customers of currently logged in customer and customer itself
	 */
	public function getAvailableCustomers(): Collection
	{
		if (!$customer = $this->getCustomer()) {
			return $this->customerRepository->many()->where('0=1');
		}

		$where = new Expression();
		$where->add('OR', 'this.fk_parentCustomer  = %s', [$customer->getPK()]);

		return $this->customerRepository->many()->where($where->getSql(), $where->getVars());
	}

	public function canBuyProduct(Product $product): bool
	{
		return !$product->isUnavailable() && $product->getValue('price') !== null && $this->getBuyPermission() && $product->deletedTs === null;
	}

	public function getProductPricesFormatted(Product $product): ?ProductWithFormattedPrices
	{
		try {
			$product->getPrice();
		} catch (NotExistsException) {
			return null;
		}

		return new ProductWithFormattedPrices(
			$this->translator,
			$product,
			$this->showPricesWithVat(),
			$this->showPricesWithoutVat(),
			$this->showPriorityPrices(),
			$this->getCatalogPermission() === 'price',
			$this->filterPrice($product->getPrice()),
			$this->filterPrice($product->getPriceVat()),
			$product->getPrice(),
			$product->getPriceVat(),
			$product->getPriceBefore() ? $this->filterPrice($product->getPriceBefore()) : null,
			$product->getPriceVatBefore() ? $this->filterPrice($product->getPriceVatBefore()) : null,
			$this->getCustomer(),
			$product->getPriceBefore() ? ((int) \round(100 - ($product->getPrice() / $product->getPriceBefore() * 100))) : null,
		);
	}

	public function getProductPricesFormattedFromCartItem(CartItem $cartItem): ?ProductWithFormattedPrices
	{
		if (!$product = $cartItem->product) {
			return null;
		}

		return new ProductWithFormattedPrices(
			$this->translator,
			$product,
			$this->showPricesWithVat(),
			$this->showPricesWithoutVat(),
			$this->showPriorityPrices(),
			$this->getCatalogPermission() === 'price',
			$this->filterPrice($cartItem->price),
			$this->filterPrice($cartItem->priceVat),
			$cartItem->price,
			$cartItem->priceVat,
			$cartItem->priceBefore ? $this->filterPrice($cartItem->priceBefore) : null,
			$cartItem->priceVatBefore ? $this->filterPrice($cartItem->priceVatBefore) : null,
			$this->getCustomer(),
			$cartItem->priceBefore ? ((int) \round(100 - ($cartItem->price / $cartItem->priceBefore * 100))) : null,
			$cartItem->amount,
			$this->filterPrice($cartItem->price * $cartItem->amount),
			$this->filterPrice($cartItem->priceVat * $cartItem->amount),
			$cartItem->priceBefore ? $this->filterPrice($cartItem->priceBefore) : null,
			$cartItem->priceVatBefore ? $this->filterPrice($cartItem->priceVatBefore) : null,
		);
	}

	public function getMainCategoryType(): CategoryType
	{
		if ($this->mainCategoryType !== false) {
			return $this->mainCategoryType;
		}

		$shop = $this->shopsConfig->getSelectedShop();

		if (!$shop) {
			return $this->mainCategoryType = $this->categoryTypeRepository->many()->setOrderBy(['priority'])->first();
		}

		$setting = $this->settingRepository->getValueByName(SettingsPresenter::MAIN_CATEGORY_TYPE . '_' . $shop->getPK());

		if (!$setting) {
			throw new \Exception('Shop is selected, but has no associated category type.');
		}

		return $this->mainCategoryType = $this->categoryTypeRepository->one($setting, true);
	}

	/**
	 * Vrací aktuální měnu, pokud zadáte kód vrací měnu dle kódu
	 */
	public function getCurrency(?string $code = null): Currency
	{
		if ($code) {
			return $this->altCurrencies[$code] ??= $this->currencyRepository->one(['code' => $code], true);
		}

		if ($this->getCustomer() && $this->getCustomer()->preferredCurrency) {
			return $this->getCustomer()->preferredCurrency;
		}

		return $this->currency ??= $this->currencyRepository->one(['code' => $this->config['currency']], true);
	}

	public function getUserPreferredMutation(): ?string
	{
		$user = $this->getCustomer() ?? $this->getMerchant();

		return $user?->getPreferredMutation();
	}

	/**
	 * Nastaví zákazníka
	 */
	public function setCustomer(?Customer $customer): void
	{
		$this->clearCached();

		$this->customer = $customer;
	}

	/**
	 * Nastaví obchodníka
	 */
	public function setMerchant(?Merchant $merchant): void
	{
		$this->clearCached();

		$this->merchant = $merchant;
	}

	public function getCustomerGroup(): ?CustomerGroup
	{
		if ($this->customerGroup !== false) {
			return $this->customerGroup;
		}

		$customer = $this->getCustomer();

		return $this->customerGroup = $customer ? $customer->group : $this->customerGroupRepository->getUnregisteredGroup();
	}

	public function setCustomerGroup(CustomerGroup $customerGroup): void
	{
		$this->customerGroup = $customerGroup;
	}

	public function getMinimalOrderValue(): float
	{
		$group = $this->getCustomerGroup();

		if ($group && $minimalOrderValue = $this->minimalOrderValueRepository->getMinimalOrderValue($group, $this->getCurrency())) {
			return $minimalOrderValue->price;
		}

		return 0;
	}

	/**
	 * @return 'customer'|'merchant'|'merge'|null
	 */
	public function getMerchantPriceListsMode(): string|null
	{
		return $this->getMerchant()?->priceListsMode;
	}

	/**
	 * Vrací kolekci aktuálních ceník, respektující uživatel i měnu
	 * @param \Eshop\DB\Currency|null $currency
	 * @param \Eshop\DB\DiscountCoupon|null $discountCoupon
	 * @return \StORM\Collection<\Eshop\DB\Pricelist>
	 */
	public function getPricelists(?Currency $currency = null, ?DiscountCoupon $discountCoupon = null): Collection
	{
		$discountCoupon ??= $this->getCheckoutManager()->getDiscountCoupon();
		$currency = $currency ?: ($this->getCurrency()->isConversionEnabled() ? $this->getCurrency()->convertCurrency : $this->getCurrency());

		$customer = $this->getCustomer();
		$merchant = $this->getMerchant();

		$unregistredGroup = $this->getCustomerGroup() ?: $this->customerGroupRepository->getUnregisteredGroup();
		$unregisteredPricelists = $unregistredGroup->defaultPricelists->toArrayOf('uuid');
		$repo = $this->pricelistRepository;

		if ($customer && $merchant) {
			if ($this->getMerchantPriceListsMode() === 'customer') {
				return $repo->getCustomerPricelists($customer, $currency, $this->getCountry(), $discountCoupon);
			}

			if ($this->getMerchantPriceListsMode() === 'merchant') {
				return $repo->getMerchantPricelists($merchant, $currency, $this->getCountry(), $discountCoupon);
			}

			return $repo->getMergedPriceLists($customer, $merchant, $currency, $this->getCountry(), $discountCoupon);
		}

		if (!$customer && $merchant) {
			return $repo->getMerchantPricelists($merchant, $currency, $this->getCountry(), $discountCoupon);
		}

		return $customer ? $repo->getCustomerPricelists($customer, $currency, $this->getCountry(), $discountCoupon) :
			$repo->getPricelists($unregisteredPricelists, $currency, $this->getCountry(), $discountCoupon);
	}

	/**
	 * Vrací kolekci aktuálních ceník, respektující uživatel i měnu, cachuje se do proměnné
	 * @param \Eshop\DB\Currency|null $currency
	 * @param \Eshop\DB\DiscountCoupon|null $discountCoupon
	 * @return array<string|int, \Eshop\DB\Pricelist>
	 */
	public function getPriceListsCached(
		Currency|null $currency = null,
		DiscountCoupon|null $discountCoupon = null
	): array {
		$index = "{$currency?->getPK()}-{$discountCoupon?->getPK()}";

		if (isset($this->priceLists[$index])) {
			return $this->priceLists[$index];
		}

		return $this->priceLists[$index] = $this->getPricelists($currency, $discountCoupon)->toArray();
	}

	/**
	 * @return array<string>
	 */
	public function getAllPriceLists(): array
	{
		if ($this->allPriceLists !== false) {
			return $this->allPriceLists;
		}

		return $this->allPriceLists = $this->getPricelists()->toArrayOf('uuid', toArrayValues: true);
	}

	/**
	 * @return array<string>|null
	 */
	public function getFavouritePriceLists(): array|null
	{
		if ($this->favouritePriceLists !== false) {
			return $this->favouritePriceLists;
		}

		$customer = $this->getCustomer();

		return $this->favouritePriceLists = ($customer && $favouritePriceLists = $customer->getFavouritePriceLists()->where('this.isActive', true)->toArrayOf('uuid', toArrayValues: true)) ?
			$favouritePriceLists : null;
	}

	/**
	 * @param string $prefix
	 * @param array<mixed> $filters
	 */
	public function getPriceCacheIndex(string $prefix, array $filters = []): ?string
	{
		if (\count($filters) > 1) {
			return null;
		}

		return $prefix . \implode('', \array_keys($this->getPriceListsCached())) . \implode('', \array_keys($this->getVisibilityLists())) . \http_build_query($filters);
	}

	/**
	 * @param array<mixed> $configuration
	 */
	public function setRegistrationConfiguration(array $configuration): void
	{
		$this->registrationConfiguration = $configuration;
	}

	/**
	 * @return array<mixed>
	 */
	public function getRegistrationConfiguration(): array
	{
		return $this->registrationConfiguration;
	}

	/**
	 * @return \StORM\Collection<\Eshop\DB\Customer>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getChildrenCustomers(): Collection
	{
		$user = $this->getMerchant() ?? $this->getCustomer();

		if (!$user) {
			return $this->customerRepository->many()->where('1=0');
		}

		return $user instanceof Merchant ? $this->customerRepository->many()
			->join(['nxn' => 'eshop_merchant_nxn_eshop_customer'], 'this.uuid = nxn.fk_customer')
			->where('nxn.fk_merchant', $user) : $this->customerRepository->many()->where('fk_parentCustomer', $user->getPK());
	}

	/**
	 * @return array<\Eshop\DB\Customer>
	 */
	public function getChildrenCustomersArray(): array
	{
		if ($this->childrenCustomers !== false) {
			return $this->childrenCustomers;
		}

		return $this->childrenCustomers = $this->getChildrenCustomers()->toArray();
	}

	public function getCatalogPermissionObject(): ?CurrentContextCatalogPermissions
	{
		$customer = $this->getCustomer();
		$merchant = $this->getMerchant();

		if ($merchant && (!$customer && !$merchant->activeCustomerAccount)) {
			return null;
		}

		return $customer ? $this->getCurrentContextCatalogPermissions->execute($customer) : null;
	}

	/**
	 * @return 'none'|'catalog'|'price'|string
	 */
	public function getCatalogPermission(): string
	{
		$customer = $this->getCustomer();
		$merchant = $this->getMerchant();

		if ($merchant?->catalogPermission === 'merchant') {
			return self::MERCHANT_CATALOG_PERMISSIONS;
		}

		if ($merchant && (!$customer && !$merchant->activeCustomerAccount)) {
			return self::MERCHANT_CATALOG_PERMISSIONS;
		}

		if (!$customer) {
			return $this->getCustomerGroup()?->defaultCatalogPermission ?: 'none';
		}

		$catalogPermission = $this->getCatalogPermissionObject();

		if (!$catalogPermission) {
			return 'none';
		}

		return $catalogPermission->catalogPermission;
	}

	public function canViewCatalog(): bool
	{
		return $this->getCatalogPermission() !== 'none';
	}

	public function canViewPrices(): bool
	{
		return $this->getCatalogPermission() === 'price';
	}

	public function getBuyPermission(): bool
	{
		$customer = $this->getCustomer();
		$merchant = $this->getMerchant();

		if ($merchant && (!$customer && !$merchant->activeCustomerAccount)) {
			return false;
		}

		if (!$customer || !$catalogPermission = $this->getCatalogPermissionObject()) {
			return $this->getCustomerGroup() ? $this->getCustomerGroup()->defaultBuyAllowed : false;
		}

		return $catalogPermission->buyAllowed;
	}

	/**
	 * @return array<float>
	 */
	public function getVatRates(): array
	{
		return $this->vatRates ??= $this->getCountry()->vatRates->toArrayOf('rate');
	}

	public function showPricesWithVat(): bool
	{
		if (!$this->getShowVat()) {
			return false;
		}

		$customer = $this->getCustomer();

		if ($this->getMerchant() && !$customer) {
			return true;
		}

		if ($customer) {
			$catalogPerm = $this->getCatalogPermissionObject();
		}

		return $customer && $catalogPerm ? $catalogPerm->showPricesWithVat : $this->customerGroupRepository->getUnregisteredGroup()->defaultPricesWithVat;
	}

	public function showPricesWithoutVat(): bool
	{
		if (!$this->getShowWithoutVat()) {
			return false;
		}

		$customer = $this->getCustomer();

		if ($this->getMerchant() && !$customer) {
			return true;
		}

		if ($customer) {
			$catalogPerm = $this->getCatalogPermissionObject();
		}

		return $customer && $catalogPerm ? $catalogPerm->showPricesWithoutVat : $this->customerGroupRepository->getUnregisteredGroup()->defaultPricesWithoutVat;
	}

	/**
	 * @return 'withVat'|'withoutVat'
	 */
	public function showPriorityPrices(): string
	{
		$customer = $this->getCustomer();

		if ($this->getMerchant() && !$customer) {
			return 'withoutVat';
		}

		if ($customer) {
			$catalogPerm = $this->getCatalogPermissionObject();
		}
		
		return $customer && $catalogPerm?->priorityPrice ? $catalogPerm->priorityPrice : $this->customerGroupRepository->getUnregisteredGroup()->defaultPriorityPrice;
	}

	/**
	 * @deprecated Use getMainPriceType
	 * @return 'withVat'|'withoutVat'
	 */
	public function getShowPrice(): string
	{
		return $this->getMainPriceType();
	}

	/**
	 * @return 'withVat'|'withoutVat'
	 */
	public function getMainPriceType(): string
	{
		if ($this->mainPriceType !== false) {
			return $this->mainPriceType;
		}

		if ($this->showPricesWithoutVat() && $this->showPricesWithVat()) {
			return $this->mainPriceType = $this->showPriorityPrices();
		}

		if ($this->showPricesWithoutVat()) {
			return $this->mainPriceType = 'withoutVat';
		}

		if ($this->showPricesWithVat()) {
			return $this->mainPriceType = 'withVat';
		}

		return $this->mainPriceType = 'withoutVat';
	}

	public function addFilters(\Nette\Bridges\ApplicationLatte\Template $template): void
	{
		$template->addFilter('price', function ($number, ?string $currencyCode = null, ?int $formatDecimals = null) {
			return $this->filterPrice($number, $currencyCode, $formatDecimals);
		});

		$template->addFilter('priceNoZero', function ($number, ?string $currencyCode = null, ?int $formatDecimals = null) {
			return $this->filterPriceNoZero($number, $currencyCode, $formatDecimals);
		});
	}

	public function addFiltersSecondary(\Nette\Bridges\ApplicationLatte\Template $template): void
	{
		$template->addFilter('priceSecondary', function ($number, ?string $currencyCode = null, ?int $formatDecimals = null) {
			return $this->filterPriceSecondary($number, $currencyCode, $formatDecimals);
		});
	}

	/**
	 * Formátuje cenu
	 * @param float|int $number
	 * @param string|null $currencyCode
	 */
	public function filterPrice($number, ?string $currencyCode = null, ?int $formatDecimals = null): string
	{
		$currency = $this->getCurrency($currencyCode);

		if ($currency->formatDecimals === null) {
			$localeInfo = \localeconv();
			$currency->formatDecimals = (int) ($localeInfo['frac_digits'] ?? 0);
		}

		$formatDecimals ??= $currency->formatDecimals;

		$nbsp = \html_entity_decode('&nbsp;');
		$formatted = \number_format((float) $number, $formatDecimals, $currency->formatDecimalSeparator, \str_replace(' ', $nbsp, $currency->formatThousandsSeparator));

		return ($currency->formatSymbolPosition !== 'after' ? $currency->symbol : '') . $formatted . $nbsp . ($currency->formatSymbolPosition === 'after' ? $currency->symbol : '');
	}

	/**
	 * Formátuje cenu
	 * @param float|int $number
	 * @param string|null $currencyCode
	 */
	public function filterPriceSecondary($number, ?string $currencyCode = null, ?int $formatDecimals = null): string
	{
		$currency = $this->getCurrency($currencyCode);

		if ($currency->formatDecimals === null) {
			$localeInfo = \localeconv();
			$currency->formatDecimals = (int) ($localeInfo['frac_digits'] ?? 0);
		}

		$formatDecimals ??= $currency->formatDecimals;

		$nbsp = \html_entity_decode('&nbsp;');
		$formatted = \number_format((float) $number, $formatDecimals, $currency->formatDecimalSeparator, \str_replace(' ', $nbsp, $currency->formatThousandsSeparator));

		return ($currency->formatSymbolPosition !== 'after' ? $currency->symbol : '') . $formatted . $nbsp . ($currency->formatSymbolPosition === 'after' ? $currency->symbol : '');
	}

	/**
	 * Formátuje cenu - 0 se nezobrazuje
	 * @param float|int $number
	 * @param string|null $currencyCode
	 */
	public function filterPriceNoZero($number, ?string $currencyCode = null, ?int $formatDecimals = null): string
	{
		return $number > 0 ? $this->filterPrice($number, $currencyCode, $formatDecimals) : '';
	}

	/**
	 * @param string|\Security\DB\Account $account
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getPreferredMutationByAccount(string|Account $account): ?string
	{
		if (!$account instanceof Account) {
			if (!$account = $this->accountRepository->one($account)) {
				return null;
			}
		}

		$mutation = $account->getPreferredMutation();

		if ($mutation) {
			return $mutation;
		}

		/** @var \Eshop\DB\Customer|null $customer */
		$customer = $this->customerRepository->getByAccountLogin($account->getPK());

		if ($customer) {
			return $customer->getPreferredMutation();
		}

		return null;
	}

	/**
	 * @throws \StORM\Exception\NotFoundException
	 * @throws \Exception
	 */
	public function impersonateCustomer(Customer $customer): void
	{
		$merchant = $this->getMerchant();
		$account = $customer->account;

		if ($merchant === null) {
			throw new \Exception('Impersonation allowed only for merchant accounts!');
		}

		$merchant->update(['activeCustomer' => $customer->getPK()]);

		if ($account === null) {
			return;
		}

		$merchant->update(['activeCustomerAccount' => $account->getPK()]);
	}

	/**
	 * @throws \StORM\Exception\NotFoundException
	 * @throws \Exception
	 */
	public function impersonateAccount(Account $account): void
	{
		$merchant = $this->getMerchant();
		$customer = $this->customerRepository->one(['accounts' => $account]);

		if ($merchant === null) {
			throw new \Exception('Impersonation allowed only for merchant accounts!');
		}

		if ($customer === null) {
			throw new \Exception('Only customer accounts can be impersonated!');
		}

		$merchant->update(['activeCustomer' => $customer->getPK()]);
		$merchant->update(['activeCustomerAccount' => $account->getPK()]);
	}

	/**
	 * @throws \StORM\Exception\NotFoundException
	 * @throws \Exception
	 */
	public function stopImpersonating(): void
	{
		$merchant = $this->getMerchant();

		if ($merchant === null) {
			throw new \Exception('Impersonation allowed only for merchant accounts!');
		}

		$merchant->update(['activeCustomer' => null]);
		$merchant->update(['activeCustomerAccount' => null]);
	}

	public function fillPhonePrefix(string $phone): string
	{
		if (\str_starts_with($phone, '+')) {
			return $phone;
		}

		if ($phonePrefix = $this->getCountry()->phonePrefix) {
			return $phonePrefix . $phone;
		}

		return $phone;
	}

	protected function clearCached(): void
	{
		$this->priceLists = [];
		$this->visibilityLists = false;
		$this->customer = false;
		$this->merchant = false;
		$this->customerGroup = false;
		$this->mainPriceType = false;
		$this->allPriceLists = false;
		$this->favouritePriceLists = false;
	}
}
