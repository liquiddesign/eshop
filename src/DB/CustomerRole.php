<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Common\DB\SystemicEntity;
use StORM\RelationCollection;

/**
 * Role uzivatelů uživatelů
 * @table
 */
class CustomerRole extends SystemicEntity
{
	/**
	 * Jméno
	 * @column
	 */
	public string $name;

	/**
	 * @column
	 */
	public int $priority;

	/**
	 * fixní provize v Kč
	 * @column
	 */
	public float $provisionCzk;

	/**
	 * procentuální provize
	 * @column
	 */
	public int $provisionPct;

	/**
	 * z první objednavky uživatele máme vetsi provizi
	 * @column
	 */
	public int $firstProvisionPct;

	/**
	 * zda je role zapojena nejak do provizniho systemu
	 * @column{"type":"enum","length":"'tree','direct'"}
	 */
	public string $affiliate = 'direct';

	/**
	 * procentuální sleva pro moje cleny
	 * @column
	 */
	public int $membersDiscountPct;

	/**
	 * fixni sleva pro členy na první objednávku v Kč
	 * @column
	 */
	public float $membersFirstOrderCzk;

	/**
	 * Procentuální sleva pro členy na první objednávku
	 * @column
	 */
	public float $membersFirstOrderPct;

	/**
	 * sleva na produkty v %
	 * @column
	 */
	public int $discount;

	/**
	 * Procentualni provize z opakovanych rays club objednavek
	 * @column
	 */
	public float $raysClubRepeatProvisionPct;

	/**
	 * Umožnit výběr peněz
	 * @column
	 */
	public bool $allowWithdraw;

	/**
	 * Dárek jako provize
	 * @column{"type":"enum","length":"'no','yes','autoship'"}
	 */
	public ?string $provisionGift = null;

	/**
	 * @relation{"targetKey":"fk_customerRole"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Customer>
	 */
	public RelationCollection $customers;

	public function isAffiliateTree(): bool
	{
		return $this->affiliate === 'tree';
	}

	public function isAffiliateDirect(): bool
	{
		return $this->affiliate === 'direct';
	}
}
