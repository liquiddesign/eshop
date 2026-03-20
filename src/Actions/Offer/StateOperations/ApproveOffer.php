<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\BaseAction;
use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;
use Eshop\Services\Offer\OfferTypeStrategyResolver;

class ApproveOffer extends BaseAction
{
	public function __construct(
		private readonly GetOfferState $getOfferState,
		private readonly OfferTypeStrategyResolver $strategyResolver,
	) {
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException|\StORM\Exception\NotFoundException
	 */
	public function execute(Offer $offer): void
	{
		$this->canApproveOffer($offer);

		$offer->update(['approvedTs' => Carbon::now()->toDateTimeString()]);
		$offer->update(['canceledTs' => null]);

		$this->onOfferApproved($offer);
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function canApproveOffer(Offer $offer): void
	{
		$state = $this->getOfferState->execute($offer);

		if ($state === OfferState::Sent) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}

	protected function onOfferApproved(Offer $offer): void
	{
		$this->strategyResolver->resolve($offer)->onApproved($offer);
	}
}
