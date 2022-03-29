<?php

declare(strict_types=1);

namespace Eshop\DB;

use Messages\DB\TemplateRepository;
use Nette\Mail\Mailer;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Review>
 */
class ReviewRepository extends \StORM\Repository
{
	/** @inject */
	public TemplateRepository $templateRepository;

	/** @inject */
	public Mailer $mailer;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager)
	{
		parent::__construct($connection, $schemaManager);
	}

	public function createReviewsFromOrder(Order $order): void
	{
		$purchase = $order->purchase;

		foreach ($order->purchase->getItems() as $item) {
			if (!$item->product) {
				continue;
			}

			$this->createOne([
				'customerFullName' => $purchase->fullname,
				'customerEmail' => $purchase->email,
				'customer' => $purchase->customer ? $purchase->customer->getPK() : null,
				'product' => $item->product->getPK(),
			], null, true);
		}
	}

	/**
	 * @param int $days Days from product buyed
	 * @param int $maxReminders Max count of tries
	 * @return \StORM\Collection<\Eshop\DB\Review>
	 */
	public function getReviewsToBeSent(int $days = 10, int $maxReminders = 1): Collection
	{
		$collection = $this->many()->where(
			'this.score IS NULL AND
			this.remindersSentCount < :maxReminders AND
			UNIX_TIMESTAMP(DATE_ADD(this.createdTs, INTERVAL :days day)) <= UNIX_TIMESTAMP(NOW())',
			[
				'maxReminders' => $maxReminders,
				'days' => $days,
			],
		);

		/** TODO group by customer */

		while ($review = $collection->fetch()) {
			/** @var \Eshop\DB\Review $review */

			try {
				$emailVariables = $review->getEmailVariables();

				$message = $this->templateRepository->createMessage('review.notification', $emailVariables, $emailVariables['customerEmail']);

				$this->mailer->send($message);

				$review->update([
					'remindersSentCount' => $review->remindersSentCount + 1,
				]);
			} catch (\Throwable $e) {
			}
		}

		return $collection->clear();
	}
}
