<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * Recenze
 * @table
 * @index{"name":"review_unique_product_customer","unique":true,"columns":["fk_product","fk_customer"]}
 */
class Review extends \StORM\Entity
{
	/**
	 * @column
	 */
	public ?float $score;

	/**
	 * @column
	 */
	public ?string $text;

	/**
	 * @column
	 */
	public ?string $customerFullName;

	/**
	 * @column
	 */
	public string $customerEmail;

	/**
	 * @column
	 */
	public int $remindersSentCount = 0;

	/**
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * @column{"type":"timestamp"}
	 */
	public ?string $reviewedTs;

	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Product $product;
	
	/**
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"SET NULL"}
	 */
	public ?Customer $customer;

	public function isReviewed(): bool
	{
		return $this->score && $this->reviewedTs;
	}

	/**
	 * @return array<string|float|int|null>
	 */
	public function getEmailVariables(): array
	{
		return [
			'score' => $this->score,
			'text' => $this->text,
			'customerFullName' => $this->customer ? $this->customer->fullname : $this->customerFullName,
			'customerEmail' => $this->customer ? $this->customer->email : $this->customerEmail,
			'productName' => $this->product->name,
		];
	}
}
