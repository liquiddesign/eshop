<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;

class CompleteOffer extends \Base\BaseAction
{
	public function __construct(private readonly GetOfferState $getOfferState)
	{
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function execute(Offer $offer): void
	{
		$this->canCompleteOffer($offer);

		$offer->update(['completedTs' => Carbon::now()->toDateTimeString()]);

		$this->onOfferCompleted($offer);
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function canCompleteOffer(Offer $offer): void
	{
		$state = $this->getOfferState->execute($offer);

		if ($state === OfferState::Approved || $state === OfferState::Canceled) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}

	protected function onOfferCompleted(Offer $offer): void
	{
		unset($offer);
	}
}
