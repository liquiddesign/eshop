<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\AttributeValue>
 */
class AttributeValueRepository extends \StORM\Repository implements IGeneralRepository
{
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('label');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('this.hidden', false);
		}

		return $collection->orderBy(['this.priority', "this.label$suffix",]);
	}

	public function isValuePairedWithProducts($value): bool
	{
		if (!$value instanceof AttributeValue) {
			if (!$value = $this->one($value)) {
				return false;
			}
		}

		return $this->many()
				->join(['attributeAssign' => 'eshop_attributeassign'], 'this.uuid = attributeAssign.fk_value')
				->where('attributeAssign.fk_value', $value->getPK())
				->count() > 0;
	}
}
