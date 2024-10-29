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

	/**
	 * @param \Eshop\DB\Attribute $attribute
	 * @return array<\Eshop\DB\AttributeValue>
	 */
	public function getNumberValues(Attribute $attribute): array
	{
		return $this->attributeValueRepository->many()
			->where('this.fk_attribute', $attribute->getPK())
			->where('this.number IS NOT NULL OR this.numberFrom IS NOT NULL OR this.numberTo IS NOT NULL')
			->toArray();
	}
}
