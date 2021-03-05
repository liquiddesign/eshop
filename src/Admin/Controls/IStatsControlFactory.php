<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

interface IStatsControlFactory
{
	/**
	 * @param \Eshop\DB\Customer|\Eshop\DB\Merchant|null $user
	 * @return \Eshop\Admin\Controls\StatsControl
	 */
	public function create($user = null): StatsControl;
}