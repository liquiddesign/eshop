<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Hodnota parametru
 * @table
 * @index{"name":"allowed_unique","unique":true,"columns":["allowedKey","fk_parameter"]}
 * @deprecated
 */
class ParameterAvailableValue extends \StORM\Entity
{
	/**
	 * Povolený klíč
	 * @column
	 */
	public string $allowedKey;

	/**
	 * Povolená hodnota
	 * @column{"mutations":true}
	 */
	public ?string $allowedValue;

	/**
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 * @relation
	 */
	public Parameter $parameter;
}
