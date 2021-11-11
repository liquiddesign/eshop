<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Varianta
 * @table
 */
class Variant extends \StORM\Entity
{
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
	/**
	 * Perex
	 * @column{"type":"text","mutations":true}
	 */
	public ?string $perex;
	
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
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Product $product;
}
