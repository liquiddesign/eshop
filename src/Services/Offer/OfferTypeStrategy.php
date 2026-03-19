<?php

declare(strict_types=1);

namespace Eshop\Services\Offer;

use Eshop\DB\Offer;
use Eshop\DB\OfferState;
use Eshop\DB\OfferType;

interface OfferTypeStrategy
{
	public function getType(): OfferType;

	/**
	 * @return array<\Eshop\DB\OfferState>
	 */
	public function getAllowedTransitions(OfferState $currentState): array;

	public function supportsManagerApproval(): bool;

	/**
	 * @return array<string> validation errors
	 */
	public function validateForSend(Offer $offer): array;

	public function onApproved(Offer $offer): void;

	public function onCanceled(Offer $offer): void;

	public function onCompleted(Offer $offer): void;
}
