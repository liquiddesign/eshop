<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Application\ApplicationException;

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

	/**
	 * Systémová
	 * @column
	 */
	public bool $systemic = false;

	public function isSystemic(): bool
	{
		return $this->systemic;
	}

	public function getImage(string $basePath, string $size = 'detail'): string
	{
		if (!\in_array($size, ['origin', 'detail', 'thumb'])) {
			throw new ApplicationException('Invalid product image size: ' . $size);
		}

		return $this->imageFileName ? $basePath . '/userfiles/' . self::IMAGE_DIR . '/' . $size . '/' . $this->imageFileName :
			$basePath . '/public/img/map-point.png';
	}
}