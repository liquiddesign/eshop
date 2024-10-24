<?php

namespace Eshop\Actions\Supplier;

use Base\BaseAction;
use Eshop\DB\Supplier;
use Eshop\DB\SupplierRepository;

class GetSupplierByCode extends BaseAction
{
	public function __construct(private readonly SupplierRepository $supplierRepository)
	{
	}

	public function execute(string $code): Supplier|null
	{
		return $this->getLocalCachedOutput($code, function () use ($code): Supplier|null {
			return $this->supplierRepository->many()->where('this.code', $code)->first();
		});
	}
}
