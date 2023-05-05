<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\Repository\GeneralRepositoryHelpers;
use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\VisibilityList>
 */
class VisibilityListRepository extends \StORM\Repository implements IGeneralRepository
{
	public function __construct(DIConnection $connection, SchemaManager $schemaManager,)
	{
		parent::__construct($connection, $schemaManager);
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->toArrayForSelect($this->getCollection($includeHidden));
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\CategoryType> $collection
	 * @return array<string>
	 */
	public function toArrayForSelect(Collection $collection): array
	{
		return GeneralRepositoryHelpers::toArrayOfFullName(GeneralRepositoryHelpers::selectFullName($collection));
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many()->orderBy(['priority', 'name']);
	}
}
