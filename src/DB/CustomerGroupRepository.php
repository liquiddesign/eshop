<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\Admin\CustomerGroupPresenter;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\CustomerGroup>
 */
class CustomerGroupRepository extends \StORM\Repository implements IGeneralRepository
{
	public const UNREGISTERED_PK = 'unregistred';
	
	private ?CustomerGroup $unregisteredGroup;
	
	private ?CustomerGroup $defaultRegistrationGroup;

	public function getUnregisteredGroup(): CustomerGroup
	{
		return $this->unregisteredGroup ??= $this->one(self::UNREGISTERED_PK, true);
	}

	public function getDefaultRegistrationGroup(): ?CustomerGroup
	{
		return $this->defaultRegistrationGroup ??= $this->many()->where('defaultAfterRegistration = 1')->first();
	}

	public function getRegisteredGroupsArray(): array
	{
		return $this->many()->where('uuid != :s', ['s' => self::UNREGISTERED_PK])->toArrayOf('name');
	}

	/**
	 * @deprecated use getArrayForSelect()
	 */
	public function getListForSelect(): array
	{
		return $this->many()->toArrayOf('name');
	}

	public function getArrayForSelect(bool $includeHidden = true, bool $showUnregistered = true): array
	{
		$collection = $this->getCollection($includeHidden);

		if(!$showUnregistered){
			$collection->where('uuid != :s', ['s' => self::UNREGISTERED_PK]);
		}

		return $collection->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$collection = $this->many();

		return $collection->orderBy(["name"]);
	}
}