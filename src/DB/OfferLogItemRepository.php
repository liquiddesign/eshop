<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Repository;

/**
 * @extends \StORM\Repository<\Eshop\DB\OfferLogItem>
 */
class OfferLogItemRepository extends Repository
{
	public function createLog(Offer $offer, string $operation, ?string $message = null, ?Merchant $merchant = null): OfferLogItem
	{
		return $this->createOne([
			'offer' => $offer->getPK(),
			'operation' => $operation,
			'message' => $message,
			'merchant' => $merchant,
			'merchantFullName' => $merchant !== null ? $merchant->fullname : null,
		]);
	}
}
