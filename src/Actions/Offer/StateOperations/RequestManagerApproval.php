<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\BaseAction;
use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;

class RequestManagerApproval extends BaseAction
{
	public function __construct(
		private readonly GetOfferState $getOfferState
	) {
	}

	/**
	 * Request manager approval for offer
	 * @throws \Exception
	 */
	public function execute(Offer $offer): void
	{
		$currentState = $this->getOfferState->execute($offer);

		if ($currentState !== OfferState::Created) {
			throw new \Exception('Nabídku lze odeslat ke schválení pouze ze stavu "Vytvořena"');
		}

		// Set timestamp
		$offer->update([
			'managerApprovalRequestedTs' => Carbon::now()->toDateTimeString(),
		]);
	}
}
