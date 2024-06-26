<?php

declare(strict_types=1);

namespace Eshop\DB;

use Carbon\Carbon;
use StORM\RelationCollection;

/**
 * Faktury
 * @table
 * @index{"name":"invoice_codehash","unique":true,"columns":["code", "hash"]}
 * @method \StORM\RelationCollection<\Eshop\DB\InvoiceItem> getItems()
 */
class Invoice extends \StORM\Entity
{
	/**
	 * ID
	 * column - don't created by auto migration, only by manual
	 */
	public int $id;

	/**
	 * Vytištěno
	 * @column
	 */
	public bool $printed = false;

	/**
	 * Kód
	 * @column
	 */
	public ?string $code;

	/**
	 * Vygenerovaný hash pro externí přístup
	 * @column
	 */
	public ?string $hash;

	/**
	 * Subject
	 * @column
	 */
	public ?string $subject;

	/**
	 * @column
	 */
	public ?string $phone;

	/**
	 * @column
	 */
	public ?string $email;

	/**
	 * IČ
	 * @column
	 */
	public ?string $ic;

	/**
	 * DIČ
	 * @column
	 */
	public ?string $dic;

	/**
	 * Vystaveno
	 * @column{"type":"date"}
	 */
	public string $exposed;

	/**
	 * Datum zdanitelného plnění
	 * @column{"type":"date"}
	 */
	public ?string $taxDate = null;

	/**
	 * Datum splatnosti
	 * @column{"type":"date"}
	 */
	public ?string $dueDate = null;

	/**
	 * Zaplaceno
	 * @column{"type":"date"}
	 */
	public ?string $paidDate = null;

	/**
	 * Stornovano
	 * @column{"type":"date"}
	 */
	public ?string $canceled = null;

	/**
	 * Cena k úhradě
	 * @column
	 */
	public float $totalPrice;

	/**
	 * Celková cena s daní
	 * @column
	 */
	public float $totalPriceVat;

	/**
	 * Cena k úhradě
	 * @column
	 */
	public float $totalPriceWithoutDiscount;

	/**
	 * Celková cena s daní
	 * @column
	 */
	public float $totalPriceVatWithoutDiscount;

	/**
	 * Uhrazeno částka
	 * @column
	 */
	public ?float $paid;

	/**
	 * Variabilni symbol
	 * @column
	 */
	public ?string $variableSymbol = null;

	/**
	 * Konstantní symbol
	 * @column
	 */
	public ?string $constantSymbol = null;

	/**
	 * Měna
	 * @constraint{"onUpdate":"RESTRICT","onDelete":"RESTRICT"}
	 * @relation
	 */
	public Currency $currency;

	/**
	 * Adresa
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Address $address;

	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 * @relation
	 */
	public ?Customer $customer;

	/**
	 * Vybraná platba
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?PaymentType $paymentType;
	
	/**
	 * Objednávky
	 * @relationNxN{"sourceViaKey":"fk_invoice","targetViaKey":"fk_order","via":"eshop_invoice_nxn_eshop_order"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Order>
	 */
	public RelationCollection $orders;

	/**
	 * Položky
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\InvoiceItem>
	 */
	public RelationCollection $items;
	
	public function getDaysFromDue(): ?int
	{
		if (!$this->dueDate) {
			return null;
		}
		
		return Carbon::now()->diffInDays(Carbon::parse($this->dueDate), false);
	}

	/**
	 * @return array<mixed>
	 */
	public function getEmailVariables(): array
	{
		return [
			'code' => $this->code,
		];
	}

	/**
	 * @return array<\Eshop\DB\InvoiceItem>
	 */
	public function getGroupedItems(): array
	{
		$grouped = [];
		$groupedAmounts = [];

		/** @var \Eshop\DB\InvoiceItem $item */
		foreach ($this->items as $item) {
			if (isset($grouped[$item->getFullCode()])) {
				$groupedAmounts[$item->getFullCode()] += $item->amount;
			} else {
				$grouped[$item->getFullCode()] = $item;
				$groupedAmounts[$item->getFullCode()] = $item->amount;
			}
		}

		foreach ($grouped as $item) {
			$grouped[$item->getFullCode()]->amount = $groupedAmounts[$item->getFullCode()];
		}

		return $grouped;
	}

