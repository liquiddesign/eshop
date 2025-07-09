<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;

class SendOffer extends \Base\BaseAction
{
	public function __construct(private readonly GetOfferState $getOfferState)
	{
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function execute(Offer $offer): void
	{
		$this->canSendOffer($offer);

		$offer->update([
			'completedTs' => Carbon::now()->toDateTimeString(),
			'canceledTs' => null,
		]);

		$this->onOfferSent($offer);
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function canSendOffer(Offer $offer): void
	{
		$state = $this->getOfferState->execute($offer);

		if ($state === OfferState::Created) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}

	protected function onOfferSent(Offer $offer): void
	{
		unset($offer);
	}
}
