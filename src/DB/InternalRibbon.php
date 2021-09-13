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

	/**
	 * Systemic
	 * @column
	 */
	public bool $systemic = false;

	public function isSystemic(): bool
	{
		return $this->systemic;
	}
}