	/**
	 * @return array<array<\Eshop\DB\InvoiceItem>>
	 */
	public function getGroupedUpsells(): array
	{
		$grouped = [];

		/** @var \Eshop\DB\InvoiceItem $item */
		foreach ($this->items->clear(true)->where('fk_upsell IS NOT NULL') as $item) {
			$grouped[$item->getValue('upsell')][$item->getPK()] = $item;
		}

		return $grouped;
	}

	public function getTotalPrice(): float
	{
		try {
			$first = $this->items->clear(true)->select(['priceSum' => 'SUM(this.price)'])->first();

			return (float) $first->getValue('priceSum');
		} catch (\Throwable $e) {
			return 0;
		}
	}

	public function getTotalPriceVat(): float
	{
		try {
			$first = $this->items->clear(true)->select(['priceSum' => 'SUM(this.priceVat)'])->first();

			return (float) $first->getValue('priceSum');
		} catch (\Throwable $e) {
			return 0;
		}
	}

	/**
	 * @return array<array<float>>
	 */
	public function getGroupedVatPrices(): array
	{
		$basePrices = [];

		/** @var \Eshop\DB\InvoiceItem $invoiceItem */
		foreach ($this->items->clear(true) as $invoiceItem) {
			if (!$invoiceItem->vatPct) {
				continue;
			}

			isset($basePrices[$invoiceItem->vatPct]['base']) ?
				$basePrices[$invoiceItem->vatPct]['base'] += $invoiceItem->getPriceSum() :
				$basePrices[$invoiceItem->vatPct]['base'] = $invoiceItem->getPriceSum();

			isset($basePrices[$invoiceItem->vatPct]['vat']) ?
				$basePrices[$invoiceItem->vatPct]['vat'] += $invoiceItem->getPriceVatSum() - $invoiceItem->getPriceSum() :
				$basePrices[$invoiceItem->vatPct]['vat'] = $invoiceItem->getPriceVatSum() - $invoiceItem->getPriceSum();
		}

		/** @var \Eshop\DB\Order $order */
		foreach ($this->orders->clear(true) as $order) {
			if ($order->purchase->deliveryType) {
				$vatPct = $order->getDeliveryPriceSum() > 0 ? \round($order->getDeliveryPriceVatSum() / $order->getDeliveryPriceSum() * 100 - 100) : 0;

				if ($vatPct > 0) {
					isset($basePrices[$vatPct]['base']) ?
						$basePrices[$vatPct]['base'] += $order->getDeliveryPriceSum() :
						$basePrices[$vatPct]['base'] = $order->getDeliveryPriceSum();

					isset($basePrices[$vatPct]['vat']) ?
						$basePrices[$vatPct]['vat'] += $order->getDeliveryPriceVatSum() - $order->getDeliveryPriceSum() :
						$basePrices[$vatPct]['vat'] = $order->getDeliveryPriceVatSum() - $order->getDeliveryPriceSum();
				}
			}

			if (!$order->purchase->paymentType) {
				continue;
			}

			$vatPct = $order->getPaymentPriceSum() > 0 ? \round($order->getPaymentPriceVatSum() / $order->getPaymentPriceSum() * 100 - 100) : 0;

			if ($vatPct <= 0) {
				continue;
			}

			isset($basePrices[$vatPct]['base']) ?
				$basePrices[$vatPct]['base'] += $order->getPaymentPriceSum() :
				$basePrices[$vatPct]['base'] = $order->getPaymentPriceSum();

			isset($basePrices[$vatPct]['vat']) ?
				$basePrices[$vatPct]['vat'] += $order->getPaymentPriceVatSum() - $order->getPaymentPriceSum() :
				$basePrices[$vatPct]['vat'] = $order->getPaymentPriceVatSum() - $order->getPaymentPriceSum();
		}

		\ksort($basePrices);

		return $basePrices;
	}

	/**
	 * Get absolute price of discount coupons applied to orders, works only for invoice with one order
	 * Hotfix for Ketofit
	 */
	public function getAbsoluteDiscountCouponPriceVatRatio(): float
	{
		/** @var \Eshop\DB\Order|null $order */
		$order = $this->orders->clear(true)->first();

		if (!$order || !$order->getDiscountCoupon()) {
			return 1.0;
		}

		return $order->getTotalPriceVat() / ($order->getTotalPriceVat() + $order->getDiscountCoupon()->discountValueVat);
	}
}
