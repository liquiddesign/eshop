<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Úrovně oprávnění pro zobrazení nákupních cen
 */
enum PurchasePricePermissionLevel: string
{
	case None = 'none';
	case Basic = 'basic';
	case Full = 'full';

	/**
	 * Může vidět nejnižší nákupní cenu
	 */
	public function canViewLowestPrice(): bool
	{
		return $this !== self::None;
	}

	/**
	 * Může vidět průměrnou nákupní cenu
	 */
	public function canViewAveragePrice(): bool
	{
		return $this !== self::None;
	}

	/**
	 * Může vidět ceny jednotlivých dodavatelů
	 */
	public function canViewSupplierPrices(): bool
	{
		return $this === self::Full;
	}

	/**
	 * @return array<string, string>
	 */
	public static function getLabels(): array
	{
		return [
			self::None->value => 'Zakázáno',
			self::Basic->value => 'Základní přístup (nejn. + prům. cena)',
			self::Full->value => 'Plný přístup (včetně dodavatelů)',
		];
	}
}
