<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface IGiftSelectorFactory
{
	public function create(): GiftSelector;
}
