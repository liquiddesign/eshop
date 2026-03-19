<?php

declare(strict_types=1);

namespace Eshop\Services\Offer;

use Base\Bridges\AutoWireService;
use Eshop\DB\Offer;
use Eshop\DB\OfferType;

class OfferTypeStrategyResolver implements AutoWireService
{
	/** @var array<string, \Eshop\Services\Offer\OfferTypeStrategy> */
	private array $strategies = [];

	public function register(OfferTypeStrategy $strategy): void
	{
		$this->strategies[$strategy->getType()->value] = $strategy;
	}

	public function resolve(Offer $offer): OfferTypeStrategy
	{
		return $this->resolveByType($offer->getOfferType());
	}

	public function resolveByType(OfferType $type): OfferTypeStrategy
	{
		$strategy = $this->strategies[$type->value] ?? null;

		if ($strategy === null) {
			throw new \RuntimeException("No strategy registered for offer type '{$type->value}'.");
		}

		return $strategy;
	}
}
