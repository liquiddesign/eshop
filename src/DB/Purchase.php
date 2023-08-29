<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Utils\Strings;
use Security\DB\Account;
use StORM\Collection;
use StORM\RelationCollection;

/**
 * Nákup
 * @method \StORM\RelationCollection<\Eshop\DB\Cart> getCarts()
 * @table
 */
class Purchase extends \StORM\Entity
{
	/**
	 * Jméno zákazníka / firma
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
	 * Jméno účtu zákazníka
	 * @column
	 */
	public ?string $accountFullname;

	/**
	 * Email účtu
	 * @column
	 */
	public ?string $accountEmail = null;
	
	/**
	 * Telefon
	 * @column
	 */
	public ?string $phone;
	
	/**
	 * Email
	 * @column
	 */
	public ?string $email = null;
	
	/**
	 * Emaily s kopií
	 * @column
	 */
	public ?string $ccEmails;
	
	/**
	 * IČO
	 * @column
	 */
	public ?string $ic;
	
	/**
	 * DIČ
	 * @column
	 */
	public ?string $dic;
	
	/**
	 * Vytvořit účet?
	 * @column
	 */
	public bool $createAccount = false;
	
	/**
	 * Heslo k nově vytvořenému účtu
	 * @column
	 */
	public ?string $password;
	
	/**
	 * Posílat newslettery?
	 * @column
	 */
	public bool $sendNewsletters = false;

	/**
	 * Interní kod - zákaznické číslo
	 * @column
	 */
	public ?string $internalOrderCode;
	
	/**
	 * Požadované datum expedice
	 * @column{"type":"date"}
	 */
	public ?string $desiredShippingDate;

	/**
	 * Požadované datum doručení
	 * @column{"type":"date"}
	 */
	public ?string $desiredDeliveryDate;
	
	/**
	 * Poznámka
	 * @column{"type":"text"}
	 */
	public ?string $note;
	
	/**
	 * Sleva na nákup
	 * @column
	 */
	public int $discountPct = 0;

	/**
	 * Poznámka pro dopravce
	 * @column{"type":"text"}
	 */
	public ?string $deliveryNote;
	
	/**
	 * Počet balíků - má vliv na cenu
	 * @column
	 */
	public int $deliveryPackagesNo = 1;

	/**
	 * Interní poznámka
	 * @column
	 */
	public ?string $internalNote;

	/**
	 * Výdejní místo - ID
	 * @column
	 */
	public ?string $pickupPointId;

	/**
	 * Výdejní místo - jméno
	 * @column
	 */
	public ?string $pickupPointName;

	/**
	 * Výdejní místo
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?PickupPoint $pickupPoint;

	/**
	 * ID pobočky zásilkovny
	 * @deprecated use pickupPointId
	 * @column{"type":"text"}
	 */
	public ?string $zasilkovnaId;
	
	/**
	 * Fakturační adresa
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Address $billAddress;
	
	/**
	 * Doručovací adresa
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Address $deliveryAddress;
	
	/**
	 * Košíky
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Cart>
	 */
	public RelationCollection $carts;
	
	/**
	 * Vybraná doprava
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?DeliveryType $deliveryType;
	
	/**
	 * Vybraná platba
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?PaymentType $paymentType;
	
	/**
	 * Zákazník
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Customer $customer;

	/**
	 * Nadřazený zákazník který provedl nákup za skutečného zákazníka
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public Customer|null $parentCustomer = null;

	/**
	 * Účet
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Account $account;
	
	/**
	 * Obchodník
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Merchant $merchant;
	
	/**
	 * Aplikovaný kupón
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?DiscountCoupon $coupon;

	/**
	 * Měna
	 * @relation
	 * @constraint
	 */
	public Currency $currency;

	/**
	 * Sleva zákazníka
	 * @column
	 */
	public ?float $customerDiscountLevel;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

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
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Order>
	 */
	public RelationCollection $orders;

	/**
	 * @var array<string>
	 */
	private ?array $cartIds;
	
	public function getFirstName(): ?string
	{
		$array = \explode(' ', $this->fullname, 2);
		
		return isset($array[0]) ? Strings::trim($array[0]) : null;
	}
	
	public function getLastName(): ?string
	{
		$array = \explode(' ', $this->fullname, 2);
		
		return isset($array[1]) ? Strings::trim($array[1]) : null;
	}
	
	public function isCompany(): bool
	{
		return (bool) $this->ic;
	}
	
	public function updateCustomer(Customer $customer): void
	{
		$customer->update([
			'email' => $this->email,
			'fullname' => $this->fullname,
			'phone' => $this->phone,
			'ic' => $this->ic ?: null,
			'dic' => $this->dic ?: null,
			'bankAccount' => $this->bankAccount ?: null,
			'bankAccountCode' => $this->bankAccountCode ?: null,
			'bankSpecificSymbol' => $this->bankSpecificSymbol ?: null,
			'billAddress' => $this->billAddress ? $this->billAddress->toArray([], true, false, false) : null,
		]);
		
		if (!$this->deliveryAddress) {
			return;
		}

		$customer->update([
			'deliveryAddress' => $this->deliveryAddress->toArray([], true, false, false),
		]);
	}

