<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\AttributeGroup>
 */
class AttributeGroupRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @param bool $includeHidden
	 * @return array<string>
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		return $this->getCollection($includeHidden)
			->select(['fullName' => "IF(this.systemicLock > 0, CONCAT(name$mutationSuffix, ' (systémový)'), CONCAT(name$mutationSuffix))"])
			->toArrayOf('fullName');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('this.hidden', false);
		}

		return $collection->orderBy(['this.subGroup', 'this.priority', "this.name$mutationSuffix",]);
	}

	/**
	 * @param array<string> $attributes
	 * @return array<mixed>
	 */
	public function getGroupsByAttributes(array $attributes): array
	{
		/** @var \StORM\Collection<\Eshop\DB\AttributeGroup> $collection */
		$collection = $this->getCollection()
			->join(['attributeXgroup' => 'eshop_attributegroup_nxn_eshop_attribute'], 'attributeXgroup.fk_attributegroup = this.uuid')
			->where('attributeXgroup.fk_attribute', $attributes)
			->setGroupBy(['this.uuid']);

		$groups = [];

		while ($group = $collection->fetch()) {
			foreach ((clone $group->attributes)->where('this.uuid', $attributes)->orderBy(['this.priority']) as $attribute) {
				if (!isset($groups[$group->subGroup][$group->getPK()])) {
					$groups[$group->subGroup][$group->getPK()] = ['group' => $group, 'items' => [$attribute->getPK() => $attribute]];
				} else {
					$groups[$group->subGroup][$group->getPK()]['items'][$attribute->getPK()] = $attribute;
				}
			}
		}

		return $groups;
	}
}
