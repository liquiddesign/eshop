<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Purchase>
 */
class PurchaseRepository extends \StORM\Repository
{
	public function __construct(DIConnection $connection, SchemaManager $schemaManager, SupplierDeliveryTypeRepository $supplierDeliveryTypeRepository)
	{
		parent::__construct($connection, $schemaManager);

		$this->injectEntityArguments($supplierDeliveryTypeRepository);
	}
}
