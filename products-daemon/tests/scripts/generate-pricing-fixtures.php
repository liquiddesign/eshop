<?php

declare(strict_types=1);

/**
 * Generate pricing parity fixtures.
 *
 * Run manually:
 *     php /home/petr/eshop/products-daemon/tests/scripts/generate-pricing-fixtures.php
 *
 * Output lands in `tests/fixtures/pricing/golden.json`. The Rust parity test
 * (`tests/integration_pricing.rs::parity_against_php_reference`) loads that file and
 * compares `apply_modifiers` output against the PHP-computed `expected` block.
 *
 * Regenerate (and re-verify Rust) any time `LiveProductsProvider::computeEffectivePrices`
 * or `tests/scripts/pricing_reference.php` changes.
 */

require __DIR__ . '/pricing_reference.php';

$fixturesDir = \dirname(__DIR__) . '/fixtures/pricing';

if (!\is_dir($fixturesDir) && !\mkdir($fixturesDir, 0o755, true) && !\is_dir($fixturesDir)) {
	\fwrite(\STDERR, "cannot create fixtures dir: {$fixturesDir}\n");
	exit(1);
}

// Cover every combinatorial branch in the inner loop:
// - convertRatio: null (native), 0.8 (conversion down), 1.5 (conversion up)
// - pricelist.allow_surcharge: true/false
// - pricelist.allow_discount_level: true/false
// - surchargeLevelPct: 0, 5.0, 20.0
// - customer discountLevelPct: 0, 10
// - product.discountLevelPct: 0, 15, 60
// - maxProductDiscountLevel: 25, 100 (clamps product-level discount)
// - priceBefore: 0 (null-equivalent — reverse-engineered on discount) / non-zero (explicit)
//
// Not every combination is independent (e.g. surchargeLevelPct=0 collapses the surcharge
// branch), but explicit coverage is cheap and readable in the output JSON.
$scenarios = [];
$id = 0;

$ratios = [null, 0.8, 1.5];
$allowSurcharge = [true, false];
$allowDiscount = [true, false];
$surchargePcts = [0.0, 5.0, 20.0];
$customerDiscountPcts = [0, 10];
$productDiscountPcts = [0, 15, 60];
$maxProductDiscountLevels = [25, 100];
$priceBeforePairs = [
	['priceBefore' => 0.0, 'priceVatBefore' => 0.0],
	['priceBefore' => 150.0, 'priceVatBefore' => 181.5],
];

foreach ($ratios as $ratio) {
	foreach ($allowSurcharge as $surchargeAllowed) {
		foreach ($allowDiscount as $discountAllowed) {
			foreach ($surchargePcts as $surchargePct) {
				foreach ($customerDiscountPcts as $customerPct) {
					foreach ($productDiscountPcts as $productPct) {
						foreach ($maxProductDiscountLevels as $maxProductPct) {
							foreach ($priceBeforePairs as $priceBeforePair) {
								$input = [
									'price' => 100.0,
									'priceVat' => 121.0,
									'priceBefore' => $priceBeforePair['priceBefore'],
									'priceVatBefore' => $priceBeforePair['priceVatBefore'],
								];

								$pricelistMeta = [
									'allowSurchargeLevel' => $surchargeAllowed,
									'allowDiscountLevel' => $discountAllowed,
								];

								$modifiers = [
									'discountLevelPct' => $customerPct,
									'maxProductDiscountLevel' => $maxProductPct,
									'surchargeLevelPct' => $surchargePct,
									'currencyRate' => $ratio,
									'calculationPrecision' => 2,
								];

								$expected = computeEffectivePriceReference($input, $pricelistMeta, $modifiers, $productPct);

								$scenarios[] = [
									'id' => \sprintf('case-%04d', $id++),
									'description' => \sprintf(
										'ratio=%s surchargeAllowed=%s discountAllowed=%s surchargePct=%.1f customerPct=%d productPct=%d maxProductPct=%d priceBefore=%.1f',
										$ratio === null ? 'null' : (string) $ratio,
										$surchargeAllowed ? 'true' : 'false',
										$discountAllowed ? 'true' : 'false',
										$surchargePct,
										$customerPct,
										$productPct,
										$maxProductPct,
										$priceBeforePair['priceBefore'],
									),
									'input' => [
										'price' => $input['price'],
										'priceVat' => $input['priceVat'],
										'priceBefore' => $input['priceBefore'],
										'priceVatBefore' => $input['priceVatBefore'],
										'pricelistAllowSurcharge' => $surchargeAllowed,
										'pricelistAllowDiscountLevel' => $discountAllowed,
										'productDiscountLevelPct' => $productPct,
										'modifiers' => $modifiers,
									],
									'expected' => $expected,
								];
							}
						}
					}
				}
			}
		}
	}
}

