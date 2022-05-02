<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Latte\Loaders\StringLoader;
use Latte\Sandbox\SecurityPolicy;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\UIMacros;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\RelatedType>
 */
class RelatedTypeRepository extends \StORM\Repository implements IGeneralRepository
{
	private LatteFactory $latteFactory;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, LatteFactory $latteFactory)
	{
		parent::__construct($connection, $schemaManager);

		$this->latteFactory = $latteFactory;
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true, bool $extended = true): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		return $this->getCollection($includeHidden)
			->select(['fullName' => "IF(this.systemic = 1 OR this.systemicLock > 0, CONCAT(name$mutationSuffix, ' (', code, ', systémový)'), CONCAT(name$mutationSuffix, ' (', code, ')'))"])
			->toArrayOf($extended ? 'fullName' : 'name');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('this.hidden', false);
		}

		return $collection->orderBy(["name$suffix"]);
	}

	public function getCartTypes(bool $includeHidden = false): Collection
	{
		return $this->getCollection($includeHidden)->where('this.showCart', true);
	}

	public function getDetailTypes(bool $includeHidden = false): Collection
	{
		return $this->getCollection($includeHidden)->where('this.showDetail', true)->where('this.showAsSet', false);
	}

	public function getSearchTypes(bool $includeHidden = false): Collection
	{
		return $this->getCollection($includeHidden)->where('this.showSearch', true);
	}

	public function getSetTypes(bool $includeHidden = false): Collection
	{
		return $this->getCollection($includeHidden)->where('this.showAsSet', true);
	}

	/**
	 * Used to check if content is valid by Latte with default values.
	 * @param string|null $content
	 */
	public function isDefaultContentValid(?string $content): bool
	{
		if ($content === null) {
			return true;
		}

		$policy = SecurityPolicy::createSafePolicy();

		$latte = $this->latteFactory->create();
		UIMacros::install($latte->getCompiler());
		$latte->setLoader(new StringLoader());
		$latte->setPolicy($policy);
		$latte->setSandboxMode();

		$params = [
			'productName' => '',
		];

		try {
			$latte->renderToString($content, $params);

			return true;
		} catch (\Throwable $e) {
			\bdump($e);

			return false;
		}
	}
}
