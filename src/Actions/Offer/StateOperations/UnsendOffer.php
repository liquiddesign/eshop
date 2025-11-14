<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\BaseAction;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;

class UnsendOffer extends BaseAction
{
	public function __construct(private readonly GetOfferState $getOfferState,)
	{
	}

	/**
	 * Unsend offer - move from Sent back to Created state
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException|\StORM\Exception\NotFoundException
	 */
	public function execute(Offer $offer): void
	{
		$this->canUnsendOffer($offer);

		// Nullify sentTs to move back to Created state
		$offer->update([
			'sentTs' => null,
			'managerApprovalRequestedTs' => null,
			'managerApprovedTs' => null,
		]);
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function canUnsendOffer(Offer $offer): void
	{
		$state = $this->getOfferState->execute($offer);

		// Can only unsend from Sent state
		if ($state === OfferState::Sent) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}
}
