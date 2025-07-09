<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;

class CancelOffer extends \Base\BaseAction
{
	public function __construct(private readonly GetOfferState $getOfferState)
	{
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function execute(Offer $offer): void
	{
		$this->canCancelOffer($offer);

		$offer->update([
			'canceledTs' => Carbon::now()->toDateTimeString(),
			'completedTs' => null,
		]);

		$this->onOfferCanceled($offer);
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function canCancelOffer(Offer $offer): void
	{
		$state = $this->getOfferState->execute($offer);

		if ($state !== OfferState::Canceled) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}

	protected function onOfferCanceled(Offer $offer): void
	{
		unset($offer);
	}
}
