<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Stužky k produktu
 * @table
 */
class InternalRibbon extends \StORM\Entity
{
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
}