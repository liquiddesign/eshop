<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Entity\ShopEntity;

/**
 * Časové prahy dopravců (časy svozů)
 * @table
 */
class DeliveryTypeThreshold extends ShopEntity
{
	public const string FREQUENCY_WEEKLY = 'weekly';
	public const string FREQUENCY_BIWEEKLY = 'biweekly';
	public const string FREQUENCY_EVERY_4_WEEKS = 'every_4_weeks';

	public const string WEEK_OFFSET_EVEN = 'even';
	public const string WEEK_OFFSET_ODD = 'odd';

	/**
	 * Platí do času aktuálního dne
	 * @column
	 */
	public string $time;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $monday = true;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $tuesday = true;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $wednesday = true;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $thursday = true;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $friday = true;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $saturday = false;

	/**
	 * Den v týdnu, kdy platí
	 * @column
	 */
	public bool $sunday = false;

	/**
	 * Výdejní typ
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public DeliveryType $deliveryType;

	/**
	 * Kraj, pro který práh platí. Null = globální (pro všechny PSČ).
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public DeliveryRegion|null $region = null;

	/**
	 * Periodicita: weekly | biweekly | every_4_weeks. Null = každý zaškrtnutý weekday (původní chování).
	 * @column
	 */
	public string|null $frequencyType = null;

	/**
	 * Pro biweekly: even (sudý ISO týden) | odd (lichý ISO týden). Jinak null.
	 * @column
	 */
	public string|null $weekOffset = null;

	/**
	 * Pro every_4_weeks: konkrétní datum prvního cutoffu (anchor). Z něj se odpočítává cyklus „co 28 dnů“.
	 * Datum musí padnout na zaškrtnutý weekday (např. cutoffDay=wednesday → anchor = nějaká středa).
	 * Jinak null. Formát: Y-m-d.
	 * @column{"type":"date"}
	 */
	public string|null $every4WeeksAnchorDate = null;

	/**
	 * Override `DeliveryType.daysFromThresholdToExpedition` na úrovni prahu.
	 * Null = použije se hodnota z DeliveryType (původní chování).
	 * Důležité pro regionální prahy ABEL dopravy, kde každý kraj má jiný delay
	 * (např. Praha Po-Út cutoff → Čt expedice = +2 dny, Severní Morava Út-St → Po next week = +3-4 dny).
	 * @column
	 */
	public int|null $daysFromThresholdToExpedition = null;

	/**
	 * Override `DeliveryType.daysToDelivery` na úrovni prahu.
	 * Null = použije se hodnota z DeliveryType (původní chování).
	 * Pro regionální prahy ABEL dopravy, kde každý kraj má jiný interval expedice → doručení
	 * (např. Vysočina Po expedice → Čt rozvoz = +3 BD, Pardubický St → Čt = +1 BD).
	 * U vícedenních rozvozových intervalů se nastavuje nejbližší (nejdřívější) den.
	 * @column
	 */
	public int|null $daysToDelivery = null;

	/**
	 * @return array<int, bool>
	 */
	public function getActiveWeekDays(): array
	{
		return [
			1 => $this->monday,
			2 => $this->tuesday,
			3 => $this->wednesday,
			4 => $this->thursday,
			5 => $this->friday,
			6 => $this->saturday,
			7 => $this->sunday,
		];
	}

	/**
	 * Ověří, zda dané datum vyhovuje periodicitě prahu.
	 * - null nebo 'weekly' → vždy true (žádné omezení)
	 * - 'biweekly' + weekOffset → ISO týden je sudý/lichý
	 * - 'every_4_weeks' + every4WeeksAnchorDate → datum je >= anchor a (datum − anchor) je dělitelný 28 dny
	 *   (kontinuální napříč roky, žádné resety na novém roce)
	 */
	public function matchesFrequency(\Carbon\Carbon $date): bool
	{
		return match ($this->frequencyType) {
			null, self::FREQUENCY_WEEKLY => true,
			self::FREQUENCY_BIWEEKLY => match ($this->weekOffset) {
				self::WEEK_OFFSET_EVEN => $date->isoWeek % 2 === 0,
				self::WEEK_OFFSET_ODD => $date->isoWeek % 2 !== 0,
				default => true,
			},
			self::FREQUENCY_EVERY_4_WEEKS => $this->every4WeeksMatchesAnchor($date),
			default => true,
		};
	}

	/**
	 * Pro every_4_weeks mode: srovná datum s `every4WeeksAnchorDate` a ověří, že je v cyklu „co 28 dnů“.
	 * Anchor i `$date` se porovnávají jen na úrovni dní (čas se ignoruje).
	 */
	private function every4WeeksMatchesAnchor(\Carbon\Carbon $date): bool
	{
		if ($this->every4WeeksAnchorDate === null) {
			return false;
		}

		$anchor = \Carbon\Carbon::parse($this->every4WeeksAnchorDate)->startOfDay();
		$candidate = $date->clone()->startOfDay();

		if ($candidate->lt($anchor)) {
			return false;
		}

		return (int) $anchor->diffInDays($candidate) % 28 === 0;
	}
}
