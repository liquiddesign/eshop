<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopEntity;
use Carbon\Carbon;
use Nette\Security\IIdentity;
use Security\DB\Account;
use Security\DB\IUser;
use StORM\Collection;
use StORM\RelationCollection;

/**
 * Zákazník
 * @table
 * @index{"name":"customer_unique_emailshop","unique":true,"columns":["email", "fk_shop"]}
 * @method array getData()
 * @method \StORM\RelationCollection<\Eshop\DB\VisibilityList> getVisibilityLists()
 * @method \StORM\RelationCollection<\Eshop\DB\Pricelist> getPricelists()
 * @method \StORM\RelationCollection<\Eshop\DB\Pricelist> getFavouritePricelists()
 * @method \StORM\RelationCollection<\Eshop\DB\Merchant> getMerchants()
 * @method \StORM\RelationCollection<\Security\DB\Account> getAccounts()
 * Due to compatibility within PHP 8.0-8.2 and seamless migration to this version, DynamicProperties are allowed in this class. If they are not, you will have to clear all sessions' data.
 */
#[\AllowDynamicProperties]
class Customer extends ShopEntity implements IIdentity, IUser
{
	/**
	 * Jméno zákazníka / kontaktní osoby
	 * @column
	 */
	public ?string $fullname;
	
	/**
	 * Externí kód
	 * @column
	 */
	public ?string $externalCode;
	
	/**
	 * Externí ID
	 * @column
	 */
	public ?string $externalId;
	
	/**
	 * Telefon
	 * @column
	 */
	public ?string $phone;
	
	/**
	 * Email
	 * @column
	 */
	public ?string $email;
	
	/**
	 * Emaily s kopií
	 * @column
	 */
	public ?string $ccEmails;
	
	/**
	 * Název společnosti
	 * @column
	 */
	public ?string $company;
	
	/**
	 * IČO
	 * @column{"nullable":true}
	 */
	public ?string $ic;
	
	/**
	 * DIČ
	 * @column
	 */
	public ?string $dic;
	
	/**
	 * Země
	 * @column
	 */
	public ?string $countryCode;
	
	/**
	 * Faktruační adresa
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?Address $billAddress;
	
	/**
	 * Dodací adresa
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?Address $deliveryAddress;
	
	/**
	 * Účet
	 * @column
	 */
	public ?string $bankAccount;
	
	/**
	 * Kód banky
	 * @column
	 */
	public ?string $bankAccountCode;
	
	/**
	 * Specifický symbol
	 * @column
	 */
	public ?string $bankSpecificSymbol;
	
	/**
	 * Slevová hladina
	 * @column
	 */
	public int $discountLevelPct = 0;
	
	/**
	 * Max. slevova u produktů
	 * @column
	 */
	public int $maxDiscountProductPct = 100;
	
	/**
	 * Zokrouhlení od procent
	 * @column
	 */
	public ?int $productRoundingPct = null;
	
	/**
	 * Aktivní košík
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Cart $activeCart;
	
	/**
	 * Matka
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Customer $parentCustomer;

	/**
	 * Opposite side of "parentCustomer" relation
	 * @relation{"targetKey":"fk_parentCustomer"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Customer>
	 */
	public RelationCollection $childCustomers;
	
	/**
	 * Vedoucí
	 * @relation
	 * @constraint
	 */
	public ?Customer $leadCustomer;
	
	/**
	 * Preferovaná platba
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?PaymentType $preferredPaymentType;
	
	/**
	 * Povolené exkluzivní platby
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\PaymentType>
	 */
	public RelationCollection $exclusivePaymentTypes;
	
	/**
	 * Povolené exkluzivní dopravy
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\DeliveryType>
	 */
	public RelationCollection $exclusiveDeliveryTypes;
	
	/**
	 * Ceníky
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Pricelist>
	 */
	public RelationCollection $pricelists;

