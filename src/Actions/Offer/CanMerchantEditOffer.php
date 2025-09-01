<?php

namespace Eshop\Actions\Offer;

use Base\BaseAction;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;

class CanMerchantEditOffer extends BaseAction
{
	public function __construct(private readonly GetOfferState $getOfferState)
	{
	}

	public function execute(Offer $offer): bool
	{
		return $this->getOfferState->execute($offer) === OfferState::Created;
	}
}
