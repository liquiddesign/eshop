<?php

declare(strict_types=1);

namespace Eshop;

use Eshop\DB\Country;
use Eshop\DB\CountryRepository;
use Eshop\DB\Currency;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\MinimalOrderValueRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroup;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Merchant;
use Eshop\DB\MerchantRepository;
use Nette\Application\Application;
use Nette\Application\UI\ITemplate;
use Nette\Security\User;
use StORM\Collection;
use Translator\Translator;

/**
 * Služba která zapouzdřuje nakupujícího
 * @package Eshop
 */
class Shopper
{
	public const PERMISSIONS = [
		'none' => 'Žádné',
		'catalog' => 'Katalog',
		'price' => 'Ceny',
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

	/**
	 * @var \Eshop\DB\Currency[]
	 */
	private array $altCurrencies = [];

	private Application $application;

	private CurrencyRepository $currencyRepository;

	private CountryRepository $countryRepository;

	private CustomerRepository $customerRepository;

	private MerchantRepository $merchantRepository;

	private CustomerGroupRepository $customerGroupRepository;

	private MinimalOrderValueRepository $minimalOrderValueRepository;

	private string $countryCode;

	private string $currencyCode;

	private string $projectUrl;

	private ?Customer $customer = null;

	private ?CustomerGroup $customerGroup;

	public function __construct(
		User $user,
		PricelistRepository $pricelistRepository,
		CurrencyRepository $currencyRepository,
		CountryRepository $countryRepository,
		CustomerRepository $customerRepository,
		MerchantRepository $merchantRepository,
		CustomerGroupRepository $customerGroupRepository,
		MinimalOrderValueRepository $minimalOrderValueRepository,
		Application $application,
		\Nette\Localization\Translator $translator)
	{


		$this->user = $user;
		$this->pricelistRepository = $pricelistRepository;
		$this->application = $application;

		$this->countryRepository = $countryRepository;
		$this->currencyRepository = $currencyRepository;
		$this->customerRepository = $customerRepository;
		$this->merchantRepository = $merchantRepository;
		$this->customerGroupRepository = $customerGroupRepository;
		$this->minimalOrderValueRepository = $minimalOrderValueRepository;
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

	public function getCountry()
	{
		return $this->country ??= $this->countryRepository->one(['code' => $this->countryCode], true);
	}

	public function setCurrency(string $currencyCode): void
	{
		unset($this->currency);
		$this->currencyCode = $currencyCode;
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

	/**
	 * Nastaví zákazníka
	 * @param Customer $customer
	 */
	public function setCustomer(Customer $customer): void
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

	public function getMinimalOrderValue()
	{
		$group = $this->getCustomerGroup();

		if ($group && $minimalOrderValue = $this->minimalOrderValueRepository->getMinimalOrderValue($group, $this->getCurrency())) {
			return $minimalOrderValue->price;
		}

		return 0;
	}

	/**
	 * Vrací kolekci aktuálních ceník, respektující uživatel i měnu
	 */
	public function getPricelists(?Currency $currency = null): Collection
	{
		if ($this->pricelists !== null) {
			return $this->pricelists;
		}

		$customer = $this->getCustomer();
		$unregistredGroup = $this->getCustomerGroup() ?: $this->customerGroupRepository->getUnregisteredGroup();
		$unregisteredPricelists = $unregistredGroup->defaultPricelists->toArrayOf('uuid');
		$repo = $this->pricelistRepository;

		return $this->pricelists = $customer ? $repo->getCustomerPricelists($customer, $currency ?: $this->getCurrency(), $this->getCountry()) : $repo->getPricelists($unregisteredPricelists, $currency ?: $this->getCurrency(), $this->getCountry());
	}

	/**
	 * @return mixed[]
	 */
	public function getRegistrationConfiguration()
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

		if (!$customer) {
			return $this->getCustomerGroup()->defaultBuyAllowed;
		}

		if (!$catalogPermission = $customer->getCatalogPermission()) {
			return false;
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
		$customer = $this->getCustomer();

		if ($this->getMerchant() && !$customer) {
			return false;
		}

		return $customer ? $customer->pricesWithVat : $this->customerGroupRepository->getUnregisteredGroup()->defaultPricesWithVat;
	}

	/**
	 * @param \Nette\Application\UI\ITemplate|\stdClass $template
	 */
	public function addFilters(ITemplate $template)
	{
		$template->addFilter('price', function ($number, string $currencyCode = null) {
			return $this->filterPrice($number, $currencyCode);
		});
	}

	/**
	 * Formátuje cenu
	 * @param float|int $number
	 * @param string|null $currencyCode
	 * @return string
	 */
	public function filterPrice($number, ?string $currencyCode = null): string
	{
		$currency = $this->getCurrency($currencyCode);

		if ($currency->formatDecimals === null) {
			$formatter = new \NumberFormatter($this->application->getMutation(), \NumberFormatter::CURRENCY);

			return $formatter->formatCurrency((float)$number, $currency->code);
		}

		$nbsp = \html_entity_decode('&nbsp;');
		$formatted = \number_format((float)$number, $currency->formatDecimals, $currency->formatDecimalSeparator, \str_replace(' ', $nbsp, $currency->formatThousandsSeparator));

		return ($currency->formatSymbolPosition !== 'after' ? $currency->symbol : '') . $formatted . $nbsp . ($currency->formatSymbolPosition === 'after' ? $currency->symbol : '');
	}
}