	/**
	 * Oblíbené ceníky
	 * @relationNxN{"via":"eshop_customer_nxn_eshop_pricelist_favourite"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Pricelist>
	 */
	public RelationCollection $favouritePriceLists;

	/**
	 * Viditelníky
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\VisibilityList>
	 */
	public RelationCollection $visibilityLists;

	/**
	 * Obchodníci
	 * @relationNxN{"sourceViaKey":"fk_merchant","targetViaKey":"fk_customer","via":"eshop_merchant_nxn_eshop_customer"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Merchant>
	 */
	public RelationCollection $merchants;
	
	/**
	 * Skupina uživatelů
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?CustomerGroup $group;

	/**
	 * Role uživatelů
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?CustomerRole $customerRole;
	
	/**
	 * Preferovaná doprava
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?DeliveryType $preferredDeliveryType;
	
	/**
	 * Preferovaná měna
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Currency $preferredCurrency;
	
	/**
	 * Preferovaná mutace
	 * @column
	 */
	public ?string $preferredMutation;
	
	/**
	 * Body
	 * @column
	 */
	public ?int $points;

	/**
	 * Oprávnění: objednávky
	 * @column{"type":"enum","length":"'fullWithApproval','full'"}
	 */
	public string $orderPermission = 'full';
	
	/**
	 * Oprávnění: filiálka
	 * @column{"type":"enum","length":"'slave','master','admin'"}
	 */
	public ?string $branchPermission = null;
	
	/**
	 * Oprávnění: použít API
	 * @column
	 */
	public bool $allowAPI = false;
	
	/**
	 * Oprávnění: použít exporty
	 * @column
	 */
	public bool $allowExport = false;

	/**
	 * EDI: Identifikátor firmy
	 * @column
	 */
	public ?string $ediCompany = null;

	/**
	 * EDI: Identifikátor pobočky
	 * @column
	 */
	public ?string $ediBranch = null;
	
	/**
	 * Volné vstupní pole 1
	 * @column
	 */
	public ?string $customField1;

	/**
	 * Věrnostní program
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?LoyaltyProgram $loyaltyProgram = null;
	
	/**
	 * Hladina věrnostního programu
	 * @relation
	 * @constraint{"onUpdate":"RESTRICT","onDelete":"RESTRICT"}
	 */
	public ?LoyaltyProgramDiscountLevel $loyaltyProgramDiscountLevel = null;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * Last order created
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Order $lastOrder;

	/**
	 * Count of all orders by customer
	 * @column
	 */
	public int $ordersCount = 0;

	/**
	 * Poslední načtení z ARES
	 * @column{"type":"datetime"}
	 */
	public ?string $aresLoadedTs;
	
	/**
	 * @relationNxN{"via":"eshop_catalogpermission"}
	 * @var \StORM\RelationCollection<\Security\DB\Account>
	 */
	public RelationCollection $accounts;
	
	public ?Account $account = null;

	protected CatalogPermission|null|false $catalogPermission = false;
	
	public function getDeliveryAddressLine(): ?string
	{
		$deliveryAddress = $this->deliveryAddress;
		
		return $deliveryAddress ? $deliveryAddress->street . ', ' . $deliveryAddress->zipcode . ' ' . $deliveryAddress->city : '';
	}
	
	public function getBillingAddressLine(): ?string
	{
		$billingAddress = $this->billAddress;
		
		return $billingAddress ? $billingAddress->street . ', ' . $billingAddress->zipcode . ' ' . $billingAddress->city : '';
	}
	
	public function getId(): string
	{
		return $this->getPK();
	}
	
	/**
	 * @return array<string>
	 */
	public function getRoles(): array
	{
		return [];
	}
	
	public function getAccount(): ?Account
	{
		return $this->account;
	}
	
	public function setAccount(Account $account): void
	{
		$this->account = $account;
	}
	
