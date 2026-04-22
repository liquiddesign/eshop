<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

/**
 * Daemon returned `fallback_required: true` — typically because the request references an
 * extensibility hook (custom order expression, custom dynamic filter) that only PHP knows.
 *
 * The proxy catches this and delegates to `LiveProductsProvider` without logging as an error.
 */
final class RustDaemonFallbackRequiredException extends RustDaemonException
{
}
