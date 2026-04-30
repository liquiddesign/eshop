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

class RequestManagerApproval extends BaseAction
{
	public function __construct(
		private readonly GetOfferState $getOfferState,
		private readonly OfferTypeStrategyResolver $strategyResolver,
		private readonly OfferLogItemRepository $offerLogItemRepository,
	) {
	}

	/**
	 * Request manager approval for offer
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function execute(Offer $offer, Merchant|null $actor = null): void
	{
		$this->canRequestManagerApproval($offer);

		$offer->update([
			'managerApprovalRequestedTs' => Carbon::now()->toDateTimeString(),
		]);

		$this->offerLogItemRepository->createLog(
			$offer,
			OfferLogItem::MANAGER_APPROVAL_REQUESTED,
			null,
			$actor ?? $offer->merchant,
		);
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function canRequestManagerApproval(Offer $offer): void
	{
		$strategy = $this->strategyResolver->resolve($offer);

		if (!$strategy->supportsManagerApproval()) {
			throw new UnauthorizedStateChangeException();
		}

		$state = $this->getOfferState->execute($offer);
		$allowedTransitions = $strategy->getAllowedTransitions($state);

		if (Arrays::contains($allowedTransitions, OfferState::AwaitingManagerApproval)) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}
}
