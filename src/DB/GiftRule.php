<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;
use StORM\RelationCollection;

/**
 * Pravidlo dárku k nákupu
 * @table
 * @method \StORM\RelationCollection<\Eshop\DB\GiftRuleProduct> getGiftRuleProducts()
 */
class GiftRule extends Entity
{
	/**
	 * Název pravidla
	 * @column
	 */
	public string $name;

	/**
	 * Cena od (včetně)
	 * @column
	 */
	public float $priceFrom;

	/**
	 * Cena do (včetně)
	 * @column
	 */
	public float $priceTo;

	/**
	 * Priorita (nižší = vyšší priorita)
	 * @column
	 */
	public int $priority = 0;

	/**
	 * Aktivní
	 * @column
	 */
	public bool $active = true;

	/**
	 * Vytvořeno
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * Měna
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Currency $currency;

	/**
	 * Produkty v pravidle
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\GiftRuleProduct>
	 */
	public RelationCollection $giftRuleProducts;
}
