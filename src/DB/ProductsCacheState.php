<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * State of products caches
 * @table
 */
class ProductsCacheState extends \StORM\Entity
{
	/**
	 * @column{"type":"enum","length":"'empty','warming','ready'"}
	 * @var 'empty'|'warming'|'ready'
	 */
	public string $state = 'empty';
}
