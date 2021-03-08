<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\MinimalOrderValue>
 */
class MinimalOrderValueRepository extends \StORM\Repository
{
	public function getMinimalOrderValue(CustomerGroup $group, Currency $currency)
	{
		return $this->one(['fk_customerGroup' => $group, 'fk_currency' => $currency], false);
	}
}
