<?php

namespace Eshop\Services\Attribute;

use Base\Bridges\AutoWireService;
use Eshop\DB\Attribute;
use Eshop\DB\AttributeValueRepository;
use StORM\DIConnection;

readonly class AttributeNumericService implements AutoWireService
{
	public function __construct(
		protected AttributeValueRepository $attributeValueRepository,
		protected DIConnection $connection,
	) {
	}

	public function getMax(Attribute $attribute): float|int|null
	{
		if (!$attribute->showNumericSlider) {
			return null;
		}

		$mutationSuffix = $this->connection->getMutationSuffix();

		return $this->attributeValueRepository->many()
			->where('this.fk_attribute', $attribute->getPK())
			->setSelect(['max' => "MAX(CAST(this.label$mutationSuffix AS SIGNED))"])
			->firstValue('max');
	}

	public function getMin(Attribute $attribute): float|int|null
	{
		if (!$attribute->showNumericSlider) {
			return null;
		}

		$mutationSuffix = $this->connection->getMutationSuffix();

		return $this->attributeValueRepository->many()
			->where('this.fk_attribute', $attribute->getPK())
			->setSelect(['min' => "MIN(CAST(this.label$mutationSuffix AS SIGNED))"])
			->firstValue('min');
	}
}
