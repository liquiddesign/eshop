<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\Address>
 */
class AddressRepository extends \StORM\Repository
{
	public function createNew(array $values): Address
	{
		return $this->createOne($values);
	}
}
