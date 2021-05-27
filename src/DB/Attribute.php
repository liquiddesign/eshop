<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Attribute
 * @table
 */
class Attribute extends \StORM\Entity
{
	public const FILTER_TYPES = [
		'and' => 'AND',
		'or' => 'OR'
	];

	/**
	 * Kód
	 * @column
	 */
	public ?string $code;

	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Typ pro filtraci
	 * @column{"type":"enum","length":"'and','or'"}
	 */
	public string $filterType = 'and';

	/**
	 * Zobrazit ve filtrech
	 * @column
	 */
	public bool $showFilter = true;

	/**
	 * Zobrazit u produktu
	 * @column
	 */
	public bool $showProduct = true;

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
	 * Kategorie atributů
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public AttributeCategory $category;

	public function isSystemic(): bool
	{
		return $this->systemic;
	}
}