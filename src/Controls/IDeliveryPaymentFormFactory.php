<?php

declare(strict_types=1);

namespace Eshop\Controls;

interface IDeliveryPaymentFormFactory
{
	/**
	 * @param (callable(string, \Eshop\DB\DeliveryType, \Nette\Forms\Rules): void)|null $onTogglePaymentId
	 */
	public function create(?callable $onTogglePaymentId = null): DeliveryPaymentForm;
}
