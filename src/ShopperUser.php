<?php

namespace Eshop;

use Admin\DB\RoleRepository;
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
use Eshop\DB\MinimalOrderValueRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\Product;
use Nette\DI\Container;
use Nette\Security\Authorizator;
use Nette\Security\IAuthenticator;
use Nette\Security\IUserStorage;
use Nette\Security\User;
use Nette\Security\UserStorage;
use Security\DB\Account;
use Security\DB\AccountRepository;
use StORM\Collection;

class ShopperUser extends User
{
	public const PERMISSIONS = [
		'none' => 'Nezobrazeno',
		'catalog' => 'Bez cen',
		'price' => 'S cenami',
	];

	public const PRICE_PRECISSION = 4;

	protected const MERCHANT_CATALOG_PERMISSIONS = 'price';

	protected ?Customer $customer = null;

	protected CheckoutManager $checkoutManager;

	protected ?CustomerGroup $customerGroup;

	protected Country $country;

	protected Currency $currency;

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
	protected array $vatRates = [];

	/**
	 * @var array<mixed>
	 */
	private array $config = [];

	public function __construct(
		protected readonly PricelistRepository $pricelistRepository,
		protected readonly CurrencyRepository $currencyRepository,
		protected readonly CountryRepository $countryRepository,
		protected readonly CustomerRepository $customerRepository,
		protected readonly CustomerGroupRepository $customerGroupRepository,
		protected readonly MinimalOrderValueRepository $minimalOrderValueRepository,
		protected readonly AccountRepository $accountRepository,
		protected readonly RoleRepository $roleRepository,
		private readonly Container $container,
		?IUserStorage $legacyStorage = null,
		?IAuthenticator $authenticator = null,
		?Authorizator $authorizator = null,
		?UserStorage $storage = null,
	) {
		parent::__construct($legacyStorage, $authenticator, $authorizator, $storage);
	}

	public function getCheckoutManager(): CheckoutManager
	{
		return $this->checkoutManager ??= $this->container->createInstance(CheckoutManager::class);
	}

	public function setConfig(array $config): void
	{
		$this->config = $config;
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
		return $this->config['discountConditions'];
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
		return $this->country ??= $this->countryRepository->one(['code' => $this->config['country']], true);
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
		$this->customer = $customer;
		$this->customerGroup = null;
	}

	public function getCustomerGroup(): ?CustomerGroup
	{
		$customer = $this->getCustomer();

		return $this->customerGroup ??= $customer ? $customer->group : $this->customerGroupRepository->getUnregisteredGroup();
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
	 * Vrací kolekci aktuálních ceník, respektující uživatel i měnu, cachuje se do proměnné pokud není zadána měna
	 * If possible, dont use this function but getPricelists(..) in CheckoutManager!
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

		if (!$customer && $merchant) {
			return $repo->getMerchantPricelists($merchant, $currency, $this->getCountry(), $discountCoupon);
		}

		return $customer ? $repo->getCustomerPricelists($customer, $currency, $this->getCountry(), $discountCoupon) :
			$repo->getPricelists($unregisteredPricelists, $currency, $this->getCountry(), $discountCoupon);
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

		return $prefix . \implode('', $this->getPricelists()->toArrayOf('uuid')) . \http_build_query($filters);
	}

	/**
	 * @return array<mixed>
	 */
	public function getRegistrationConfiguration(): array
	{
		return $this->registrationConfiguration;
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
			$catalogPerm = $customer->getCatalogPermission();
		}

		return $customer ? $catalogPerm->showPricesWithVat : $this->customerGroupRepository->getUnregisteredGroup()->defaultPricesWithVat;
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
			$catalogPerm = $customer->getCatalogPermission();
		}

		return $customer ? $catalogPerm->showPricesWithoutVat : $this->customerGroupRepository->getUnregisteredGroup()->defaultPricesWithoutVat;
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
			$catalogPerm = $customer->getCatalogPermission();
		}

		/** @var 'withVat'|'withoutVat' $result */
		$result = $customer ? $catalogPerm->priorityPrice : $this->customerGroupRepository->getUnregisteredGroup()->defaultPriorityPrice;

		return $result;
	}

	/**
	 * Main function, always use this to determine vat or withoutVat on frontend
	 * @return 'withVat'|'withoutVat'
	 */
	public function getShowPrice(): string
	{
		if ($this->showPricesWithoutVat() && $this->showPricesWithVat()) {
			return $this->showPriorityPrices();
		}

		if ($this->showPricesWithoutVat()) {
			return 'withoutVat';
		}

		if ($this->showPricesWithVat()) {
			return 'withVat';
		}

		return 'withoutVat';
	}

	public function addFilters(\Nette\Bridges\ApplicationLatte\Template $template): void
	{
		$template->addFilter('price', function ($number, ?string $currencyCode = null) {
			return $this->filterPrice($number, $currencyCode);
		});

		$template->addFilter('priceNoZero', function ($number, ?string $currencyCode = null) {
			return $this->filterPriceNoZero($number, $currencyCode);
		});
	}

	/**
	 * Formátuje cenu
	 * @param float|int $number
	 * @param string|null $currencyCode
	 */
	public function filterPrice($number, ?string $currencyCode = null): string
	{
		$currency = $this->getCurrency($currencyCode);

		if ($currency->formatDecimals === null) {
			$localeInfo = \localeconv();
			$currency->formatDecimals = (int) ($localeInfo['frac_digits'] ?? 0);
		}

		$nbsp = \html_entity_decode('&nbsp;');
		$formatted = \number_format((float) $number, $currency->formatDecimals, $currency->formatDecimalSeparator, \str_replace(' ', $nbsp, $currency->formatThousandsSeparator));

		return ($currency->formatSymbolPosition !== 'after' ? $currency->symbol : '') . $formatted . $nbsp . ($currency->formatSymbolPosition === 'after' ? $currency->symbol : '');
	}

	/**
	 * Formátuje cenu - 0 se nezobrazuje
	 * @param float|int $number
	 * @param string|null $currencyCode
	 */
	public function filterPriceNoZero($number, ?string $currencyCode = null): string
	{
		return $number > 0 ? $this->filterPrice($number, $currencyCode) : '';
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
}