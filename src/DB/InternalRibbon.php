<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopEntity;
use StORM\RelationCollection;

/**
 * Stužky k produktu
 * @table
 */
class InternalRibbon extends ShopEntity
{
	public const TYPE_PRODUCT = 'product';
	public const TYPE_ORDER = 'order';
	public const TYPE_PRICE_LIST = 'price_list';

	public const TYPES = [
		self::TYPE_PRODUCT => 'Produkt',
		self::TYPE_ORDER => 'Objednávka',
		self::TYPE_PRICE_LIST => 'Ceník',
	];

	/**
	 * Název / Popisek
	 * @column
	 */
	public string $name;
	
	/**
	 * Barva textu
	 * @column
	 */
	public ?string $color;
	
	/**
	 * Pozadí
	 * @column
	 */
	public ?string $backgroundColor;

	/**
	 * Systemic
	 * @column
	 */
	public bool $systemic = false;

	/**
	 * Typ
	 * @column{"type":"enum","length":"'product','order','price_list'"}
	 */
	public string $type = 'product';

	/**
	 * @relationNxN{"sourceViaKey":"fk_internalRibbon","targetViaKey":"fk_order","via":"eshop_internalribbon_nxn_eshop_order"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Order>
	 */
	public RelationCollection $orders;

	public function isSystemic(): bool
	{
		return $this->systemic;
	}
}
