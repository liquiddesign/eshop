<?php

namespace Eshop\DB;

use Base\DB\Shop;
use Carbon\Carbon;
use StORM\Entity;
use StORM\RelationCollection;

/**
 * @table
 * @index{"name":"code","unique":true,"columns":["code"]}
 * @index{"name":"order","unique":true,"columns":["fk_order"]}
 */
class Offer extends Entity
{
	/**
	 * @column
	 */
	public string $code;

	/**
	 * Vytvořena
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string|null $sentTs = null;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string|null $approvedTs = null;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string|null $completedTs = null;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string|null $canceledTs = null;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string|null $validFromTs = null;

	/**
	 * @column{"type":"timestamp"}
	 */
	public string|null $validUntilTs = null;

	/**
	 * Požádáno o schválení managerem
	 * @column{"type":"timestamp"}
	 */
	public string|null $managerApprovalRequestedTs = null;

	/**
	 * Schváleno managerem
	 * @column{"type":"timestamp"}
	 */
	public string|null $managerApprovedTs = null;

	/**
	 * Typ nabídky (normal/ckp)
	 * @column{"type":"enum","length":"'normal','ckp'","default":"'normal'"}
	 */
	public string $offerType = 'normal';

	/**
	 * Sdílená s dalšími zákazníky
	 * @column{"type":"tinyint","default":"0"}
	 */
	public bool $isShared = false;

	/**
	 * Poznámka
	 * @column{"type":"text"}
	 */
	public ?string $internalNote;

	/**
	 * Poznámka obchodníka pro zákazníka
	 * @column{"type":"longtext"}
	 */
	public string|null $note = null;

	/**
	 * Id v pipedrive
	 * @column
	 */
	public string|null $pipedriveDealId = null;

	/**
	 * Zaokrouhlování
	 * @column
	 */
	public float|null $roundingTo = null;

	/**
	 * Celé jméno zákazníka
	 * @column
	 */
	public string|null $fullname = null;

	/**
	 * Email zákazníka
	 * @column
	 */
	public string|null $email = null;

	/**
	 * Telefon zákazníka
	 * @column
	 */
	public string|null $phone = null;

	/**
	 * CC emaily
	 * @column
	 */
	public string|null $ccEmails = null;

	/**
	 * IČO
	 * @column
	 */
	public string|null $ic = null;

	/**
	 * DIČ
	 * @column
	 */
	public string|null $dic = null;

	/**
	 * Email účtu
	 * @column
	 */
	public string|null $accountEmail = null;

	/**
	 * Kontaktní osoba
	 * @column
	 */
	public string|null $contactName = null;

	/**
	 * Cena dopravy v čase vytvoření nabídky
	 * @column
	 */
	public float|null $deliveryPrice = null;

	/**
	 * Cena dopravy s DPH v čase vytvoření nabídky
	 * @column
	 */
	public float|null $deliveryPriceVat = null;

	/**
	 * Cena platby v čase vytvoření nabídky
	 * @column
	 */
	public float|null $paymentPrice = null;

	/**
	 * Cena platby s DPH v čase vytvoření nabídky
	 * @column
	 */
	public float|null $paymentPriceVat = null;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public Shop|null $shop = null;

	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Order|null $order = null;

	/**
	 * Zákazník
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public Customer|null $customer = null;

	/**
	 * Obchodník
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public Merchant|null $merchant = null;

	/**
	 * Dodávající obchodník
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public Merchant|null $deliveringMerchant = null;

	/**
	 * Účet
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public \Security\DB\Account|null $account = null;

	/**
	 * Měna
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"RESTRICT"}
	 */
	public Currency|null $currency = null;

	/**
	 * Fakturační adresa
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public Address|null $billAddress = null;

	/**
	 * Doručovací adresa
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public Address|null $deliveryAddress = null;

	/**
	 * Typ dopravy
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public DeliveryType|null $deliveryType = null;

	/**
	 * Typ platby
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public PaymentType|null $paymentType = null;

	/**
	 * Položky nabídky
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\OfferItem>
	 */
	public RelationCollection $offerItems;

	/**
	 * Sdílení zákazníci (NxN)
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Customer>
	 */
	public RelationCollection $sharedCustomers;

	/**
	 * Sdílení dle IČ (NxN)
	 * @relationNxN{"via":"abel_offer_nxn_shared_by_ic","sourceViaKey":"fk_offer","targetViaKey":"fk_customer"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Customer>
	 */
	public RelationCollection $sharedByIcCustomers;

	/**
	 * Sdílení dle CKP (NxN)
	 * @relationNxN{"via":"abel_offer_nxn_shared_by_ckp","sourceViaKey":"fk_offer","targetViaKey":"fk_customer"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Customer>
	 */
	public RelationCollection $sharedByCkpCustomers;

	public function getOfferType(): OfferType
	{
		return OfferType::from($this->offerType);
	}

	public function isCkp(): bool
	{
		return $this->offerType === OfferType::Ckp->value;
	}

	public function isExpired(Carbon $now): bool
	{
		$validFrom = $this->validFromTs ? Carbon::parse($this->validFromTs) : null;
		$validUntil = $this->validUntilTs ? Carbon::parse($this->validUntilTs) : null;

		if ($validFrom && $now->lt($validFrom)) {
			return true;
		}

		return $validUntil && $now->gte($validUntil);
	}

	/**
	 * @return \StORM\RelationCollection<\Eshop\DB\OfferItem>
	 */
	public function getItems(): RelationCollection
	{
		return $this->offerItems;
	}

	/**
	 * Součet cen všech položek bez DPH
	 */
	public function getSumPrice(): float
	{
		$sum = 0.0;

		foreach ($this->getItems() as $item) {
			$sum += $item->getPriceSum();
		}

		return $sum;
	}

	/**
	 * Součet cen všech položek s DPH
	 */
	public function getSumPriceVat(): float
	{
		$sum = 0.0;

		foreach ($this->getItems() as $item) {
			$vatSum = $item->getPriceVatSum();

			if ($vatSum === null) {
				continue;
			}

			$sum += $vatSum;
		}

		return $sum;
	}

	/**
	 * Součet vah všech položek
	 */
	public function getSumWeight(): float
	{
		$sum = 0.0;

		foreach ($this->getItems() as $item) {
			$sum += ($item->productWeight ?? 0.0) * $item->amount;
		}

		return $sum;
	}

	/**
	 * Celková cena bez DPH (položky + doprava + platba)
	 */
	public function getTotalPrice(): float
	{
		return $this->getSumPrice() + ($this->deliveryPrice ?? 0.0) + ($this->paymentPrice ?? 0.0);
	}

	/**
	 * Celková cena s DPH (položky + doprava + platba)
	 */
	public function getTotalPriceVat(): float
	{
		return $this->getSumPriceVat()
			+ ($this->deliveryPriceVat ?? $this->deliveryPrice ?? 0.0)
			+ ($this->paymentPriceVat ?? $this->paymentPrice ?? 0.0);
	}
}
