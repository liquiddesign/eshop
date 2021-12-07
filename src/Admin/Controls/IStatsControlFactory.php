<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\DB\Customer;

interface IStatsControlFactory
{
	public function create(?Customer $signedInCustomer = null): StatsControl;
}
