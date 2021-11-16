<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

interface IStatsControlFactory
{
	public function create(): StatsControl;
}
