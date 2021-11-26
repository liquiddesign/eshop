<?php
declare(strict_types=1);

namespace Eshop\Providers;

interface IHeurekaProviderFactory
{
	public function create(string $supplierCode, bool $images = false): HeurekaProvider;
}
