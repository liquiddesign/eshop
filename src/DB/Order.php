<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Utils\DateTime;
use StORM\Collection;
use StORM\RelationCollection;

/**
 * Objednávka
 * @method \StORM\RelationCollection<\Eshop\DB\Package> getPackages()
 * @table
 * @index{"name":"order_code","unique":true,"columns":["code"]}
 */
class Order extends \StORM\Entity
{
	public const STATE_OPEN = 'open';
	public const STATE_RECEIVED = 'received';
	public const STATE_COMPLETED = 'finished';
	public const STATE_CANCELED = 'canceled';
	
	/**
	 * Id
	 * @column{"autoincrement":true}
	 */
	public int $id;
	
	/**
	 * Kód
	 * @column
	 */
	public string $code;
	
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
	 * Zablokováno
	 * @column{"type":"timestamp"}
	 */
	public ?string $bannedTs;
	
	/**
	 * Odesláno do systému zásilkovny
	 * @column
	 */
	public bool $zasilkovnaCompleted = false;

	/**
	 * DPD kód
	 * @column
	 */
	public ?string $dpdCode;

	/**
	 * DPD vytištěno
	 * @column
	 */
	public bool $dpdPrinted = false;

	/**
	 * PPL chyba
	 * @column
	 */
	public bool $dpdError = false;

	/**
	 * PPL kód
	 * @column
	 */
	public ?string $pplCode;

	/**
	 * PPL vytištěno
	 * @column
	 */
	public bool $pplPrinted = false;

	/**
	 * PPL chyba
	 * @column
	 */
	public bool $pplError = false;

	/**
	 * Edited manually in Admin
	 * @column
	 */
	public bool $manuallyEdited = false;

	/**
	 * @column
	 */
	public ?string $eHubVisitId;

	/**
	 * @column
	 */
	public bool $newCustomer = false;
	
	/**
	 * @column
	 */
	public bool $highlighted = false;
	
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
	
	/**
	 * Faktury
	 * @relationNxN{"sourceViaKey":"fk_order","targetViaKey":"fk_invoice","via":"eshop_invoice_nxn_eshop_order"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Invoice>
	 */
	public RelationCollection $invoices;
	
	public function getDeliveryPriceSum(): float
	{
		return $this->deliveries->sum('price');
	}
	
	public function getDeliveryPriceVatSum(): float
	{
		return $this->deliveries->sum('priceVat');
	}

	public function getDeliveryDiscountPriceSum(): float
	{
		$beforePrice = null;
		$price = null;

		foreach ($this->deliveries as $delivery) {
			$delivery->priceBefore ? $beforePrice += $delivery->priceBefore : $beforePrice += $delivery->price;
			$price += $delivery->price;
		}

		return $beforePrice - $price;
	}

	public function getDeliveryDiscountPriceVatSum(): float
	{
		$beforePrice = null;
		$price = null;

		foreach ($this->deliveries as $delivery) {
			$delivery->priceBefore ? $beforePrice += $delivery->priceVatBefore : $beforePrice += $delivery->priceVat;
			$price += $delivery->priceVat;
		}

		return $beforePrice - $price;
	}

	public function getDeliveriesDiscountLevel(): float
	{
		$beforePrice = null;
		$price = null;

		foreach ($this->deliveries as $delivery) {
			$delivery->priceBefore ? $beforePrice += $delivery->priceBefore : $beforePrice += $delivery->price;
			$price += $delivery->price;
		}

		return $beforePrice > 0 ? 100 - ($price / $beforePrice * 100) : 0.0;
	}

	public function getDeliveriesDiscountLevelVat(): float
	{
		$beforePrice = null;
		$price = null;

		foreach ($this->deliveries as $delivery) {
			$delivery->priceBefore ? $beforePrice += $delivery->priceVatBefore : $beforePrice += $delivery->priceVat;
			$price += $delivery->priceVat;
		}

		return $beforePrice > 0 ? 100 - ($price / $beforePrice * 100) : 0.0;
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
		return (bool)$this->getValue('ic');
	}
	
	public function getState(): string
	{
		/** @var \Eshop\DB\OrderRepository $repository */
		$repository = $this->getRepository();
		
		return $repository->getState($this);
	}
	
	public function getId(int $length): string
	{
		return \str_pad((string) $this->id, $length, '0', \STR_PAD_LEFT);
	}
	
	public function getYear(): int
	{
		$created = DateTime::from((int) $this->createdTs);
		
		return (int) $created->format('Y');
	}
	
	public function getIdByYear(int $length): string
	{
		$maxIdLastYear = $this->getRepository()->many()
			->where('YEAR(createdTs) < :year', ['year' => $this->getYear()])
			->orderBy(['id' => 'DESC'])
			->firstValue('id');
		
		$id = $maxIdLastYear ? $this->id - (int) $maxIdLastYear : $this->id;
		
		return \str_pad((string) $id, $length, '0', \STR_PAD_LEFT);
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
		/** @var \StORM\Collection<\Eshop\DB\Payment> $payments */
		$payments = clone $this->payments;
		
		return $payments->orderBy(['createdTs' => 'DESC'])->first();
	}
	
	public function getLastDelivery(): ?Delivery
	{
		/** @var \StORM\Collection<\Eshop\DB\Delivery> $deliveries */
		$deliveries = clone $this->deliveries;
		
		return $deliveries->orderBy(['createdTs' => 'DESC'])->first();
	}
	
	public function getDiscountCoupon(): ?DiscountCoupon
	{
		return $this->purchase->coupon;
	}

	/**
	 * @return \StORM\Collection<\Eshop\DB\Invoice>
	 * @deprecated use property
	 */
	public function getInvoices(): Collection
	{
		$invoiceRepository = $this->getConnection()->findRepository(Invoice::class);

		return $invoiceRepository->many()->where('orders.uuid', $this->getPK());
	}

	public function getDpdCode(): ?string
	{
		return $this->dpdError ? null : $this->dpdCode;
	}

	public function getPplCode(): ?string
	{
		return $this->pplError ? null : $this->pplCode;
	}
}
