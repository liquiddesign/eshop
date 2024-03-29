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
	 * Ukazovat ceny bez DPH
	 * @column
	 */
	public bool $defaultPricesWithoutVat = false;

	/**
	 * Formát ceny
	 * @column{"type":"enum","length":"'withoutVat','withVat'"}
	 */
	public string $defaultPriorityPrice = 'withoutVat';

	/**
	 * Defaultní oprávnění: katalog
	 * @column{"type":"enum","length":"'none','catalog','price'"}
	 */
	public string $defaultCatalogPermission = 'price';

	/**
	 * Defaultní slevová hladina
	 * @column
	 */
	public int $defaultDiscountLevelPct = 0;
	
	/**
	 * Max. slevova u produktů
	 * @column
	 */
	public int $defaultMaxDiscountProductPct = 100;

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
	 * Oprávnění: vidět všechny objednávky zákazníka
	 * @column
	 */
	public bool $defaultViewAllOrders = false;

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
