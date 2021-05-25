<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Parameter produktu
 * @table
 */
class Parameter extends \StORM\Entity
{
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
	/**
	 * Kód
	 * @column
	 */
	public ?string $code;
	
	/**
	 * Popisek
	 * @column{"mutations":true}
	 */
	public ?string $description;
	
	/**
	 * Typ
	 * @column{"type":"enum","length":"'text','bool','list'"}
	 */
	public string $type;
	
	/**
	 * Povolené hodnoty, klíče
	 * @column
	 */
	public ?string $allowedKeys;
	
	/**
	 * Povolené hodnoty, textově
	 * @column{"mutations":true}
	 */
	public ?string $allowedValues;
	
	/**
	 * @column
	 */
	public bool $isPreview = true;
	
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
	 * Systemic
	 * @column
	 */
	public bool $systemic = false;
	
	/**
	 * Skupina parametrů
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ParameterGroup $group;

	public function isSystemic(): bool
	{
		return $this->systemic;
	}
}