	public function getCatalogPermission(): ?CatalogPermission
	{
		if ($this->catalogPermission !== false) {
			return $this->catalogPermission;
		}

		/** @var \Eshop\DB\CatalogPermission|null $perm */
		$perm = $this->getValue('account') ?
			$this->getConnection()->findRepository(CatalogPermission::class)->one(['fk_customer' => $this->getPK(), 'fk_account' => $this->getValue('account')], false) :
			null;

		return $this->catalogPermission = $perm;
	}
	
	public function isCompany(): bool
	{
		return (bool) $this->company;
	}
	
	public function getName(): string
	{
		return (string) ($this->company ?: $this->fullname);
	}

	public function getPreferredMutation(): ?string
	{
		return $this->account && $this->account->getPreferredMutation() ? $this->account->getPreferredMutation() : $this->preferredMutation;
	}

	public function getLoyaltyProgramPoints(): ?float
	{
		if ($this->loyaltyProgram === null) {
			return null;
		}

		if ($this->loyaltyProgram->histories->count() === 0) {
			return 0.0;
		}

		/** @var \Eshop\DB\LoyaltyProgramHistory|null $points */
		$points = $this->loyaltyProgram->histories->match(['fk_customer' => $this->getPK()])->select(['totalPoints' => 'SUM(points)'])->first();

		return $points ? \floatval($points->getValue('totalPoints')) : 0;
	}
	
	public function getAvailableCredit(Currency $currency): ?float
	{
		/** @var \Eshop\DB\RewardMoveRepository $repository */
		$repository = $this->getConnection()->findRepository(RewardMove::class);
		
		return $repository->many()
			->where('applied = 0 OR ((validFrom >= NOW() OR validFrom IS NULL) AND (validTo <= NOW() OR validTo IS NULL))')
			->where('fk_currency', $currency->getPK())
			->where('fk_customer', $this->getPK())
			->sum('price');
	}
	
	public function getAvailableCreditVat(Currency $currency): ?float
	{
		/** @var \Eshop\DB\RewardMoveRepository $repository */
		$repository = $this->getConnection()->findRepository(RewardMove::class);
		
		return $repository->many()
			->where('applied = 0 OR ((validFrom >= NOW() OR validFrom IS NULL) AND (validTo <= NOW() OR validTo IS NULL))')
			->where('fk_currency', $currency->getPK())
			->where('fk_customer', $this->getPK())
			->sum('priceVat');
	}
	
	/**
	 * Returns aggregated product, productAmount, price, priceVat
	 */
	public function getAvailableProductReward(): Collection
	{
		/** @var \Eshop\DB\RewardMoveRepository $repository */
		$repository = $this->getConnection()->findRepository(RewardMove::class);
		
		return $repository->many()
			->select(['product' => 'fk_product', 'amount' => 'SUM(productAmount)', 'price' => 'SUM(price * productAmount)', 'priceVat' => 'SUM(priceVat * productAmount)'])
			->where('applied = 0 OR ((validFrom >= NOW() OR validFrom IS NULL) AND (validTo <= NOW() OR validTo IS NULL))')
			->where('fk_customer', $this->getPK())
			->setGroupBy(['fk_product'], 'SUM(productAmount) > 0');
	}

	/**
	 * @return array<mixed>|\StORM\Collection
	 */
	public function getMyChildUsers(): array|\StORM\Collection
	{
		if ($this->isAffiliateTree()) {
			return $this->getMyTreeUsers();
		}

		if ($this->isAffiliateDirect()) {
			return $this->getMyDirectUsers();
		}

		return [];
	}

	public function getMyDirectUsers(): Collection
	{
		/** @var \Eshop\DB\CustomerRepository $repository */
		$repository = $this->getConnection()->findRepository(Customer::class);

		return $repository->many()
			->where('fk_parentCustomer', $this->getPK())
			->orderBy(['createdTs' => 'DESC']);
	}

