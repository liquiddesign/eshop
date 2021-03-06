<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface ICartCheckerFactory
{
	public function create(): CartChecker;
}
