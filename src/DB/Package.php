<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Balíčku
 * @table
 * @method \StORM\RelationCollection<\Eshop\DB\PackageItem> getItems()
 */
class Package extends \StORM\Entity
{
	/**
	 * Číslo balíku
	 * @column
	 */
	public int $id = 1;
	
	/**
	 * Váha
	 * @deprecated use self::getWeight
	 * @column
	 */
	public ?float $weight = null;
	
	/**
	 * @relation
	 * @var \StORM\RelationCollection<\Eshop\DB\PackageItem>|\Eshop\DB\PackageItem[]
	 */
	public RelationCollection $items;
	
	/**
	 * Doručení
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Delivery $delivery;
	
	/**
	 * Objednávka
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Order $order;

	private float $computedWeight;

	public function getWeight(): float
	{
		return $this->computedWeight ??= (float) $this->getItems()
			->setSelect(['weight' => 'SUM(ci.productWeight * ci.amount)'])
			->join(['ci' => 'eshop_cartitem'], 'this.fk_cartItem = ci.uuid')
			->firstValue('weight');
	}
}
