<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\MinimalOrderValue>
 */
class MinimalOrderValueRepository extends \StORM\Repository
{
	public function getMinimalOrderValue(CustomerGroup $group, Currency $currency): ?MinimalOrderValue
	{
		return $this->one(['fk_customerGroup' => $group->getPK(), 'fk_currency' => $currency->getPK()], false);
	}
}
