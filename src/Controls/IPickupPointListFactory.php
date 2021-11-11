<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface IPickupPointListFactory
{
	public function create(): PickupPointList;
}
