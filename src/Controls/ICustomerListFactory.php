<?php

declare(strict_types=1);

namespace Eshop\Controls;

use StORM\Collection;

interface ICustomerListFactory
{
	public function create(Collection $customers): CustomerList;
}