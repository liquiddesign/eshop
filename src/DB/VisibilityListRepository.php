<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\ShopsConfig;
use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\VisibilityList>
 */
class VisibilityListRepository extends \StORM\Repository implements IGeneralRepository
{
	public function __construct(DIConnection $connection, SchemaManager $schemaManager, private readonly ShopsConfig $shopsConfig)
	{
		parent::__construct($connection, $schemaManager);
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->toArrayForSelect($this->getCollection($includeHidden));
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\VisibilityList> $collection
	 * @return array<string>
	 */
	public function toArrayForSelect(Collection $collection): array
	{
		return $this->shopsConfig->shopEntityCollectionToArrayOfFullName($this->shopsConfig->selectFullNameInShopEntityCollection($collection));
	}

	/**
	 * @param bool $includeHidden
	 * @return \StORM\Collection<\Eshop\DB\VisibilityList>
	 */
	public function getCollection(bool $includeHidden = false): Collection
	{
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('this.hidden', false);
		}

		return $collection->orderBy(['priority', 'name']);
	}

	/**
	 * @param \Eshop\DB\Customer $customer
	 * @return \StORM\Collection<\Eshop\DB\VisibilityList>
	 */
	public function getVisibilityListsByCustomer(Customer $customer): Collection
	{
		$visibilityLists = $customer->getVisibilityLists();

		$this->shopsConfig->filterShopsInShopEntityCollection($visibilityLists, $customer->shop);
		$visibilityLists->select(['this.id'])->where('this.hidden', false)->orderBy(['this.priority' => 'ASC', 'this.uuid' => 'ASC']);

		return $visibilityLists;
	}

	/**
	 * @param \Eshop\DB\Merchant $merchant
	 * @return \StORM\Collection<\Eshop\DB\VisibilityList>
	 */
	public function getVisibilityListsByMerchant(Merchant $merchant): Collection
	{
		$visibilityLists = $merchant->getVisibilityLists();

		$this->shopsConfig->filterShopsInShopEntityCollection($visibilityLists, $merchant->shop);
		$visibilityLists->select(['this.id'])->where('this.hidden', false)->orderBy(['this.priority' => 'ASC', 'this.uuid' => 'ASC']);

		return $visibilityLists;
	}
}
