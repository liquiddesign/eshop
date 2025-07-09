<?php

namespace Eshop\DB;

use StORM\ICollection;
use StORM\Repository;

/**
 * @template T of \Eshop\DB\Offer = \Eshop\DB\Offer
 * @extends \StORM\Repository<T>
 */
class OfferRepository extends Repository
{
	/**
	 * @param string $state
	 * @return \StORM\ICollection<\Eshop\DB\Offer>
	 * @throws \InvalidArgumentException
	 */
	public function getOffersByState(OfferState|string $state): ICollection
	{
		if (\is_string($state)) {
			$state = OfferState::tryFrom($state);
		}

		if ($state === null) {
			throw new \InvalidArgumentException("No such state available for offers: $state");
		}

		return match ($state) {
			OfferState::Created => $this->many()
				->where('this.approvedTs IS NULL')
				->where('this.sentTs IS NULL')
				->where('this.canceledTs IS NULL'),
			OfferState::Sent => $this->many()
				->where('this.approvedTs IS NULL')
				->where('this.sentTs IS NOT NULL')
				->where('this.canceledTs IS NULL'),
			OfferState::Approved => $this->many()
				->where('this.approvedTs IS NOT NULL')
				->where('this.sentTs IS NOT NULL')
				->where('this.canceledTs IS NULL'),
			default => $this->many()
				->where('this.canceledTs IS NOT NULL'),
		};
	}
}
