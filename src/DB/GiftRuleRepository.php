<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\GiftRule>
 */
class GiftRuleRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * Získá aktivní pravidlo pro danou cenu objednávky
	 */
	public function getActiveRuleForPrice(float $orderPrice, Currency $currency): ?GiftRule
	{
		return $this->many()
			->where('this.active', true)
			->where('this.fk_currency', $currency->getPK())
			->where('this.priceFrom <= :priceFrom AND this.priceTo >= :priceTo', [
				'priceFrom' => $orderPrice,
				'priceTo' => $orderPrice,
			])
			->orderBy(['this.priority' => 'ASC'])
			->first();
	}

	/**
	 * Získá všechna aktivní pravidla
	 * @return \StORM\Collection<\Eshop\DB\GiftRule>
	 */
	public function getActiveRules(): Collection
	{
		return $this->many()
			->where('this.active', true)
			->orderBy(['this.priority' => 'ASC']);
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		unset($includeHidden);

		return $this->many()
			->orderBy(['this.name' => 'ASC'])
			->toArrayOf('name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('this.active', true);
		}

		return $collection->orderBy(['this.priority' => 'ASC']);
	}
}
