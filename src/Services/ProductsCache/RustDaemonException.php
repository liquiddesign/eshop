<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

/**
 * Base class for all {@see RustDaemonClient} error paths.
 * Callers catching this delegate to the PHP fallback provider.
 * @internal Not a part of the public API. {@see GeneralProductsCacheProvider} handles daemon
 *           failures transparently via fallback to {@see LiveProductsProvider}.
 */
abstract class RustDaemonException extends \RuntimeException
{
}
