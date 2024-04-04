<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\ShopsConfig;
use Common\DB\IGeneralRepository;
use Eshop\Admin\SettingsPresenter;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;
use Web\DB\SettingRepository;

/**
 * @extends \StORM\Repository<\Eshop\DB\CustomerGroup>
 */
class CustomerGroupRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * Method CustomerGroupRepository::getUnregisteredGroup returns group by setting. If no setting available, try to find group by this constant.
	 */
	public const UNREGISTERED_PK = 'unregistred';
	
	protected CustomerGroup|null|false $unregisteredGroup = false;

	protected CustomerGroup|null|false $defaultRegistrationGroup = false;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, protected readonly ShopsConfig $shopsConfig, protected readonly SettingRepository $settingRepository)
	{
		parent::__construct($connection, $schemaManager);
	}

	public function getUnregisteredGroup(): CustomerGroup
	{
		if ($this->unregisteredGroup !== false) {
			return $this->unregisteredGroup;
		}

		$defaultGroupSetting = $this->settingRepository->getValueByNameWithShop(SettingsPresenter::DEFAULT_UNREGISTERED_GROUP);

		if (!$defaultGroupSetting) {
			$defaultGroupSetting = $this::UNREGISTERED_PK;
		}

		return $this->unregisteredGroup = $this->one($defaultGroupSetting, true);
	}

	public function getDefaultRegistrationGroup(): ?CustomerGroup
	{
		if ($this->defaultRegistrationGroup !== false) {
			return $this->defaultRegistrationGroup;
		}

		$groupQuery = $this->many()->where('defaultAfterRegistration', true);

		$this->shopsConfig->filterShopsInShopEntityCollection($groupQuery, showOnlyEntitiesWithSelectedShops: true);

		return $this->defaultRegistrationGroup = $groupQuery->first();
	}

	/**
	 * @return array<string>
	 */
	public function getRegisteredGroupsArray(): array
	{
		return $this->many()->where('uuid != :s', ['s' => self::UNREGISTERED_PK])->toArrayOf('name');
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true, bool $showUnregistered = true): array
	{
		$collection = $this->getCollection($includeHidden);

		if (!$showUnregistered) {
			$collection->where('uuid != :s', ['s' => self::UNREGISTERED_PK]);
		}

		return $this->toArrayForSelect($collection);
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many()->orderBy(['name']);
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\CategoryType> $collection
	 * @return array<string>
	 */
	public function toArrayForSelect(Collection $collection): array
	{
		return $this->shopsConfig->shopEntityCollectionToArrayOfFullName($this->shopsConfig->selectFullNameInShopEntityCollection($collection, oldSystemicProperty: true));
	}
}
