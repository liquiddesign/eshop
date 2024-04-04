<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Latte\Loaders\StringLoader;
use Latte\Sandbox\SecurityPolicy;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\UIExtension;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;
use Web\DB\SettingRepository;

/**
 * @extends \StORM\Repository<\Eshop\DB\RelatedType>
 */
class RelatedTypeRepository extends \StORM\Repository implements IGeneralRepository
{
	private LatteFactory $latteFactory;
	
	private SettingRepository $settingRepository;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, SettingRepository $settingRepository, LatteFactory $latteFactory)
	{
		parent::__construct($connection, $schemaManager);

		$this->latteFactory = $latteFactory;
		$this->connection = $connection;
		$this->settingRepository = $settingRepository;
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
		$policy->allowFilters(['firstLower']);

		$latte = $this->latteFactory->create();
		$latte->addFilter('firstLower', function (string $s): string {
			return Strings::firstLower($s);
		});

		$latte->addExtension(new UIExtension(null));

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
			return false;
		}
	}
	
	public function getAttributeBySettingName(string $settingName): ?RelatedType
	{
		$setting = $this->settingRepository->getValueByName($settingName);
		
		return $setting ? $this->one($setting) : null;
	}
}