	/**
	 * @return \StORM\Collection<\Eshop\DB\CartItem>
	 */
	public function getItems(): Collection
	{
		/** @var \Eshop\DB\CartItemRepository $cartItemRepository */
		$cartItemRepository = $this->getConnection()->findRepository(CartItem::class);

		return $cartItemRepository->getItems($this->getCartIds());
	}
	
	/**
	 * @return \StORM\Collection<\Eshop\DB\CartItem>
	 */
	public function getTopLevelItems(): Collection
	{
		return $this->getItems()->where('this.fk_upsell IS NULL');
	}
	
	public function getSumPriceVat(): float
	{
		/** @var \Eshop\DB\CartItemRepository $cartItemRepository */
		$cartItemRepository = $this->getConnection()->findRepository(CartItem::class);
		
		return $cartItemRepository->getSumProperty($this->getCartIds(), 'priceVat');
	}
	
	public function getSumPrice(): float
	{
		/** @var \Eshop\DB\CartItemRepository $cartItemRepository */
		$cartItemRepository = $this->getConnection()->findRepository(CartItem::class);
		
		return $cartItemRepository->getSumProperty($this->getCartIds(), 'price');
	}

	public function getSumPriceBeforeVat(): float
	{
		/** @var \Eshop\DB\CartItemRepository $cartItemRepository */
		$cartItemRepository = $this->getConnection()->findRepository(CartItem::class);

		return $cartItemRepository->getSumProperty($this->getCartIds(), 'priceVatBefore');
	}

	public function getSumPriceBefore(): float
	{
		/** @var \Eshop\DB\CartItemRepository $cartItemRepository */
		$cartItemRepository = $this->getConnection()->findRepository(CartItem::class);

		return $cartItemRepository->getSumProperty($this->getCartIds(), 'priceBefore');
	}
	
	public function getSumWeight(): float
	{
		/** @var \Eshop\DB\CartItemRepository $cartItemRepository */
		$cartItemRepository = $this->getConnection()->findRepository(CartItem::class);
		
		return $cartItemRepository->getSumProperty($this->getCartIds(), 'productWeight');
	}

	public function getSumDimension(): float
	{
		/** @var \Eshop\DB\CartItemRepository $cartItemRepository */
		$cartItemRepository = $this->getConnection()->findRepository(CartItem::class);

		return $cartItemRepository->getSumProperty($this->getCartIds(), 'productDimension');
	}
	
	public function getDeliveryTypeExternalId(Supplier $supplier): ?string
	{
		if (!$this->getValue('deliveryType')) {
			return null;
		}

		$supplierDeliveryTypeRepository = $this->getConnection()->findRepository(SupplierDeliveryType::class);

		/** @var \Eshop\DB\SupplierDeliveryType|null $supplierDeliveryType */
		$supplierDeliveryType = $supplierDeliveryTypeRepository->many()
			->where('this.fk_supplier', $supplier->getPK())
			->where('this.fk_deliveryType', $this->getValue('deliveryType'))
			->first();

		return $supplierDeliveryType->externalId ?? null;
	}

	public function getPaymentTypeExternalId(Supplier $supplier): ?string
	{
		if (!$this->getValue('paymentType')) {
			return null;
		}

		$supplierPaymentTypeRepository = $this->getConnection()->findRepository(SupplierPaymentType::class);

		/** @var \Eshop\DB\SupplierPaymentType $supplierPaymentType */
		$supplierPaymentType = $supplierPaymentTypeRepository->many()
			->where('this.fk_supplier', $supplier->getPK())
			->where('this.fk_paymentType', $this->getValue('paymentType'))
			->first();

		return $supplierPaymentType->externalId ?? ($this->paymentType->externalId ?? null);
	}

	public function getCustomerPrefferedMutation(): ?string
	{
		$accountMutation = null;

		if ($this->account) {
			if (!$accountMutation = $this->account->getPreferredMutation()) {
				if ($this->customer) {
					$accountMutation = $this->customer->getPreferredMutation();
				}
			}
		}

		return $accountMutation;
	}

	public function getPickupPointId(): ?string
	{
		if ($this->pickupPointId) {
			return $this->pickupPoint ? $this->pickupPoint->code : $this->pickupPointId;
		}

		if ($this->zasilkovnaId) {
			return $this->pickupPoint ? $this->pickupPoint->code : $this->zasilkovnaId;
		}

		return null;
	}

	public function getPickupPointName(): ?string
	{
		if ($this->pickupPointId) {
			return $this->pickupPoint ? $this->pickupPoint->name : $this->pickupPointName;
		}

		if ($this->zasilkovnaId) {
			return $this->pickupPoint ? $this->pickupPoint->name : $this->zasilkovnaId;
		}

		return null;
	}

	/**
	 * @return array<string>
	 */
	private function getCartIds(): array
	{
		return $this->cartIds ??= $this->carts->select(['uuid'])->toArrayOf('uuid', [], true);
	}
}
