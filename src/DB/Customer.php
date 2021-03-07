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
	 * Slevová hladina
	 * @column
	 */
	public int $discountLevelPct = 0;
	
	/**
	 * Zokrouhlení od procent
	 * @column
	 */
	public ?int $productRoundingPct = null;
	
	/**
	 * Obchodník
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Merchant $merchant;
	
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
	 */
	public bool $newsletter = false;
	
	/**
	 * Body
	 * @column
	 */
	public ?int $points;
	
	/**
	 * Ukazovat ceny s DPH
	 * @column
	 */
	public bool $pricesWithVat = false;
	
	/**
	 * Oprávnění: katalog
	 * @column{"type":"enum","length":"'none','catalog','price','full'"}
	 */
	public string $catalogPermission = 'full';
	
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
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Account $account;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
	
	public function getDeliveryAddressLine(): ?string
	{
		$deliveryAddress = $this->deliveryAddress;
		
		return $deliveryAddress ? $deliveryAddress->street. ', ' . $deliveryAddress->zipcode . ' ' . $deliveryAddress->city : '';
	}
	
	public function getBillingAddressLine(): ?string
	{
		$billingAddress = $this->billAddress;
		
		return $billingAddress ? $billingAddress->street. ', ' . $billingAddress->zipcode . ' ' . $billingAddress->city : '';
	}
	
	public function getId(): string
	{
		return $this->getValue('account');
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
	
	public function isCompany(): bool
	{
		return (bool) $this->ic;
	}
	
	public function __call($name, $arguments)
	{
		// TODO: Implement @method array getData()
	}
}
