<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

interface IStatsControlFactory
{
	/**
	 * @param \Eshop\DB\Customer|\Eshop\DB\Merchant|null $user
	 */
	public function create($user = null): StatsControl;
}
