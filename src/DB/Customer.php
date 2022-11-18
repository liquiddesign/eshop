<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Security\IIdentity;
use Security\DB\Account;
use Security\DB\IUser;
use StORM\Collection;
use StORM\Entity;
use StORM\RelationCollection;

/**
 * Zákazník
 * @table
 * @index{"name":"customer_unique_email","unique":true,"columns":["email"]}
 * @method array getData()
 */
class Customer extends Entity implements IIdentity, IUser
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
	 * @constraint
	 * @relation
	 */
	public ?Address $billAddress;
	
	/**
	 * Dodací adresa
	 * @constraint
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
	 * @constraint
	 */
	public ?Customer $parentCustomer;
	
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
	 * @var \Eshop\DB\PaymentType[]|\StORM\RelationCollection<\Eshop\DB\PaymentType>
	 */
	public RelationCollection $exclusivePaymentTypes;
	
	/**
	 * Povolené exkluzivní dopravy
	 * @relationNxN
	 * @var \Eshop\DB\DeliveryType[]|\StORM\RelationCollection<\Eshop\DB\DeliveryType>
	 */
	public RelationCollection $exclusiveDeliveryTypes;
	
	/**
	 * Ceníky
	 * @relationNxN
	 * @var \Eshop\DB\Pricelist[]|\StORM\RelationCollection<\Eshop\DB\Pricelist>
	 */
	public RelationCollection $pricelists;
	
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
	 * Přihlášen k newsletteru
	 * @column
	 * @deprecated
	 */
	public bool $newsletter = false;

	/**
	 * Newsletter skupina
	 * @column
	 * @deprecated
	 */
	public ?string $newsletterGroup;
	
	/**
	 * Body
	 * @column
	 */
	public ?int $points;
	
	/**
	 * Ukazovat ceny s DPH
	 * @deprecated
	 * @column
	 */
	public bool $pricesWithVat = false;

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
	 * Datum odsouhlaseni obchodnich podminek
	 * @column{"type":"timestamp","default":null}
	 */
	public ?string $conditionsTs;

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
	 * @relationNxN{"via":"eshop_catalogpermission"}
	 * @var \StORM\RelationCollection<\Security\DB\Account>|\Security\DB\Account[]
	 */
	public RelationCollection $accounts;
	
	public ?Account $account = null;
	
	protected ?CatalogPermission $catalogPermission;
	
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
	 * @return string[]
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
		/** @var \Eshop\DB\CatalogPermission|null $perm */
		$perm = $this->getValue('account') ?
			$this->getConnection()->findRepository(CatalogPermission::class)->one(['fk_customer' => $this->getPK(), 'fk_account' => $this->getValue('account')], false) :
			null;

		return $this->catalogPermission ?? $perm;
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
	public function getAvailableProductReward(): int
	{
		/** @var \Eshop\DB\RewardMoveRepository $repository */
		$repository = $this->getConnection()->findRepository(RewardMove::class);

		$object = $repository->many()
			->select(['product' => 'fk_product', 'amount' => 'SUM(productAmount)', 'price' => 'SUM(price * productAmount)', 'priceVat' => 'SUM(priceVat * productAmount)'])
			->where('applied = 0 OR ((validFrom >= NOW() OR validFrom IS NULL) AND (validTo <= NOW() OR validTo IS NULL))')
			->where('fk_customer', $this->getPK())
			->setGroupBy(['fk_product'], 'SUM(productAmount) > 0')->first();

		return $object ? (int)$object->amount : 0;
	}

	/**
	 * @return array<mixed>|\StORM\Collection
	 */
	public function getMyChildUsers()
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
	 * @return float|int
	 */
	public function getProvisionAmount(Order $order)
	{
		if (!$this->hasActiveAutoship()) {
			return 0;
		}

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
		if (!$this->hasActiveAutoship()) {
			return 0;
		}

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
			'createdTs' => \date('Y-m-d H:i:s'),
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
			"Provize za objednávku id. $order->code, uživatele $email",
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
			"Dárek za objednávku id. $order->code, uživatele $email",
			$rewards,
		);
	}

	public function firstOrderDone(): bool
	{
		$orderRepository = $this->getConnection()->findRepository(Order::class);

		$firstOrder = $orderRepository->many()
			->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid')
			->where('purchase.fk_customer', $this)
			->where('canceledTs IS NULL')
			->first();

		return $firstOrder !== null;
	}

	public function getActualDiscountPct(): int
	{
		$discountPct = $this->getAllOrdersDiscountPct();

		if (!$this->firstOrderDone()) {
			$firstOrderDiscountPct = $this->getFirstOrderDiscountPct();

			return $firstOrderDiscountPct > $discountPct ? $firstOrderDiscountPct : $discountPct;
		}

		return $discountPct;
	}

	/**
	 * Ziska slevu na produkty, kombinuje slevu se slevou na prvni objednavku
	 * @return int
	 */
	/*public function getDiscountPct() {
		//moje sleva
		$discount = $this->getAllOrdersDiscountPct();
		$firstOrderDiscount = $this->getFirstOrderDiscountPct();

		return ($firstOrderDiscount > $discount) ? $firstOrderDiscount : $discount;
	}*/

	/**
	 * Ziska procentualni slevu na vsechny objednavky
	 */
	public function getAllOrdersDiscountPct(): int
	{
		if (!$this->customerRole) {
			return 0;
		}

		//moje sleva
		$myDiscount = $this->customerRole->discount;

		if ($this->leadCustomer) {
			if ($this->leadCustomer->customerRole->membersDiscountPct > $myDiscount) {
				$myDiscount = $this->leadCustomer->customerRole->membersDiscountPct;
			}
		}

		if ($this->parentCustomer) {
			if ($this->parentCustomer->customerRole->membersDiscountPct > $myDiscount) {
				$myDiscount = $this->parentCustomer->customerRole->membersDiscountPct;
			}
		}

		return $myDiscount;
	}

	/**
	 * Ziska procentualni slevu na prvni objednavku
	 */
	public function getFirstOrderDiscountPct(): int
	{

		$parentDiscount = 0;

		if ($this->leadCustomer) {
			$parentDiscount = $this->leadCustomer->customerRole->membersFirstOrderPct;
		}

		if ($this->parentCustomer) {
			if ($this->parentCustomer->customerRole->membersFirstOrderPct > $parentDiscount) {
				$parentDiscount = $this->parentCustomer->customerRole->membersFirstOrderPct;
			}
		}

		return $parentDiscount;
	}

	public function getFirstOrderDiscountFix(): float
	{
		$parentDiscount = 0;

		if ($this->leadCustomer) {
			$parentDiscount = $this->leadCustomer->customerRole->membersFirstOrderCzk;
		}

		if ($this->parentCustomer) {
			if ($this->parentCustomer->customerRole->membersFirstOrderCzk > $parentDiscount) {
				$parentDiscount = $this->parentCustomer->customerRole->membersFirstOrderCzk;
			}
		}

		return $parentDiscount;
	}

	public function updateActualDiscountLevel(): void
	{
		$this->update([
			'discountLevelPct' => $this->getActualDiscountPct(),
		]);
	}

	/**
	 * Zda má uživatel nějaký aktivní autoship
	 */
	public function hasActiveAutoship(): bool
	{
		$autoships = $this->getConnection()->findRepository(Autoship::class)
			->many()
			->where('active=1 AND activeFrom<NOW() AND purchase.fk_customer=:uuid', ['uuid' => $this->getPK()]);

		return (bool) \count($autoships);
	}

	public function conditionsConfirmed(): bool
	{
		return $this->conditionsTs !== null;
	}
}
