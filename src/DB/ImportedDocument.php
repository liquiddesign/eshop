<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\RelationCollection;

/**
 * @table
 * @index{"name":"importeddocument_id_uniqued","unique":true,"columns":["id"]}
 */
class ImportedDocument extends \StORM\Entity
{
	/**
	 * @column
	 */
	public string $id;

	/**
	 * @column
	 */
	public string $filename;

	/**
	 * @column
	 */
	public string $type;

	/**
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;

	/**
	 * @column{"type":"datetime"}
	 */
	public ?string $importedTs = null;

	/**
	 * @column{"type":"datetime"}
	 */
	public ?string $exportedTs = null;

	/**
	 * @relationNxN{"sourceViaKey":"fk_importedDocument","targetViaKey":"fk_order","via":"eshop_importeddocument_nxn_eshop_order"}
	 * @var \StORM\RelationCollection<\Eshop\DB\Order>
	 */
	public RelationCollection $orders;
}