// Add a few hand-picked edge cases that stress specific numerical hazards:
// - `surchargeLevelPct = 99.9` → near-zero divisor, ensure no division-by-zero.
// - `surchargeLevelPct = 100` → exact zero divisor, branch must skip (PHP `$surchargeDivisor > 0`).
// - Non-zero `priceBefore` + discount applied → explicit `priceBefore` survives.
// - Precision=4 → parity holds at finer granularity (Czech eshop occasionally uses 4 decimals for B2B).
$edgeCases = [
	[
		'label' => 'surcharge-near-one',
		'input' => ['price' => 100.0, 'priceVat' => 121.0, 'priceBefore' => 0.0, 'priceVatBefore' => 0.0],
		'pricelistMeta' => ['allowSurchargeLevel' => true, 'allowDiscountLevel' => true],
		'productPct' => 0,
		'modifiers' => [
			'discountLevelPct' => 0,
			'maxProductDiscountLevel' => 100,
			'surchargeLevelPct' => 99.9,
			'currencyRate' => null,
			'calculationPrecision' => 2,
		],
	],
	[
		'label' => 'surcharge-exact-100',
		'input' => ['price' => 100.0, 'priceVat' => 121.0, 'priceBefore' => 0.0, 'priceVatBefore' => 0.0],
		'pricelistMeta' => ['allowSurchargeLevel' => true, 'allowDiscountLevel' => false],
		'productPct' => 0,
		'modifiers' => [
			'discountLevelPct' => 0,
			'maxProductDiscountLevel' => 100,
			'surchargeLevelPct' => 100.0,
			'currencyRate' => null,
			'calculationPrecision' => 2,
		],
	],
	[
		'label' => 'explicit-price-before-survives',
		'input' => ['price' => 100.0, 'priceVat' => 121.0, 'priceBefore' => 180.0, 'priceVatBefore' => 217.8],
		'pricelistMeta' => ['allowSurchargeLevel' => false, 'allowDiscountLevel' => true],
		'productPct' => 10,
		'modifiers' => [
			'discountLevelPct' => 10,
			'maxProductDiscountLevel' => 100,
			'surchargeLevelPct' => 0.0,
			'currencyRate' => null,
			'calculationPrecision' => 2,
		],
	],
	[
		'label' => 'precision-4-decimals',
		'input' => ['price' => 13.37, 'priceVat' => 16.18, 'priceBefore' => 0.0, 'priceVatBefore' => 0.0],
		'pricelistMeta' => ['allowSurchargeLevel' => true, 'allowDiscountLevel' => true],
		'productPct' => 7,
		'modifiers' => [
			'discountLevelPct' => 3,
			'maxProductDiscountLevel' => 50,
			'surchargeLevelPct' => 2.5,
			'currencyRate' => 1.0789,
			'calculationPrecision' => 4,
		],
	],
	// NULL priceVat branch — PHP collapses `priceVat === null` to `price`. The Rust daemon
	// achieves the same via SQL `COALESCE(priceVat, price)` in loaders.rs. These scenarios
	// seed `priceVat = null` straight into the reference helper so parity holds at the math level.
	[
		'label' => 'null-price-vat-no-rate',
		'input' => ['price' => 100.0, 'priceVat' => null, 'priceBefore' => 0.0, 'priceVatBefore' => 0.0],
		'pricelistMeta' => ['allowSurchargeLevel' => false, 'allowDiscountLevel' => false],
		'productPct' => 0,
		'modifiers' => [
			'discountLevelPct' => 0,
			'maxProductDiscountLevel' => 100,
			'surchargeLevelPct' => 0.0,
			'currencyRate' => null,
			'calculationPrecision' => 2,
		],
	],
	[
		'label' => 'null-price-vat-with-rate',
		'input' => ['price' => 100.0, 'priceVat' => null, 'priceBefore' => 0.0, 'priceVatBefore' => 0.0],
		'pricelistMeta' => ['allowSurchargeLevel' => false, 'allowDiscountLevel' => false],
		'productPct' => 0,
		'modifiers' => [
			'discountLevelPct' => 0,
			'maxProductDiscountLevel' => 100,
			'surchargeLevelPct' => 0.0,
			'currencyRate' => 0.8,
			'calculationPrecision' => 2,
		],
	],
	[
		'label' => 'null-price-vat-discount-applied',
		'input' => ['price' => 100.0, 'priceVat' => null, 'priceBefore' => 0.0, 'priceVatBefore' => 0.0],
		'pricelistMeta' => ['allowSurchargeLevel' => false, 'allowDiscountLevel' => true],
		'productPct' => 25,
		'modifiers' => [
			'discountLevelPct' => 0,
			'maxProductDiscountLevel' => 100,
			'surchargeLevelPct' => 0.0,
			'currencyRate' => null,
			'calculationPrecision' => 2,
		],
	],
];

foreach ($edgeCases as $ec) {
	$expected = computeEffectivePriceReference($ec['input'], $ec['pricelistMeta'], $ec['modifiers'], $ec['productPct']);
	$scenarios[] = [
		'id' => \sprintf('edge-%s', $ec['label']),
		'description' => 'edge case: ' . $ec['label'],
		'input' => [
			'price' => $ec['input']['price'],
			'priceVat' => $ec['input']['priceVat'],
			'priceBefore' => $ec['input']['priceBefore'],
			'priceVatBefore' => $ec['input']['priceVatBefore'],
			'pricelistAllowSurcharge' => $ec['pricelistMeta']['allowSurchargeLevel'],
			'pricelistAllowDiscountLevel' => $ec['pricelistMeta']['allowDiscountLevel'],
			'productDiscountLevelPct' => $ec['productPct'],
			'modifiers' => $ec['modifiers'],
		],
		'expected' => $expected,
	];
}

$payload = [
	'generatedAt' => \gmdate('Y-m-d\TH:i:s\Z'),
	'phpVersion' => \PHP_VERSION,
	'referenceFile' => 'tests/scripts/pricing_reference.php (mirror of LiveProductsProvider.php lines 1128-1180)',
	'scenarios' => $scenarios,
];

$jsonPath = $fixturesDir . '/golden.json';
$json = \json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

if ($json === false) {
	\fwrite(\STDERR, 'json_encode failed: ' . \json_last_error_msg() . "\n");
	exit(1);
}

if (\file_put_contents($jsonPath, $json . "\n") === false) {
	\fwrite(\STDERR, "cannot write {$jsonPath}\n");
	exit(1);
}

\printf("wrote %d scenarios to %s\n", \count($scenarios), $jsonPath);
