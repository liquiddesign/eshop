<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

/**
 * Wire-level error: truncated frame, malformed JSON, oversize header, protocol version mismatch.
 * Indicates a daemon bug or client/server version skew.
 * @internal Not a part of the public API. {@see GeneralProductsCacheProvider} handles daemon
 *           failures transparently via fallback to {@see LiveProductsProvider}.
 */
final class RustDaemonProtocolException extends RustDaemonException
{
}
