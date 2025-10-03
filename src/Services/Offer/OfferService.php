<?php

namespace Eshop\Services\Offer;

use Base\Bridges\AutoWireService;
use Eshop\DB\Offer;
use Eshop\DB\OrderRepository;

class OfferService implements AutoWireService
{
	public function __construct(private readonly OrderRepository $orderRepository)
	{
	}

	/**
	 * @param \Eshop\DB\Offer $offer
	 * @return array{offerCode: string, offer: array<string, mixed>, order: array<string, mixed>}
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getEmailVariables(Offer $offer): array
	{
		return [
			'offerCode' => $offer->code,
			'offer' => $offer->toJsonArray(),
			'order' => $this->orderRepository->getEmailVariables($offer->order),
		];
	}
}
