<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

interface IProductTesterResultFactory
{
	public function create(array|null $tester = null): ProductTesterResult;
}
