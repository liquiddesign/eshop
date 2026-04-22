<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

/**
 * Socket unreachable (daemon not running, file missing, connect refused).
 * Spawn-on-demand may have been attempted; the caller should fall back to the PHP provider.
 */
final class RustDaemonUnavailableException extends RustDaemonException
{
}
