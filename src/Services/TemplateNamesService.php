<?php

namespace Eshop\Services;

use Base\Bridges\AutoWireService;

class TemplateNamesService implements AutoWireService
{
	public function getOrderCreated(): string
	{
		return 'order.created';
	}

	public function getOrderReceived(): string
	{
		return 'order.received';
	}

	public function getOrderCompleted(): string
	{
		return 'order.confirmed';
	}

	public function getOrderPaymentChanged(): string
	{
		return 'order.paymentChanged';
	}

	public function getOrderDeliveryChanged(): string
	{
		return 'order.deliveryChanged';
	}

	public function getOrderCanceled(): string
	{
		return 'order.canceled';
	}

	public function getOrderChanged(): string
	{
		return 'order.changed';
	}

	public function getOrderPayed(): string
	{
		return 'order.payed';
	}

	public function getOrderShipped(): string
	{
		return 'order.shipped';
	}
}
