<?php

declare(strict_types=1);

namespace Eshop\DB;


use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Currency>
 */
class CurrencyRepository extends \StORM\Repository implements IGeneralRepository
{
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('code');
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		return $this->many()->orderBy(['code', "symbol"]);
	}
	
}
