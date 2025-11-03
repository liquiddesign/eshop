<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Fotografie dodavatelského produktu (dočasné úložiště před importem do Photo)
 * @table
 */
class SupplierProductPhoto extends \StORM\Entity
{
	/**
	 * Název souboru
	 * @column
	 */
	public ?string $fileName;

	/**
	 * URL zdroje (původní odkaz od dodavatele)
	 * @column{"type":"longtext"}
	 */
	public ?string $sourceUrl;

	/**
	 * Priorita (pořadí zobrazení)
	 * @column
	 */
	public int $priority = 10;

	/**
	 * Dodavatelský produkt
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public SupplierProduct $supplierProduct;

	/**
	 * Vytvořen
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
}
