<?php

declare(strict_types=1);

namespace Eshop\DB;

use JetBrains\PhpStorm\Deprecated;

/**
 * @extends \StORM\Repository<\Eshop\DB\ProductsCacheState>
 */
#[Deprecated('ProductsCache now only has one version which is regularly updated.')]
class ProductsCacheStateRepository extends \StORM\Repository
{
}
