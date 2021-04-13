<?php

declare(strict_types=1);

namespace Eshop\Controls;

use StORM\Collection;

interface IAccountListFactory
{
	public function create(Collection $accounts): AccountList;
}