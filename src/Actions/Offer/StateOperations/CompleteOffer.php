<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\BaseAction;
use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Merchant;
use Eshop\DB\Offer;
use Eshop\DB\OfferLogItem;
use Eshop\DB\OfferLogItemRepository;
use Eshop\DB\OfferState;
use Eshop\Services\Offer\OfferTypeStrategyResolver;
use Nette\Utils\Arrays;

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
	public function execute(Offer $offer, Merchant|null $actor = null): void
	{
		$this->canCompleteOffer($offer);

		$date = Carbon::now()->toDateTimeString();

		$offer->update([
			'completedTs' => $date,
			'approvedTs' => $offer->approvedTs ?? $date,
			'sentTs' => $offer->sentTs ?? $date,
		]);

		$this->offerLogItemRepository->createLog(
			$offer,
			OfferLogItem::COMPLETED,
			null,
			$actor ?? $offer->merchant,
		);

		$this->onOfferCompleted($offer);
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function canCompleteOffer(Offer $offer): void
	{
		$state = $this->getOfferState->execute($offer);
		$strategy = $this->strategyResolver->resolve($offer);
		$allowedTransitions = $strategy->getAllowedTransitions($state);

		if (Arrays::contains($allowedTransitions, OfferState::Completed)) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}

	protected function onOfferCompleted(Offer $offer): void
	{
		$this->strategyResolver->resolve($offer)->onCompleted($offer);
	}
}
