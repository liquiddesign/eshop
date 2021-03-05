<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\DisplayAmount>
 */
class DisplayAmountRepository extends \StORM\Repository
{
	public function getArrayForSelect():array
	{
		return $this->many()->orderBy(['label'])->toArrayOf('label');
	}
}
