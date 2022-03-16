<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Nette\Application\ApplicationException;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\AttributeValue>
 */
class AttributeValueRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @inheritDoc
	 */
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

	/**
	 * @param string $query
	 * @param int|null $page
	 * @param int $onPage
	 * @return array<string, array<int|string, array<string, mixed>|bool>>
	 */
	public function getAttributesForAdminAjax(string $query, ?int $page = null, int $onPage = 5): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		$attributes = $this->getCollection(true)
			->join(['attribute' => 'eshop_attribute'], 'this.fk_attribute = attribute.uuid')
			->where("this.label$mutationSuffix LIKE :q OR attribute.name$mutationSuffix LIKE :q ", ['q' => "%$query%"])
			->setPage($page ?? 1, $onPage)
			->select(['fullname' => "CONCAT(attribute.name$mutationSuffix, ' - ', this.label$mutationSuffix)"])
			->toArrayOf('fullname');

		$payload = [];
		$payload['results'] = [];

		foreach ($attributes as $pk => $label) {
			$payload['results'][] = [
				'id' => $pk,
				'text' => $label,
			];
		}

		$payload['pagination'] = ['more' => \count($attributes) === $onPage];

		return $payload;
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

	public function getImage(string $basePath, $attributeValue, string $size = 'detail'): ?string
	{
		if (!$attributeValue instanceof AttributeValue) {
			if (!$attributeValue = $this->one($attributeValue)) {
				return null;
			}
		}

		if (!\in_array($size, ['origin', 'detail', 'thumb'])) {
			throw new ApplicationException('Invalid product image size: ' . $size);
		}

		$dir = AttributeValue::IMAGE_DIR;

		return $attributeValue->imageFileName ? "$basePath/userfiles/$dir/$size/$attributeValue->imageFileName" : "$basePath/public/img/no-image.png";
	}
}
