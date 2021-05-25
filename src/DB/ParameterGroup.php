<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Skupina parametrů
 * @table
 */
class ParameterGroup extends \StORM\Entity
{
	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Interní název
	 * @column
	 */
	public ?string $internalName;
	
	/**
	 * Operator skupiny
	 * @column
	 */
	public ?string $filterOperator;
	
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
	 * Systémová skupina
	 * @column
	 */
	public bool $systemic = false;

	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public ParameterCategory $parameterCategory;
	
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