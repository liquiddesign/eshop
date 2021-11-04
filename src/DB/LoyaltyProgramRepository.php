<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;
use StORM\DIConnection;
use StORM\ICollection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\LoyaltyProgram>
 */
class LoyaltyProgramRepository extends \StORM\Repository implements IGeneralRepository
{
	private LoyaltyProgramDiscountLevelRepository $loyaltyProgramDiscountLevelRepository;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, LoyaltyProgramDiscountLevelRepository $loyaltyProgramDiscountLevelRepository)
	{
		parent::__construct($connection, $schemaManager);

		$this->loyaltyProgramDiscountLevelRepository = $loyaltyProgramDiscountLevelRepository;
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('name');
	}

	public function getLevelsByProgram(LoyaltyProgram $loyaltyProgram): Collection
	{
		return $this->loyaltyProgramDiscountLevelRepository->many()->where('this.fk_loyaltyProgram', $loyaltyProgram->getPK());
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('IF (validFrom IS NOT NULL AND validTo IS NOT NULL,
			validFrom <= NOW() AND NOW() <= validTo,
			IF(validFrom IS NOT NULL OR validTo IS NOT NULL,
			(validFrom IS NOT NULL AND validFrom <= NOW()) OR (validTo IS NOT NULL AND NOW() <= validTo),
			TRUE)
			)');
		}
		
		return $collection->orderBy(["name$suffix"]);
	}
}
