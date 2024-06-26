<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopSystemicEntity;
use DVDoug\BoxPacker;
use DVDoug\BoxPacker\Packer;
use StORM\RelationCollection;

/**
 * Typ dopravy
 * @table
 * @property float $priceVatWithCod
 * @index{"name":"deliverytype_codeshop","unique":true,"columns":["code", "fk_shop"]}
 */
class DeliveryType extends ShopSystemicEntity implements BoxPacker\Box
{
	public const IMAGE_DIR = 'deliverytype_images';
	
	/**
	 * Kód
	 * @column
	 */
	public string $code;
	
	/**
	 * Externí ID
	 * @column
	 */
	public ?string $externalId;

	/**
	 * Externí ID Heureka.cz
	 * @column
	 */
	public ?string $externalIdHeureka;

	/**
	 * Externí ID Zboží.cz
	 * @column
	 */
	public ?string $externalIdZbozi;
	
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
	/**
	 * Popisek
	 * @column{"type":"text","mutations":true}
	 */
	public ?string $perex;
	
	/**
	 * Instrukce (např. do emailu)
	 * @column{"type":"text","mutations":true}
	 */
	public ?string $instructions;
	
	/**
	 * Náhledový obrázek
	 * @column
	 */
	public ?string $imageFileName;
	
	/**
	 * Trakovací odkaz v printf formátu
	 * @column
	 */
	public ?string $trackingLink;
	
	/**
	 * Exportovat do feedu
	 * @column
	 */
	public bool $exportToFeed = false;
	
	/**
	 * Exclusivní pro skupiny uživatelů
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?CustomerGroup $exclusive;
	
	/**
	 * Priorita
	 * @column
	 */
	public int $priority = 10;
	
	/**
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;
	
	/**
	 * Doporučeno
	 * @column
	 */
	public bool $recommended = false;
	
	/**
	 * Externí dopravce
	 * @column
	 */
	public bool $externalCarrier = false;

	/**
	 * Max váha celé objednávky
	 * @column
	 */
	public ?float $totalMaxWeight;

	/**
	 * Max váha balíku
	 * @column
	 */
	public ?float $maxWeight;
	
	/**
	 * Max rozměr
	 * @column
	 */
	public ?float $maxDimension;
	
	/**
	 * Šířka
	 * @column
	 */
	public ?int $maxWidth;
	
	/**
	 * Délka
	 * @column
	 */
	public ?int $maxLength;
	
	/**
	 * Hloubka
	 * @column
	 */
	public ?int $maxDepth;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?DisplayDelivery $defaultDisplayDelivery;
	
	/**
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\PaymentType>
	 */
	public RelationCollection $allowedPaymentTypes;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\DeliveryTypePrice>
	 */
	public RelationCollection $deliveryTypePrices;

	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\SupplierDeliveryType>
	 */
	public RelationCollection $supplierDeliveryTypes;
	
	/**
	 * Výdejní typ
	 * @relation
	 * @constraint
	 */
	public ?PickupPointType $pickupPointType;
	
	private BoxPacker\PackedBoxList $boxesForItems;
	
	public function getRealPrice(): float
	{
		return $this->getValue('price') * ($this->getValue('packagesNo') ?? 1);
	}
	
	public function getRealPriceVat(): float
	{
		return $this->getValue('priceVat') * ($this->getValue('packagesNo') ?? 1);
	}
	
	public function getPackagesNo(): int
	{
		return $this->getValue('packagesNo') ?? 1;
	}
	
	/**
	 * @param array<\Eshop\DB\CartItem> $items
	 */
	public function getBoxesForItems(array $items): BoxPacker\PackedBoxList
	{
		if ($this->maxWeight === null && $this->maxDepth === null && $this->maxLength === null && $this->maxWidth === null) {
			$packedBox = new BoxPacker\PackedBoxList();
			$packedBox->insert(new BoxPacker\PackedBox($this, new BoxPacker\PackedItemList()));
			
			return $packedBox;
		}
		
		if (isset($this->boxesForItems)) {
			return $this->boxesForItems;
		}
		
		$packer = new Packer();
		$packer->addBox($this);
		
		foreach ($items as $item) {
			$packer->addItem($item, $item->amount);
		}
		
		return $this->boxesForItems = $packer->pack();
	}
	
	/**
	 * Reference for box type (e.g. SKU or description).
	 */
	public function getReference(): string
	{
		return $this->code ?: $this->name;
	}
	
	/**
	 * Outer width in mm.
	 */
	public function getOuterWidth(): int
	{
		return (int) $this->maxWidth;
	}
	
	/**
	 * Outer length in mm.
	 */
	public function getOuterLength(): int
	{
		return (int) $this->maxLength;
	}
	
	/**
	 * Outer depth in mm.
	 */
	public function getOuterDepth(): int
	{
		return (int) $this->maxDepth;
	}
	
	/**
	 * Empty weight in g.
	 */
	public function getEmptyWeight(): int
	{
		return 0;
	}
	
	/**
	 * Inner width in mm.
	 */
	public function getInnerWidth(): int
	{
		return (int) $this->maxWidth;
	}
	
	/**
	 * Inner length in mm.
	 */
	public function getInnerLength(): int
	{
		return (int) $this->maxLength;
	}
	
	/**
	 * Inner depth in mm.
	 */
	public function getInnerDepth(): int
	{
		return (int) $this->maxDepth;
	}
	
	/**
	 * Max weight the packaging can hold in g.
	 */
	public function getMaxWeight(): int
	{
		return (int) \round($this->maxWeight * 1000);
	}

	public function getDynamicDelivery(): ?string
	{
		$displayDelivery = $this->defaultDisplayDelivery;

		if (!$displayDelivery) {
			return null;
		}

		if (!$displayDelivery->timeThreshold) {
			return $displayDelivery->label;
		}

		$nowThresholdTime = \Carbon\Carbon::createFromFormat('G:i', $displayDelivery->timeThreshold);

		return $nowThresholdTime > (new \Carbon\Carbon()) ? $displayDelivery->beforeTimeThresholdLabel : $displayDelivery->afterTimeThresholdLabel;
	}
}
