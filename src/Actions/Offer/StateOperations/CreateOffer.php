<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Carbon\Carbon;
use Eshop\Actions\Offer\Code\GenerateOfferCode;
use Eshop\DB\Offer;
use Eshop\DB\OfferRepository;
use Eshop\DB\Order;
use StORM\Connection;
use Tracy\Debugger;
use Tracy\ILogger;

class CreateOffer extends \Base\BaseAction
{
	public function __construct(
		private readonly GenerateOfferCode $generateOfferCode,
		private readonly Connection $connection,
		private readonly OfferRepository $offerRepository,
	) {
	}

	/**
	 * @throws \Exception
	 */
	public function execute(Order $order): Offer
	{
		$maxAttempts = 5;

		$lastException = null;

		for ($i = 0; $i < $maxAttempts; $i++) {
			try {
				$inTransaction = $this->connection->beginTransaction();

				$offer = $this->offerRepository->createOne([
					'code' => $this->generateOfferCode->execute(),
					'order' => $order->getPK(),
					'validFromTs' => Carbon::now()->toDateString(),
					'validUntilTs' => Carbon::now()->addDays(14)->toDateString(),
				]);

				if ($inTransaction) {
					$this->connection->commit();
				}

				return $offer;
			} catch (\Exception $e) {
				$lastException = $e;
			}
		}

		Debugger::log($lastException, ILogger::EXCEPTION);

		throw $lastException;
	}
}
