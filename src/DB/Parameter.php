<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Parameter produktu
 * @table
 * @deprecated
 */
class Parameter extends \StORM\Entity
{
	public const FILTER_TYPES = [
		'and' => 'AND',
		'or' => 'OR'
	];

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
	 * Typ pro filtr
	 * @column{"type":"enum","length":"'and','or'"}
	 */
	public string $filterType = 'and';
	
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