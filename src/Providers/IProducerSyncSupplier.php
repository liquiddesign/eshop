<?php

declare(strict_types=1);

namespace Eshop\Providers;

interface IProducerSyncSupplier
{
	public function syncSupplierProducers(): void;

	public function syncRealProducers(): void;

	public function getProducerName(string $name): string;

	public function getProducerCode(string $name): string;
}
