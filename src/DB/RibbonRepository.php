<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\ShopsConfig;
use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Ribbon>
 */
class RibbonRepository extends \StORM\Repository implements IGeneralRepository
{
	private Collection $imageRibbons;

	private Collection $textRibbons;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, private readonly ShopsConfig $shopsConfig)
	{
		parent::__construct($connection, $schemaManager);
	}

	/**
	 * @return \StORM\Collection<\Eshop\DB\Ribbon>
	 */
	public function getImageRibbons(): Collection
	{
		return $this->imageRibbons ??= $this->many()->where('type', 'onlyImage')->where('hidden', false)->orderBy(['priority']);
	}

	/**
	 * @return \StORM\Collection<\Eshop\DB\Ribbon>
	 */
	public function getTextRibbons(): Collection
	{
		return $this->textRibbons ??= $this->many()->where('type', 'normal')->where('hidden', false)->orderBy(['priority']);
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->toArrayForSelect($this->getCollection($includeHidden));
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\Category> $collection
	 * @return array<string>
	 */
	public function toArrayForSelect(Collection $collection): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		return $this->shopsConfig->shopEntityCollectionToArrayOfFullName($this->shopsConfig->selectFullNameInShopEntityCollection(
			$collection,
			selectColumnName: "this.name$mutationSuffix",
			systemic: false,
		));
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('this.hidden', false);
		}

		return $collection->orderBy(['this.priority', "this.name$suffix",]);
	}
}
