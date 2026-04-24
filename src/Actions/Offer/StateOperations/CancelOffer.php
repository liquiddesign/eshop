<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Merchant;
use Eshop\DB\Offer;
use Eshop\DB\OfferLogItem;
use Eshop\DB\OfferLogItemRepository;
use Eshop\DB\OfferState;
use Eshop\Services\Offer\OfferTypeStrategyResolver;
use Nette\Utils\Arrays;

class CancelOffer extends \Base\BaseAction
{
	public function __construct(
		private readonly GetOfferState $getOfferState,
		private readonly OfferTypeStrategyResolver $strategyResolver,
		private readonly OfferLogItemRepository $offerLogItemRepository,
	) {
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function execute(Offer $offer, Merchant|null $actor = null): void
	{
		$this->canCancelOffer($offer);

		$offer->update([
			'canceledTs' => Carbon::now()->toDateTimeString(),
			'completedTs' => null,
		]);

		$this->offerLogItemRepository->createLog(
			$offer,
			OfferLogItem::CANCELED,
			null,
			$actor ?? $offer->merchant,
		);

		$this->onOfferCanceled($offer);
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function canCancelOffer(Offer $offer): void
	{
		$state = $this->getOfferState->execute($offer);
		$strategy = $this->strategyResolver->resolve($offer);
		$allowedTransitions = $strategy->getAllowedTransitions($state);

		if (Arrays::contains($allowedTransitions, OfferState::Canceled)) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}

	protected function onOfferCanceled(Offer $offer): void
	{
		$this->strategyResolver->resolve($offer)->onCanceled($offer);
	}
}
