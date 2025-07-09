<?php

namespace Eshop\Actions\Offer;

use Base\BaseAction;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;

class GetOfferState extends BaseAction
{
	public function execute(Offer $offer): OfferState
	{
		if ($offer->canceledTs !== null) {
			return OfferState::Canceled;
		}

		if ($offer->approvedTs !== null) {
			return OfferState::Approved;
		}

		if ($offer->sentTs !== null) {
			return OfferState::Sent;
		}

		return OfferState::Created;
	}
}
