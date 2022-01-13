<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface IWatcherListFactory
{
	public function create(bool $email = false): WatcherList;
}
