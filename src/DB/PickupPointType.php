<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Typ výdejního místa
 * @table
 */
class PickupPointType extends \StORM\Entity
{
	public const IMAGE_DIR = 'pickupPointType_images';

	/**
	 * Název
	 * @column{"mutations":true}
	 */
	public ?string $name;

	/**
	 * Logo
	 * @column
	 */
	public ?string $imageFileName;

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

}