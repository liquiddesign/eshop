<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Collection;

interface IGeneralRepository
{
	/**
	 * @param bool $includeHidden
	 * @return string[]
	 */
	public function getArrayForSelect(bool $includeHidden = true): array;
	
	public function getCollection(bool $includeHidden = false): Collection;
}