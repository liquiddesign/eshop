<?php

declare(strict_types=1);

namespace Eshop\DB;

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
}
