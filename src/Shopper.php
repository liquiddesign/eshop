<?php

declare(strict_types=1);

namespace Eshop;

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
use Nette\Security\User;
use Security\DB\Account;
use Security\DB\AccountRepository;
use StORM\Collection;

/**
 * Služba která zapouzdřuje nakupujícího
 * @package Eshop
 */
class Shopper
{
	public const PERMISSIONS = [
		'none' => 'Nezobrazeno',
		'catalog' => 'Bez cen',
		'price' => 'S cenami',
	];
	
	private const MERCHANT_CATALOG_PERMISSIONS = 'price';
	
	/**
	 * @var bool[]
	 */
	private array $registrationConfiguration = [
		'enabled' => true,
		'confirmation' => true,
		'emailAuthorization' => true,
	];
	
	/**
	 * @var \StORM\Collection<\Eshop\DB\Pricelist>|null
	 */
	private ?Collection $pricelists = null;
	
	private User $user;
	
	private PricelistRepository $pricelistRepository;
	
	private Currency $currency;
	
	private Country $country;
	
	/**
	 * @var float[]
	 */
	private array $vatRates = [];
	
	private bool $showVat;
	
	private bool $showWithoutVat;

	private bool $showZeroPrices;
	
	/**
	 * @var \Eshop\DB\Currency[]
	 */
	private array $altCurrencies = [];
	
	private CurrencyRepository $currencyRepository;
	
	private CountryRepository $countryRepository;
	
	private CustomerRepository $customerRepository;
	
	private CustomerGroupRepository $customerGroupRepository;
	
	private MinimalOrderValueRepository $minimalOrderValueRepository;
	
	private string $countryCode;
	
	private string $currencyCode;
	
	private string $projectUrl;
	
	private bool $editOrderAfterCreation;

	private bool $alwaysCreateCustomerOnOrderCreated;

	private bool $integrationsEHub;

	/**
	 * @var array<mixed>
	 */
	private array $reviews;

	/**
	 * @var array<mixed>
	 */
	private array $invoices;

	/**
	 * @var array<mixed>
	 */
	private array $categories;
	
	private bool $allowBannedEmailOrder = false;

	private bool $useDiscountLevelCalculationInBeforePrice = false;

	private ?DiscountCoupon $discountCoupon = null;
	
	private ?Customer $customer = null;
	
	private ?CustomerGroup $customerGroup;
	
	private AccountRepository $accountRepository;
	
	public function __construct(
		User $user,
		PricelistRepository $pricelistRepository,
		CurrencyRepository $currencyRepository,
		CountryRepository $countryRepository,
		CustomerRepository $customerRepository,
		CustomerGroupRepository $customerGroupRepository,
		MinimalOrderValueRepository $minimalOrderValueRepository,
		AccountRepository $accountRepository
	) {
		$this->user = $user;
		$this->pricelistRepository = $pricelistRepository;
		$this->accountRepository = $accountRepository;
		$this->countryRepository = $countryRepository;
		$this->currencyRepository = $currencyRepository;
		$this->customerRepository = $customerRepository;
		$this->customerGroupRepository = $customerGroupRepository;
		$this->minimalOrderValueRepository = $minimalOrderValueRepository;
	}

	public function setUseDiscountLevelCalculationInBeforePrice(bool $useDiscountLevelCalculationInBeforePrice): void
	{
		$this->useDiscountLevelCalculationInBeforePrice = $useDiscountLevelCalculationInBeforePrice;
	}

	public function getUseDiscountLevelCalculationInBeforePrice(): bool
	{
		return $this->useDiscountLevelCalculationInBeforePrice;
	}
	
	public function setAllowBannedEmailOrder(bool $allowBannedEmailOrder): void
	{
		$this->allowBannedEmailOrder = $allowBannedEmailOrder;
	}
	
	public function getAllowBannedEmailOrder(): bool
	{
		return $this->allowBannedEmailOrder;
	}
	
	public function setEditOrderAfterCreation(bool $editOrderAfterCreation): void
	{
		$this->editOrderAfterCreation = $editOrderAfterCreation;
	}
	
	public function getEditOrderAfterCreation(): bool
	{
		return $this->editOrderAfterCreation;
	}
	
	public function setShowVat(bool $showVat): void
	{
		$this->showVat = $showVat;
	}
	
	public function getShowVat(): bool
	{
		return $this->showVat;
	}
	
	public function setShowWithoutVat(bool $showWithoutVat): void
	{
		$this->showWithoutVat = $showWithoutVat;
	}
	
	public function getShowWithoutVat(): bool
	{
		return $this->showWithoutVat;
	}

	public function setShowZeroPrices(bool $showZeroPrices): void
	{
		$this->showZeroPrices = $showZeroPrices;
	}

	public function getShowZeroPrices(): bool
	{
		return $this->showZeroPrices;
	}
	
	public function setRegistrationConfiguration(array $configuration): void
	{
		$this->registrationConfiguration = $configuration;
	}
	
	public function setProjectUrl(string $projectUrl): void
	{
		$this->projectUrl = $projectUrl;
	}
	
	public function setCountry(string $countryCode): void
	{
		unset($this->country);
		$this->countryCode = $countryCode;
	}
	
	public function getProjectUrl(): string
	{
		return $this->projectUrl;
	}
	
	public function getCountry(): Country
	{
		return $this->country ??= $this->countryRepository->one(['code' => $this->countryCode], true);
	}
	
	public function setCurrency(string $currencyCode): void
	{
		unset($this->currency);
		$this->currencyCode = $currencyCode;
	}

