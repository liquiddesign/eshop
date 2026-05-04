<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

/**
 * Hook do {@see RustDaemonClient}, který se zavolá, když daemon vrátí chybu
 * `unknown_pricelist` — typicky proto, že PHP právě vytvořil nový zákaznický
 * ceník (offer approve, CKP sync) a daemon snapshot ho ještě nezachytil.
 *
 * Implementace je odpovědná za to, co s informací udělá. V abelu typicky držíme
 * per-request boolean state, který šablona čte na konci renderu a vykreslí
 * varování pro přihlášeného obchodníka.
 *
 * Observer je optional dependency — pokud není v DI registrovaný, daemon error
 * nadále jen probublá jako {@see RustDaemonRequestException}.
 */
interface UnknownPricelistObserver
{
	public function onUnknownPricelist(string $pricelistPk): void;
}
