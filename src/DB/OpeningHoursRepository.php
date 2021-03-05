<?php

declare(strict_types=1);

namespace Eshop\DB;

use IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\OpeningHours>
 */
class OpeningHoursRepository extends \StORM\Repository implements IGeneralRepository
{
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('day');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		return $this->many()->orderBy(['day']);
	}
}
