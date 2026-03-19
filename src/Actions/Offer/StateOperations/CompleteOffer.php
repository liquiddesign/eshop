<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\BaseAction;
use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Offer;
use Eshop\DB\OfferLogItem;
use Eshop\DB\OfferLogItemRepository;
use Eshop\DB\OfferState;
use Eshop\Services\Offer\OfferTypeStrategyResolver;

class CompleteOffer extends BaseAction
{
	public function __construct(
		private readonly GetOfferState $getOfferState,
		private readonly OfferLogItemRepository $offerLogItemRepository,
		private readonly OfferTypeStrategyResolver $strategyResolver,
	) {
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function execute(Offer $offer): void
	{
		$this->canCompleteOffer($offer);

		$date = Carbon::now()->toDateTimeString();

		$offer->update(['approvedTs' => $date]);

		$this->offerLogItemRepository->createLog(
			$offer,
			OfferLogItem::COMPLETED,
			null,
			$offer->merchant
		);

		$this->onOfferCompleted($offer);
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function canCompleteOffer(Offer $offer): void
	{
		$state = $this->getOfferState->execute($offer);

		if ($state === OfferState::Sent || $state === OfferState::Approved) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}

	protected function onOfferCompleted(Offer $offer): void
	{
		$this->strategyResolver->resolve($offer)->onCompleted($offer);
	}
}
