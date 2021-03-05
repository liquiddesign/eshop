<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Collection;
use StORM\RelationCollection;

/**
 * Nákup
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
	 * Interní kod
	 * @column
	 */
	public ?string $internalOrderCode;
	
	/**
	 * Požadované datum expedice
	 * @column{"type":"date"}
	 */
	public ?string $desiredShippingDate;
	
	/**
	 * Poznámka
	 * @column{"type":"text"}
	 */
	public ?string $note;
	
	/**
	 * Fakturační adresa
	 * @relation
	 * @constraint
	 */
	public ?Address $billAddress;
	
	/**
	 * Doručovací adresa
	 * @relation
	 * @constraint
	 */
	public ?Address $deliveryAddress;
	
	/**
	 * Košíky
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Cart>|\Eshop\DB\Cart[]
	 */
	public RelationCollection $carts;
	
	/**
	 * Vybraná doprava
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?DeliveryType $deliveryType;
	
	/**
	 * Vybraná platba
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?PaymentType $paymentType;
	
	/**
	 * Zákazník
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Customer $customer;
	
	/**
	 * Obchodník
	 * @relation
	 * @constraint{"onUpdate":"SET NULL","onDelete":"SET NULL"}
	 */
	public ?Merchant $merchant;
	
	/**
	 * Aplikovaný kupón
	 * @relation
	 * @constraint
	 */
	public ?DiscountCoupon $coupon;
	
	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * @var string[]
	 */
	private ?array $cartIds;
	
	public function isCompany(): bool
	{
		return (bool) $this->ic;
	}
	
	/**
	 * @return \StORM\Collection|\Eshop\DB\CartItem[]
	 */
	public function getItems(): Collection
	{
		/** @var \Eshop\DB\CartItemRepository $cartItemRepository */
		$cartItemRepository = $this->getConnection()->findRepository(CartItem::class);
		
		return $cartItemRepository->getItems($this->getCartIds());
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
	
	public function getSumWeight(): float
	{
		/** @var \Eshop\DB\CartItemRepository $cartItemRepository */
		$cartItemRepository = $this->getConnection()->findRepository(CartItem::class);
		
		return $cartItemRepository->getSumProperty($this->getCartIds(), 'productWeight');
	}
	
	/**
	 * @return string[]
	 */
	private function getCartIds(): array
	{
		return $this->cartIds ??= $this->carts->select(['uuid'])->toArrayOf('uuid', [], true);
	}
}
