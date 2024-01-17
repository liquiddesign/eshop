<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\ShopsConfig;
use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\CustomerGroup>
 */
class CustomerGroupRepository extends \StORM\Repository implements IGeneralRepository
{
	public const UNREGISTERED_PK = 'unregistred';
	
	private ?CustomerGroup $unregisteredGroup;

	private CustomerGroup|null|false $defaultRegistrationGroup = false;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, protected readonly ShopsConfig $shopsConfig)
	{
		parent::__construct($connection, $schemaManager);
	}

	public function getUnregisteredGroup(): CustomerGroup
	{
		return $this->unregisteredGroup ??= $this->one(self::UNREGISTERED_PK, true);
	}

	public function getDefaultRegistrationGroup(): ?CustomerGroup
	{
		if ($this->defaultRegistrationGroup !== false) {
			return $this->defaultRegistrationGroup;
		}

		$groupQuery = $this->many()->where('defaultAfterRegistration', true);

		$this->shopsConfig->filterShopsInShopEntityCollection($groupQuery, showOnlyEntitiesWithSelectedShops: true);

		return $this->defaultRegistrationGroup = $groupQuery->first();
	}

	/**
	 * @return array<string>
	 */
	public function getRegisteredGroupsArray(): array
	{
		return $this->many()->where('uuid != :s', ['s' => self::UNREGISTERED_PK])->toArrayOf('name');
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true, bool $showUnregistered = true): array
	{
		$collection = $this->getCollection($includeHidden);

		if (!$showUnregistered) {
			$collection->where('uuid != :s', ['s' => self::UNREGISTERED_PK]);
		}

		return $collection->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many()->orderBy(['name']);
	}
}
