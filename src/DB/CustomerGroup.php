<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * Skupiny uživatelů
 * @table
 */
class CustomerGroup extends \StORM\Entity
{
	/**
	 * Jméno
	 * @column
	 */
	public string $name;

	/**
	 * Ukazovat ceny s DPH
	 * @column
	 */
	public bool $defaultPricesWithVat = false;

	/**
	 * Defaultní orávnění: katalog
	 * @column{"type":"enum","length":"'none','catalog','price','full'"}
	 */
	public string $defaultCatalogPermission = 'full';

	/**
	 * Defaultní ceníky
	 * @relationNxN
	 * @var \StORM\RelationCollection<\Eshop\DB\Pricelist>|\Eshop\DB\Pricelist[]
	 */
	public RelationCollection $defaultPricelists;

	/**
	 * Defaultní po registraci
	 * @column
	 */
	public bool $defaultAfterRegistration = false;

	/**
	 * Automaticky potvrdit zákazníky
	 * @column
	 */
	public bool $autoActiveCustomers = true;

	/**
	 * Systémová
	 * @column
	 */
	public bool $systemic = false;

	public function isSystemic(): bool
	{
		return $this->systemic;
	}
}