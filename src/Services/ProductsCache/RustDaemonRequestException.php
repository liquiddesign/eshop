<?php

declare(strict_types=1);

namespace Eshop\Services\ProductsCache;

/**
 * Daemon returned an explicit error envelope (unknown_pricelist, unknown_category, etc.).
 * Usually recoverable — caller falls back to the PHP provider.
 */
final class RustDaemonRequestException extends RustDaemonException
{
	public function __construct(private readonly string $errorKind, string $message)
	{
		parent::__construct(\sprintf('[%s] %s', $this->errorKind, $message));
	}

	public function getErrorKind(): string
	{
		return $this->errorKind;
	}
}
