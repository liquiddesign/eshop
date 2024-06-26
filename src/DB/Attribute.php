<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Controls\ProductFilter;
use Nette\Utils\Arrays;
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
	 * Počet zobrazených
	 * @column
	 */
	public ?int $showCount = null;

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
	 * Zobrazit skryté
	 * @column
	 * @deprecated
	 */
	public bool $showCollapsed = false;

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
	 * Doporučené
	 * @column
	 */
	public bool $recommended = false;

	/**
	 * Skrýt hodnoty bez přiřazení na frontu
	 * @column
	 */
	public bool $hideEmptyValues = false;

	/**
	 * Systemic
	 * @column
	 */
	public bool $systemic = false;

	/**
	 * Systemic
	 * @column
	 */
	public int $systemicLock = 0;

	/**
	 * Zobrazit jako range
	 * @column
	 */
	public bool $showRange = false;

	/**
	 * Order attribute values by label
	 * @column
	 */
	public bool $orderValuesByLabel = false;

	/**
	 * Jméno pro Heureku
	 * @column
	 */
	public ?string $heurekaName;

	/**
	 * Jméno pro Zboží
	 * @column
	 */
	public ?string $zboziName;

	/**
	 * @column
	 */
	public bool $exportToAlgolia = false;

	/**
	 * ID
	 * column - don't created by auto migration, only by manual
	 */
	public int $id;

	/**
	 * Kategorie
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Category>
	 */
	public RelationCollection $categories;

	/**
	 * @relationNxN{"sourceViaKey":"fk_attribute","targetViaKey":"fk_attributegroup","via":"eshop_attributegroup_nxn_eshop_attribute"}
	 * @var \StORM\RelationCollection<\Eshop\DB\AttributeGroup>
	 */
	public RelationCollection $groups;
	
	/**
	 * Dodavatel / externí
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public ?Supplier $supplier;

	public function isSystemic(): bool
	{
		return $this->systemic || $this->systemicLock > 0;
	}

	public function isHardSystemic(): bool
	{
		return $this->isSystemic() && Arrays::contains(\array_keys(ProductFilter::SYSTEMIC_ATTRIBUTES), $this->getPK());
	}

	public function addSystemic(): int
	{
		$this->systemicLock++;
		$this->updateAll();

		return $this->systemicLock;
	}

	public function removeSystemic(): int
	{
		$this->systemicLock--;

		if ($this->systemicLock < 0) {
			$this->systemicLock = 0;
		} else {
			$this->updateAll();
		}

		return $this->systemicLock;
	}
}
