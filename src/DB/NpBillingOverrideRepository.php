<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Repository;

/**
 * @extends \StORM\Repository<\Eshop\DB\NpBillingOverride>
 */
class NpBillingOverrideRepository extends Repository
{
	/**
	 * @return array<string, true>
	 */
	public function getAllIcosAsSet(): array
	{
		$result = [];

		/** @var array<\Eshop\DB\NpBillingOverride> $overrides */
		$overrides = $this->many()->toArray();

		foreach ($overrides as $override) {
			$result[$override->ic] = true;
		}

		return $result;
	}
}
