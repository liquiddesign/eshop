<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface ICartRecapitulationListFactory
{
	public function create(): CartRecapitulationList;
}