<?php

namespace Eshop\Services;

use Base\Bridges\AutoWireService;
use Base\ShopsConfig;
use Eshop\Admin\SettingsPresenter;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
use Web\DB\SettingRepository;

class SettingsService implements AutoWireService
{
	/**
	 * @var array<string, \Eshop\DB\CategoryType>|false
	 */
	protected array|false $cachedCategoryTypes = false;

	public function __construct(
		protected readonly ShopsConfig $shopsConfig,
		protected readonly SettingRepository $settingRepository,
		protected readonly CategoryRepository $categoryRepository,
		protected readonly CategoryTypeRepository $categoryTypeRepository,
	) {
	}

	/**
	 * Get category main types by shops. If no shops, return main type if possible or first category type based on priority.
	 * @return array<string, \Eshop\DB\CategoryType>
	 */
	public function getCategoryMainTypes(): array
	{
		if ($this->cachedCategoryTypes !== false) {
			return $this->cachedCategoryTypes;
		}

		$categoryTypes = [];

		if ($shops = $this->shopsConfig->getAvailableShops()) {
			foreach ($shops as $shop) {
				$setting = $this->settingRepository->getValueByName(SettingsPresenter::MAIN_CATEGORY_TYPE . '_' . $shop->getPK());

				if (!$setting) {
					continue;
				}

				$categoryTypes[] = $setting;
			}

			return $this->cachedCategoryTypes = $this->categoryTypeRepository->many()->where('this.uuid', $categoryTypes)->toArray();
		}

		$categoryTypes = $this->categoryTypeRepository->many()->where('this.uuid', 'main')->toArray();

		if ($categoryTypes) {
			return $this->cachedCategoryTypes = $categoryTypes;
		}

		$categoryTypes = $this->categoryTypeRepository->many()->setOrderBy(['priority'])->setTake(1)->toArray();

		if ($categoryTypes) {
			return $this->cachedCategoryTypes = $categoryTypes;
		}

		return $this->cachedCategoryTypes = [];
	}

	/**
	 * @return array<string>
	 */
	public function getAllDefaultUnregisteredGroups(): array
	{
		return $this->settingRepository->many()->where('this.name LIKE :s', ['s' => SettingsPresenter::DEFAULT_UNREGISTERED_GROUP . '%'])->toArrayOf('value', toArrayValues: true);
	}
}
