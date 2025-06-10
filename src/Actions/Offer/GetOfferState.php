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

		if ($offer->completedTs !== null) {
			return OfferState::Completed;
		}

		if ($offer->approvedTs !== null) {
			return OfferState::Approved;
		}

		return OfferState::Created;
	}
}
