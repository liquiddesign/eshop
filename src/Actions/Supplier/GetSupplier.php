<?php

namespace Eshop\Actions\Supplier;

use Base\BaseAction;
use Eshop\DB\Supplier;
use Eshop\DB\SupplierRepository;

class GetSupplier extends BaseAction
{
	public function __construct(private readonly SupplierRepository $supplierRepository)
	{
	}

	public function execute(string $uuid): Supplier|null
	{
		return $this->getLocalCachedOutput($uuid, function () use ($uuid): Supplier|null {
			return $this->supplierRepository->many()->where('this.uuid', $uuid)->first();
		});
	}
}
