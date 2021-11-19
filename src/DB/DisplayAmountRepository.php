<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * @extends \StORM\Repository<\Eshop\DB\DisplayAmount>
 */
class DisplayAmountRepository extends \StORM\Repository
{
	/**
	 * @return string[]
	 */
	public function getArrayForSelect(): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		return $this->many()->orderBy(['priority', "label$mutationSuffix"])->toArrayOf('label');
	}
	
	/**
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getDisplayAmount(int $amount): ?Entity
	{
		return $this->many()->where('amountFrom <= :amount AND amountTo >= :amount', ['amount' => $amount])->orderBy(['priority'])->setTake(1)->first();
	}
}