	public function getMyTreeUsers(): Collection
	{
		/** @var \Eshop\DB\CustomerRepository $repository */
		$repository = $this->getConnection()->findRepository(Customer::class);

		return $repository->many()
			->where('fk_leadCustomer', $this->getPK())
			->orderBy(['createdTs' => 'DESC']);
	}

	public function isAffiliateTree(): bool
	{
		return $this->customerRole && $this->customerRole->isAffiliateTree();
	}

	public function isAffiliateDirect(): bool
	{
		return $this->customerRole && $this->customerRole->isAffiliateDirect();
	}

	/**
	 * Vraci provizi jakou uzivatel dostane z objednavky
	 * @param \Eshop\DB\Order $order
	 */
	public function getProvisionAmount(Order $order): float|int
	{
		$provision = 0;

		//pokud jde o opakovanou objednavku (RAYS CLUB)
		if ($order->autoship) {
			if ($this->customerRole->raysClubRepeatProvisionPct) {
				$provision += $order->purchase->getSumPriceVat() * $this->customerRole->raysClubRepeatProvisionPct / 100;
			}
		} elseif ($order->isFirstOrder() && $this->customerRole->firstProvisionPct > 0) {
			//pokud se jedna o prvni objednavku uzivatele a je nastavena specialni provize za prvni objednavku, pripisujeme tuto provizi
			$provision += $order->purchase->getSumPriceVat() * $this->customerRole->firstProvisionPct / 100;
		} else {
			//procentualni provize
			if ($this->customerRole->provisionPct) {
				$provision += $order->purchase->getSumPriceVat() * $this->customerRole->provisionPct / 100;
			}

			//fixni provize CZK
			if ($this->customerRole->provisionCzk > 0) {
				$provision += $this->customerRole->provisionCzk;
			}
		}

		return $provision;
	}

	/**
	 * vrati na kolik darku zdarma ma uzivatel pravo jako provizi za prvni objednavku
	 * @param \Eshop\DB\Order $order
	 */
	public function getRewardAmount(Order $order): int
	{
		if ($order->isFirstOrder() && $this->customerRole->provisionGift === 'yes') {
			return 1;
		}

		if ($order->isFirstOrder() && $this->customerRole->provisionGift === 'autoship' && $order->autoship) {
			return 1;
		}

		return 0;
	}

	public function addProvision($reason, $amount, $withVat = true, ?Currency $currency = null): void
	{
		/** @var \Eshop\DB\RewardMoveRepository $repository */
		$repository = $this->getConnection()->findRepository(RewardMove::class);

		$reward = [
			'reason' => $reason,
			'price' => isset($currency) && !$withVat ? $amount : null,
			'priceVat' => isset($currency) && $withVat ? $amount : null,
			'productAmount' => !isset($currency) ? $amount : null,
			'customer' => $this,
			'currency' => $currency,
			'createdTs' => Carbon::now()->format('Y-m-d H:i:s'),
		];

		$repository->createOne($reward);
	}

	/**
	 * pripise uzivateli provizi z objednavky
	 * @param \Eshop\DB\Order $order
	 */
	public function addOrderProvision(Order $order): void
	{
		$provision = $this->getProvisionAmount($order);

		if ($provision <= 0) {
			return;
		}

		$email = $order->purchase->email;

		$this->addProvision(
			"Provize za objednávku id. $order->id, uživatele $email",
			$provision,
			true,
			$order->purchase->currency,
		);
	}

	/**
	 * pripise uzivateli darek na ktery ma z objednavky narok
	 * @param \Eshop\DB\Order $order
	 */
	public function addOrderReward(Order $order): void
	{
		$rewards = $this->getRewardAmount($order);

		if ($rewards <= 0) {
			return;
		}

		$email = $order->purchase->email;

		$this->addProvision(
			"Dárek za objednávku id. $order->id, uživatele $email",
			$rewards,
		);
	}
}
