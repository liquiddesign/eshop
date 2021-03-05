<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\UserGroup>
 */
class CustomerGroupRepository extends \StORM\Repository
{
	public const UNREGISTERED_PK = 'unregistred';

	public function getUnregisteredGroup(): CustomerGroup
	{
		return $this->one(self::UNREGISTERED_PK, true);
	}

	public function getDefaultRegistrationGroup(): ?CustomerGroup
	{
		return $this->many()->where('defaultAfterRegistration = 1')->fetch();
	}

	public function getRegisteredGroupsArray(): array
	{
		return $this->many()->where('uuid != :s', ['s' => self::UNREGISTERED_PK])->toArrayOf('name');
	}

	public function getListForSelect(): array
	{
		return $this->many()->toArrayOf('name');
	}
}