<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Collection;
use StORM\Repository;

/**
 * @extends \StORM\Repository<\Eshop\DB\InvoiceItem>
 */
class InvoiceItemRepository extends Repository
{
	/**
	 * @param \Eshop\DB\Invoice $invoice
	 * @param \Eshop\DB\RelatedType $relatedType
	 * @return \StORM\Collection<\Eshop\DB\InvoiceItem>
	 */
	public function getInvoiceItemsUpsellsByRelatedType(Invoice $invoice, RelatedType $relatedType): Collection
	{
		return $this->many()
			->where('fk_invoice', $invoice->getPK())
			->where('fk_upsell IS NOT NULL')
			->where('fk_product IS NOT NULL')
			->join(['related' => 'eshop_related'], 'this.fk_product = related.fk_slave')
			->where('related.fk_type', $relatedType->getPK());
	}
}
