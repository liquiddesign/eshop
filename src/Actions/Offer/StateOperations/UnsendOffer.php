<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\BaseAction;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Merchant;
use Eshop\DB\Offer;
use Eshop\DB\OfferLogItem;
use Eshop\DB\OfferLogItemRepository;
use Eshop\DB\OfferState;

class UnsendOffer extends BaseAction
{
	public function __construct(
		private readonly GetOfferState $getOfferState,
		private readonly OfferLogItemRepository $offerLogItemRepository,
	) {
	}

	/**
	 * Unsend offer - move from Sent/Approved back to Created state
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException|\StORM\Exception\NotFoundException
	 */
	public function execute(Offer $offer, Merchant|null $actor = null): void
	{
		$this->canUnsendOffer($offer);

		// Nullify all timestamps to move back to Created state
		// Must include approvedTs - otherwise offer won't appear in any tab
		$offer->update([
			'sentTs' => null,
			'approvedTs' => null,
			'managerApprovalRequestedTs' => null,
			'managerApprovedTs' => null,
		]);

		$this->offerLogItemRepository->createLog(
			$offer,
			OfferLogItem::UNSENT,
			null,
			$actor ?? $offer->merchant,
		);

		$this->onOfferUnsent($offer);
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function canUnsendOffer(Offer $offer): void
	{
		$state = $this->getOfferState->execute($offer);

		// Can only unsend from Sent state
		if ($state === OfferState::AwaitingManagerApproval ||
			$state === OfferState::ManagerApproved ||
			$state === OfferState::Sent ||
			$state === OfferState::Approved
		) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}

	protected function onOfferUnsent(Offer $offer): void
	{
		unset($offer);
	}
}
