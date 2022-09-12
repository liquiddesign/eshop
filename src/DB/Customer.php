<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Security\IIdentity;
use Security\DB\Account;
use Security\DB\IUser;
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
		return (string) $this->company ?: $this->fullname;
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
}
