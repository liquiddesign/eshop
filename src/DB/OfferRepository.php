<?php

namespace Eshop\DB;

use Nette\Utils\Arrays;
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
	public function getOffersByState(string $state): ICollection
	{
		if (Arrays::contains(Offer::getAvailableStates(), $state) === false) {
			throw new \InvalidArgumentException("No such state available for offers: $state");
		}

		return match ($state) {
			Offer::STATE_OPEN => $this->many()
				->where('approvedTs IS NULL')
				->where('completedTs IS NULL')
				->where('canceledTs IS NULL'),
			Offer::STATE_RECEIVED => $this->many()
				->where('approvedTs IS NOT NULL')
				->where('completedTs IS NULL')
				->where('canceledTs IS NULL'),
			Offer::STATE_COMPLETED => $this->many()
				->where('approvedTs IS NOT NULL')
				->where('completedTs IS NOT NULL')
				->where('canceledTs IS NULL'),
			Offer::STATE_CANCELED => $this->many()
				->where('canceledTs IS NOT NULL'),
			default => throw new \InvalidArgumentException("No such state available for offers: $state"),
		};
	}
}
