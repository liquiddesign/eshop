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
	 * Defaultní oprávnění: katalog
	 * @column{"type":"enum","length":"'none','catalog','price'"}
	 */
	public string $defaultCatalogPermission = 'price';

	/**
	 * Defaultní oprávnění: nákup
	 * @column
	 */
	public bool $defaultBuyAllowed = true;

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