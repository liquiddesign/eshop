<?php

declare(strict_types=1);

namespace Eshop\DB;

use DVDoug\BoxPacker;
use DVDoug\BoxPacker\Packer;
use Eshop\Common\DB\SystemicEntity;
use StORM\RelationCollection;

/**
 * Typ dopravy
 * @table
 */
class DeliveryType extends SystemicEntity implements BoxPacker\Box
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
	 * Max váha
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
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\PaymentType>|\Eshop\DB\PaymentType[]
	 */
	public RelationCollection $allowedPaymentTypes;
	
	/**
	 * Výdejní typ
	 * @relation
	 * @constraint
	 */
	public ?PickupPointType $pickupPointType;

	/**
	 * @param array<\Eshop\DB\CartItem> $items
	 */
	public function getBoxesFroItems(array $items): BoxPacker\PackedBoxList
	{
		$packer = new Packer();
		$packer->addBox($this);
		
		foreach ($items as $item) {
			$packer->addItem($item, $item->amount);
		}
		
		return $packer->pack();
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
}
