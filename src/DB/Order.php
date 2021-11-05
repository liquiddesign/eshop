<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Objednávka
 * @table
 * @index{"name":"order_code","unique":true,"columns":["code"]}
 */
class Order extends \StORM\Entity
{
	public const STATE = [
		'open',
		'received',
		'finished',
		'canceled',
	];

	/**
	 * Kód
	 * @column
	 */
	public string $code;

	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * Obdržena
	 * @column{"type":"timestamp"}
	 */
	public ?string $receivedTs;

	/**
	 * Zpracována
	 * @column{"type":"timestamp"}
	 */
	public ?string $processedTs;

	/**
	 * Uzavřena
	 * @column{"type":"timestamp"}
	 */
	public ?string $completedTs;

	/**
	 * Zrušeno
	 * @column{"type":"timestamp"}
	 */
	public ?string $canceledTs;

	/**
	 * Odesláno do systému zásilkovny
	 * @column
	 */
	public bool $zasilkovnaCompleted = false;

	/**
	 * Nákup
	 * @relation
	 * @constraint{"onUpdate":"RESTRICT","onDelete":"RESTRICT"}
	 */
	public Purchase $purchase;
	
	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Package>|\Eshop\DB\Package[]
	 */
	public RelationCollection $packages;

	/**
	 * Platby
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Payment>|\Eshop\DB\Payment[]
	 */
	public RelationCollection $payments;

	/**
	 * Dopravy
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Delivery>|\Eshop\DB\Delivery[]
	 */
	public RelationCollection $deliveries;
	
	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\Comgate>|\Eshop\DB\Comgate[]
	 */
	public RelationCollection $comgate;

	public function getDeliveryPriceSum(): float
	{
		return $this->deliveries->sum('price');
	}

	public function getDeliveryPriceVatSum(): float
	{
		return $this->deliveries->sum('priceVat');
	}

	public function getPaymentPriceSum(): float
	{
		return $this->payments->sum('price');
	}

	public function getPaymentPriceVatSum(): float
	{
		return $this->payments->sum('priceVat');
	}

	public function getTotalPrice(): float
	{
		return $this->purchase->getSumPrice() + $this->getDeliveryPriceSum() + $this->getPaymentPriceSum() - $this->getDiscountPrice();
	}

	public function getTotalPriceVat(): float
	{
		return $this->purchase->getSumPriceVat() + $this->getDeliveryPriceVatSum() + $this->getPaymentPriceVatSum() - $this->getDiscountPriceVat();
	}

	public function getDiscountPrice(): float
	{
		if ($coupon = $this->purchase->coupon) {
			if ($coupon->discountPct) {
				return \floatval($this->purchase->getSumPrice() * $coupon->discountPct / 100);
			}

			return \floatval($coupon->discountValue);
		}

		return 0.0;
	}

	public function getDiscountPriceVat(): float
	{
		if ($coupon = $this->purchase->coupon) {
			if ($coupon->discountPct) {
				return \floatval($this->purchase->getSumPriceVat() * $coupon->discountPct / 100);
			}

			return \floatval($coupon->discountValueVat);
		}

		return 0.0;
	}

	public function isCompany(): bool
	{
		return (bool)$this->ic;
	}

	/**
	 * @deprecated Use repository method getState()
	 */
	public function getState(): string
	{
		return $this->completedTs || $this->canceledTs ? 'Vyřízeno' : 'Zpracovává se';
	}

	/**
	 * @return \Eshop\DB\CartItem[]
	 */
	public function getGroupedItems(): array
	{
		$grouped = [];

		foreach ($this->purchase->getItems() as $item) {
			if (isset($grouped[$item->getFullCode()])) {
				$grouped[$item->getFullCode()]->amount += $item->amount;
			} else {
				$grouped[$item->getFullCode()] = $item;
			}
		}

		return $grouped;
	}

	public function getPayment(): ?Payment
	{
		$payments = clone $this->payments;

		return $payments->orderBy(['createdTs' => 'DESC'])->first();
	}

	public function getLastDelivery(): ?Delivery
	{
		$deliveries = clone $this->deliveries;

		return $deliveries->orderBy(['createdTs' => 'DESC'])->first();
	}

	public function getDiscountCoupon(): ?DiscountCoupon
	{
		return $this->purchase->coupon;
	}
}
