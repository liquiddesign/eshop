<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopEntity;
use StORM\RelationCollection;

/**
 * Dopravce / rozvozové auto. Per-shop.
 * @table
 * @index{"name":"deliverycarrier_codeshop","unique":true,"columns":["code", "fk_shop"]}
 */
class DeliveryCarrier extends ShopEntity
{
	/**
	 * Strojový kód (např. cechy, morava)
	 * @column
	 */
	public string $code;

	/**
	 * Lidský název
	 * @column{"mutations":true}
	 */
	public string|null $name = null;

	/**
	 * Priorita pro řazení v adminu
	 * @column
	 */
	public int $priority = 10;

	/**
	 * Skryto
	 * @column
	 */
	public bool $hidden = false;

	/**
	 * QI TransportTypeID — odesílá se do hlavičky QI objednávky (DocumentHeader/TransportTypeID)
	 * při exportu objednávky s Abel dodávkou. Dopravce se vybírá podle kraje doručení.
	 * @column
	 */
	public string|null $qiTransportTypeId = null;

	/**
	 * QI GoodsID — odesílá se jako kód položky s cenou dopravy do QI objednávky (Items/Item/GoodsID)
	 * při exportu objednávky s Abel dodávkou. Dopravce se vybírá podle kraje doručení.
	 * @column
	 */
	public string|null $qiDeliveryGoodsId = null;

	/**
	 * Kraje, které tento dopravce obsluhuje (M:N přes pivot DeliveryCarrierRegion)
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\DeliveryRegion>
	 */
	public RelationCollection $regions;
}
