<?php

declare(strict_types=1);

/**
 * Reference PHP implementation of the inner loop of
 * `Eshop\Services\ProductsCache\LiveProductsProvider::computeEffectivePrices`
 * (lines 1128-1180 of `/home/petr/eshop/src/Services/ProductsCache/LiveProductsProvider.php`).
 *
 * SYNC ME: this file must stay byte-for-byte equivalent with that method's math. The Rust
 * daemon's `query::pricing::apply_modifiers` is verified against fixtures produced from here;
 * when PHP changes, regenerate fixtures and re-run the Rust parity suite before flipping
 * `productsProvider: rust` in production.
 *
 * Runs standalone — no DI container, no DB, no StORM entities. Pure arithmetic.
 */

/**
 * @param array{
 *     price: float,
 *     priceVat: float|null,
 *     priceBefore: float|null,
 *     priceVatBefore: float|null
 * } $priceRow
 * @param array{allowSurchargeLevel: bool, allowDiscountLevel: bool} $pricelistMeta
 * @param array{
 *     discountLevelPct: int,
 *     maxProductDiscountLevel: int,
 *     surchargeLevelPct: float,
 *     currencyRate: float|null,
 *     calculationPrecision: int
 * } $modifiers
 * @param int $productDiscountLevelPct Product-level discount (`product.discountLevelPct ?? 0`).
 * @return array{price: float, priceVat: float, priceBefore: float, priceVatBefore: float}
 */
function computeEffectivePriceReference(
	array $priceRow,
	array $pricelistMeta,
	array $modifiers,
	int $productDiscountLevelPct,
): array {
	$prec = $modifiers['calculationPrecision'];
	$convertRatio = $modifiers['currencyRate'];
	$discountLevelPct = $modifiers['discountLevelPct'];
	$maxProductDiscountLevel = $modifiers['maxProductDiscountLevel'];
	$surchargeLevelPct = $modifiers['surchargeLevelPct'];

	// PHP line 1127: clamp + max over customer-global discount.
	$effectiveDiscount = \max(\min($productDiscountLevelPct, $maxProductDiscountLevel), $discountLevelPct);

	// PHP lines 1142-1151: currency conversion + round per price independently.
	$price = $convertRatio === null ? (float) $priceRow['price'] : \round(((float) $priceRow['price']) * $convertRatio, $prec);
	$priceVat = $priceRow['priceVat'] === null
		? $price
		: ($convertRatio === null ? (float) $priceRow['priceVat'] : \round(((float) $priceRow['priceVat']) * $convertRatio, $prec));
	$priceBeforeRaw = $priceRow['priceBefore'] === null
		? 0.0
		: ($convertRatio === null ? (float) $priceRow['priceBefore'] : \round(((float) $priceRow['priceBefore']) * $convertRatio, $prec));
	$priceVatBeforeRaw = $priceRow['priceVatBefore'] === null
		? 0.0
		: ($convertRatio === null ? (float) $priceRow['priceVatBefore'] : \round(((float) $priceRow['priceVatBefore']) * $convertRatio, $prec));

	// Surcharge divisor — no intermediate round (matches SQL inline `$expression$surchargeExpression`
	// in `ProductRepository::sqlHandlePrice`, which is just a raw divide with the outer ROUND/CAST
	// applying final precision).
	if ($surchargeLevelPct > 0 && $pricelistMeta['allowSurchargeLevel']) {
		$surchargeDivisor = 1 - ($surchargeLevelPct / 100);

		if ($surchargeDivisor > 0) {
			$price = $price / $surchargeDivisor;
			$priceVat = $priceVat / $surchargeDivisor;
		}
	}

	// Discount — single round on combined expression matches SQL
	// `ROUND($expression$surchargeExpression * ((100 - effDisc)/100), $prec)` in sqlHandlePrice.
	// Integer-first `* divisor / 100` namísto `* discount_factor` — lepší f64 přesnost
	// a symetrie s reverse-engineering tvarem (`* 100 / divisor`).
	// Bez discountu: final round mirrors the outer `CAST(x AS DECIMAL(n, prec))` in CONCAT_WS.
	if ($pricelistMeta['allowDiscountLevel'] && $effectiveDiscount > 0) {
		$discountDivisor = 100 - $effectiveDiscount;
		$price = \round($price * $discountDivisor / 100, $prec);
		$priceVat = \round($priceVat * $discountDivisor / 100, $prec);

		if ($priceBeforeRaw === 0.0) {
			$priceBeforeRaw = \round($price * 100 / $discountDivisor, $prec);
			$priceVatBeforeRaw = \round($priceVat * 100 / $discountDivisor, $prec);
		}
	} else {
		$price = \round($price, $prec);
		$priceVat = \round($priceVat, $prec);
	}

	return [
		'price' => $price,
		'priceVat' => $priceVat,
		'priceBefore' => $priceBeforeRaw,
		'priceVatBefore' => $priceVatBeforeRaw,
	];
}
