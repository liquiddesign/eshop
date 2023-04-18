<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\ShopperUser;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Review>
 */
class ReviewRepository extends \StORM\Repository
{
	public function __construct(DIConnection $connection, SchemaManager $schemaManager, private readonly ShopperUser $shopperUser)
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
	 * @param callable $onReviewProcessed
	 * @param int $days Days from product buyed
	 * @param int $maxReminders Max count of tries
	 * @return array<\Eshop\DB\Review>
	 */
	public function getReviewsToBeSent(callable $onReviewProcessed, int $days = 10, int $maxReminders = 1): array
	{
		$reviews = $this->getReviewsToBeSentCollection($days, $maxReminders)->toArray();

		/** @var \Eshop\DB\Review $review */
		foreach ($reviews as $review) {
			try {
				$onReviewProcessed($review);

				$review->update([
					'remindersSentCount' => $review->remindersSentCount + 1,
				]);
			} catch (\Throwable $e) {
			}
		}

		return $reviews;
	}

	public function filterReviewedReviews(Collection $collection): void
	{
		$collection->where('this.score IS NOT NULL AND this.reviewedTs IS NOT NULL');
	}

	/**
	 * @return \StORM\Collection<\Eshop\DB\Review>
	 */
	public function getReviewedReviews(): Collection
	{
		return $this->many()->where('this.score IS NOT NULL AND this.reviewedTs IS NOT NULL');
	}

	public function getTotalReviewsCount(): int
	{
		return (int) $this->getReviewedReviews()->select(['totalCount' => 'COUNT(this.uuid)'])->firstValue('totalCount');
	}

	public function getAverageReviewsScore(): float
	{
		return (float) $this->getReviewedReviews()->select(['averageScore' => 'AVG(this.score)'])->firstValue('averageScore');
	}

	/**
	 * @return float Recommendation percent based on reviews with score bigger than or equal to middle of min and max score.
	 */
	public function getRecommendationPercentOfReviews(): float
	{
		$total = $this->getTotalReviewsCount();

		return $total > 0 ? ((float) $this->getReviewedReviews()
				->select(['recommendationPercent' => 'COUNT(this.uuid)'])
				->where('this.score >= :s', ['s' => $this->shopperUser->getReviewsMiddleScore()])
				->firstValue('recommendationPercent')) /
			$total * 100 : 0;
	}

	/**
	 * @param int $days
	 * @param int $maxReminders
	 * @return \StORM\Collection<\Eshop\DB\Review>
	 */
	private function getReviewsToBeSentCollection(int $days, int $maxReminders): Collection
	{
		return $this->many()->where(
			'this.score IS NULL AND
			this.remindersSentCount < :maxReminders AND
			UNIX_TIMESTAMP(DATE_ADD(this.createdTs, INTERVAL :days day)) <= UNIX_TIMESTAMP(NOW())',
			[
				'maxReminders' => $maxReminders,
				'days' => $days,
			],
		);
	}
}
