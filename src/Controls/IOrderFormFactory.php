<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface IOrderFormFactory
{
	public function create(): OrderForm;
}