<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;

class ApproveOffer extends \Base\BaseAction
{
	public function __construct(private readonly GetOfferState $getOfferState)
	{
	}

	public function execute(Offer $offer): void
	{
		$this->canApproveOrder($offer);

		$offer->update(['approvedTs' => Carbon::now()->toDateTimeString()]);

		$this->onOfferApproved($offer);
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function canApproveOrder(Offer $offer): void
	{
		$state = $this->getOfferState->execute($offer);

		if ($state === OfferState::Created) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}

	protected function onOfferApproved(Offer $offer): void
	{
		unset($offer);
	}
}
