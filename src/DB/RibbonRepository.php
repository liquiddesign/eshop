<?php

declare(strict_types=1);

namespace Eshop\DB;

use IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Ribbon>
 */
class RibbonRepository extends \StORM\Repository implements IGeneralRepository
{
	private ?Collection $ribbons = null;
	
	public function getRibbons(): Collection
	{
		return $this->ribbons ??= $this->many()->where('hidden', false)->orderBy(['priority']);
	}
	
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('name');
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		return $this->many()->orderBy(['priority', 'name']);
	}
}
