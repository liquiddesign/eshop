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
				->where('this.canceledTs IS NULL')
				->where('this.completedTs IS NULL')
				->where('this.managerApprovalRequestedTs IS NULL'),
			OfferState::Sent => $this->many()
				->where('this.approvedTs IS NULL')
				->where('this.sentTs IS NOT NULL')
				->where('this.canceledTs IS NULL')
				->where('this.completedTs IS NULL'),
			OfferState::Approved => $this->many()
				->where('this.approvedTs IS NOT NULL')
				->where('this.sentTs IS NOT NULL')
				->where('this.canceledTs IS NULL')
				->where('this.completedTs IS NULL'),
			OfferState::AwaitingManagerApproval => $this->many()
				->where('this.managerApprovalRequestedTs IS NOT NULL')
				->where('this.managerApprovedTs IS NULL')
				->where('this.canceledTs IS NULL')
				->where('this.completedTs IS NULL'),
			OfferState::ManagerApproved => $this->many()
				->where('this.managerApprovedTs IS NOT NULL')
				->where('this.sentTs IS NULL')
				->where('this.canceledTs IS NULL')
				->where('this.completedTs IS NULL'),
			OfferState::Completed => $this->many()
				->where('this.sentTs IS NOT NULL')
				->where('this.approvedTs IS NOT NULL')
				->where('this.completedTs IS NOT NULL')
				->where('this.canceledTs IS NULL'),
			default => $this->many()
				->where('this.canceledTs IS NOT NULL'),
		};
	}

	public function findOfferByCkpPriceList(Pricelist $pricelist): ?Offer
	{
		return $this->many()
			->join(['nxn' => 'eshop_customer_nxn_eshop_pricelist'], 'nxn.fk_customer = this.fk_customer')
			->where('nxn.fk_pricelist', $pricelist->getPK())
			->where('this.offerType', OfferType::Ckp->value)
			->first();
	}
}
