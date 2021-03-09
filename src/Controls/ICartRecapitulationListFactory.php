<?php

declare(strict_types=1);

namespace Eshop\Controls;

use StORM\ICollection;

interface ICartRecapitulationListFactory
{
	public function create(?ICollection $items = null): CartRecapitulationList;
}