<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface IAddressesFormFactory
{
	public function create(): AddressesForm;
}