	public function setDiscountCoupon(?DiscountCoupon $discountCoupon): void
	{
		$this->discountCoupon = $discountCoupon;
	}

	public function isAlwaysCreateCustomerOnOrderCreated(): bool
	{
		return $this->alwaysCreateCustomerOnOrderCreated;
	}

	public function setAlwaysCreateCustomerOnOrderCreated(bool $create): void
	{
		$this->alwaysCreateCustomerOnOrderCreated = $create;
	}

	public function setIntegrationsEHub(bool $eHub): void
	{
		$this->integrationsEHub = $eHub;
	}

	/**
	 * @param array<mixed> $reviews
	 */
	public function setReviews(array $reviews): void
	{
		$this->reviews = $reviews;
	}

	/**
	 * @param array<mixed> $invoices
	 */
	public function setInvoices(array $invoices): void
	{
		$this->invoices = $invoices;
	}

	/**
	 * @param array<mixed> $categories
	 */
	public function setCategories(array $categories): void
	{
		$this->categories = $categories;
	}

	/**
	 * @return array<mixed>
	 */
	public function getCategoriesImage(): array
	{
		return $this->categories['image'];
	}

	/**
	 * @return array<mixed>
	 */
	public function getCategoriesFallbackImage(): array
	{
		return $this->categories['fallbackImage'];
	}

	public function getInvoicesAutoTaxDateInDays(): int
	{
		return $this->invoices['autoTaxDateInDays'];
	}

	public function getInvoicesAutoDueDateInDays(): int
	{
		return $this->invoices['autoDueDateInDays'];
	}

	public function getReviewsType(): string
	{
		return $this->reviews['type'];
	}

	public function getReviewsMinScore(): float
	{
		return $this->reviews['minScore'];
	}

	public function getReviewsMaxScore(): float
	{
		return $this->reviews['maxScore'];
	}

	public function getReviewsMaxRemindersCount(): int
	{
		return $this->reviews['maxRemindersCount'];
	}

	public function getReviewsMiddleScore(): float
	{
		return ($this->getReviewsMinScore() + $this->getReviewsMaxScore()) / 2;
	}

	public function isIntegrationsEHub(): bool
	{
		return $this->integrationsEHub;
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
		
		return $this->currency ??= $this->currencyRepository->one(['code' => $this->currencyCode], true);
	}
	
	/**
	 * Vrací aktuálního uživatele
	 * Prioritně vrací zákazníka nastaveného pomocí setCustomer
	 */
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
	
	public function getUserPreferredMutation(): ?string
	{
		$user = $this->getCustomer() ?? $this->getMerchant();
		
		return $user ? $user->getPreferredMutation() : null;
	}
	
	/**
	 * Nastaví zákazníka
	 */
	public function setCustomer(?Customer $customer): void
	{
		$this->customer = $customer;
		$this->customerGroup = null;
	}
	
	public function getMerchant(): ?Merchant
	{
		return $this->user->isLoggedIn() && $this->user->getIdentity() instanceof Merchant ? $this->user->getIdentity() : null;
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
	 */
	public function getPricelists(?Currency $currency = null): Collection
	{
		if ($this->pricelists !== null && $currency === null) {
			return $this->pricelists;
		}
		
		$currency = $currency ?: ($this->getCurrency()->isConversionEnabled() ? $this->getCurrency()->convertCurrency : $this->getCurrency());
		
		$customer = $this->getCustomer();
		$merchant = $this->getMerchant();
		
		$unregistredGroup = $this->getCustomerGroup() ?: $this->customerGroupRepository->getUnregisteredGroup();
		$unregisteredPricelists = $unregistredGroup->defaultPricelists->toArrayOf('uuid');
		$repo = $this->pricelistRepository;
		
		if (!$customer && $merchant) {
			return $this->pricelists = $repo->getMerchantPricelists($merchant, $currency, $this->getCountry(), $this->getDiscountCoupon());
		}
		
		return $this->pricelists = $customer ? $repo->getCustomerPricelists($customer, $currency, $this->getCountry(), $this->getDiscountCoupon()) :
			$repo->getPricelists($unregisteredPricelists, $currency, $this->getCountry(), $this->getDiscountCoupon());
	}
	
	public function getPriceCacheIndex(string $prefix, array $filters = []): ?string
	{
		if (\count($filters) > 1) {
			return null;
		}
		
		return $prefix . \implode('', $this->getPricelists()->toArrayOf('uuid')) . \http_build_query($filters);
	}
	
	/**
	 * @return mixed[]
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
	 * @return float[]
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
	
	public function showPriorityPrices(): ?string
	{
		$customer = $this->getCustomer();
		
		if ($this->getMerchant() && !$customer) {
			return null;
		}
		
		if ($customer) {
			$catalogPerm = $customer->getCatalogPermission();
		}
		
		return $customer ? $catalogPerm->priorityPrice : $this->customerGroupRepository->getUnregisteredGroup()->defaultPriorityPrice;
	}

	/**
	 * Main function, always use this to determine vat or withoutVat on frontend
	 */
	public function getShowPrice(): ?string
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

		return null;
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
		$formatted = \number_format((float)$number, $currency->formatDecimals, $currency->formatDecimalSeparator, \str_replace(' ', $nbsp, $currency->formatThousandsSeparator));
		
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
	
	public function getPreferredMutationByAccount($account): ?string
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

	private function getDiscountCoupon(): ?DiscountCoupon
	{
		return $this->discountCoupon ?? null;
	}
}
