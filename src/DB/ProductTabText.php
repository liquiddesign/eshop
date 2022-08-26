<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Text záložky
 * @table
 */
class ProductTabText extends \StORM\Entity
{
	/**
	 * Obsah
	 * @column{"type":"longtext","mutations":true}
	 */
	public ?string $content;

	/**
	 * Záložka
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?ProductTab $tab;

	/**
	 * Záložka
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ?Product $product;
}
