<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface ICartImportFactory
{
	public function create(): CartImportForm;
}
