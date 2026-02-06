<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\DB\Shop;
use Base\ShopsConfig;
use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\GiftRule>
 */
class GiftRuleRepository extends \StORM\Repository implements IGeneralRepository
{
	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		private readonly ShopsConfig $shopsConfig,
	) {
		parent::__construct($connection, $schemaManager);
	}

	/**
	 * Získá aktivní pravidlo pro danou cenu objednávky
	 */
	public function getActiveRuleForPrice(
		float $orderPrice,
		Currency $currency,
		Shop|null $shop = null,
		CustomerGroup|null $customerGroup = null,
	): GiftRule|null {
		$collection = $this->many()
			->where('this.active', true)
			->where('this.fk_currency', $currency->getPK())
			->where('this.priceFrom <= :priceFrom AND this.priceTo >= :priceTo', [
				'priceFrom' => $orderPrice,
				'priceTo' => $orderPrice,
			]);

		// Filter by shop (null = all shops)
		$this->shopsConfig->filterShopsInShopEntityCollection($collection, $shop);

		// Filter by customer group (empty = all groups)
		if ($customerGroup !== null) {
			$collection->join(['nxn' => 'eshop_giftrule_nxn_eshop_customergroup'], 'this.uuid = nxn.fk_giftRule', type: 'LEFT');
			$collection->where('nxn.fk_giftRule IS NULL OR nxn.fk_customerGroup = :group', ['group' => $customerGroup->getPK()]);
		}

		return $collection->orderBy(['this.priority' => 'ASC'])->first();
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
	 * Získá aktivní pravidla pro daný obchod
	 * @return \StORM\Collection<\Eshop\DB\GiftRule>
	 */
	public function getActiveRulesForShop(Shop|null $shop = null): Collection
	{
		$collection = $this->many()->where('this.active', true);
		$this->shopsConfig->filterShopsInShopEntityCollection($collection, $shop);

		return $collection->orderBy(['this.priority' => 'ASC']);
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
