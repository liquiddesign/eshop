<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Ribbon>
 */
class RibbonRepository extends \StORM\Repository implements IGeneralRepository
{
	private Collection $imageRibbons;
	
	private Collection $textRibbons;
	
	public function getImageRibbons(): Collection
	{
		return $this->imageRibbons ??= $this->many()->where('type', 'onlyImage')->where('hidden', false)->orderBy(['priority']);
	}
	
	public function getTextRibbons(): Collection
	{
		return $this->textRibbons ??= $this->many()->where('type', 'normal')->where('hidden', false)->orderBy(['priority']);
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
