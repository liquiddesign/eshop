<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\DisplayDelivery>
 */
class DisplayDeliveryRepository extends \StORM\Repository
{
	/**
	 * @return string[]
	 */
	public function getArrayForSelect(): array
	{
		return $this->many()->orderBy(['label'])->toArrayOf('label');
	}
}
