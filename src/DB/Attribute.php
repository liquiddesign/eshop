<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Attribute
 * @table
 * @index{"name":"attribute_code_unique","unique":true,"columns":["code"]}
 */
class Attribute extends \StORM\Entity
{
	public const FILTER_TYPES = [
		'and' => 'AND',
		'or' => 'OR',
	];

	/**
	 * Kód
	 * @unique
	 * @column
	 */
	public ?string $code;

	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;
	
	/**
	 * Dodatečné informace pro front, např.: na otazník
	 * @column{"mutations":true, "type":"longtext"}
	 */
	public ?string $note;

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
	 * Zobrazit skryté
	 * @column
	 */
	public bool $showCollapsed = false;

	/**
	 * Zobrazit v pruvodci
	 * @column
	 */
	public bool $showWizard = false;

	/**
	 * Pozice v pruvodci (krok)
	 * @column{"type":"set","length":"'1','2','3','4'"}
	 */
	public ?string $wizardStep = null;

	/**
	 * Nazev v pruvodci
	 * @column{"mutations":true}
	 */
	public ?string $wizardLabel = null;

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
	 * Zobrazit jako range
	 * @column
	 */
	public bool $showRange = false;

	/**
	 * Kategorie
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Category>|\Eshop\DB\Category[]
	 */
	public RelationCollection $categories;
	
	/**
	 * Dodavatel / externí
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Supplier $supplier;

	public function isSystemic(): bool
	{
		return $this->systemic;
	}
}
