<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

/**
 * Base class for all {@see RustDaemonClient} error paths.
 * Callers catching this delegate to the PHP fallback provider.
 */
abstract class RustDaemonException extends \RuntimeException
{
}
