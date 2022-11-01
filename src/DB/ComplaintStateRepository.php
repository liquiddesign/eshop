<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\ComplaintState>
 */
class ComplaintStateRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		return $this->many()->orderBy(['sequence', "name$mutationSuffix"]);
	}
}
