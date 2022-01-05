<?php

declare(strict_types=1);

namespace Eshop\Integration;

use Algolia\AlgoliaSearch\SearchClient;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ProductRepository;
use Nette\Http\IRequest;
use Web\DB\SettingRepository;

class Algolia
{
	private ?SearchClient $client = null;

	private ProductRepository $productRepository;

	private SettingRepository $settingRepository;

	private CategoryRepository $categoryRepository;

	private string $baseUrl;

	public function __construct(IRequest $httpRequest, ProductRepository $productRepository, SettingRepository $settingRepository, CategoryRepository $categoryRepository)
	{
		$this->productRepository = $productRepository;
		$this->settingRepository = $settingRepository;
		$this->categoryRepository = $categoryRepository;
		$this->baseUrl = $httpRequest->getUrl()->getBaseUrl();

		$applicationId = $settingRepository->one(['name' => 'algoliaApplicationId']);
		$adminApiKey = $settingRepository->one(['name' => 'algoliaAdminApiKey']);

		if (!$applicationId || !$adminApiKey) {
			return;
		}

		$this->client = SearchClient::create($applicationId->value, $adminApiKey->value);
	}

	public function isActive(): bool
	{
		return (bool) $this->client;
	}

	/**
	 * @param array<string, array<string, array<string>>> $indexes
	 * @param string[] $mutations
	 * @throws \Algolia\AlgoliaSearch\Exceptions\MissingObjectId|\StORM\Exception\NotFoundException
	 */
	public function uploadProducts(array $indexes, array $mutations): void
	{
		if (!$this->client) {
			return;
		}

		$category = ($categorySetting = $this->settingRepository->one(['name' => 'algoliaCategory'])) ? $this->categoryRepository->one($categorySetting->value) : null;

		$algoliaResults = [];

		foreach ($indexes as $indexName => $indexData) {
			$properties = $indexData['properties'];
			$mutationalProperties = $indexData['mutationalProperties'];

			foreach ($mutations as $mutation) {
				$records = $this->productRepository->many();

				if ($category) {
					$records->join(['productXCategory' => 'eshop_product_nxn_eshop_category'], 'this.uuid = productXCategory.fk_product')
						->join(['category' => 'eshop_category'], 'productXcategory.fk_category = category.uuid')
						->where('category.path LIKE :s', ['s' => "$category->path%"]);
				}

				/** @var \Eshop\DB\Product $record */
				foreach ($records as $record) {
					$algoliaResults[$record->getPK()]['objectID' ] = $record->getPK();

					foreach ($properties as $property) {
						$algoliaResults[$record->getPK()][$property] = ($property === 'page_url' ? $this->baseUrl : '') . $record->$property;
						$algoliaResults[$record->getPK()]["not_" . $property] = (bool) !$record->$property;
					}

					foreach ($mutationalProperties as $property) {
						$prop = $property . '_' . $mutation;
						$algoliaResults[$record->getPK()][$property.'_'.$mutation] = ($property === 'page_url' ? $this->baseUrl : '') . $record->$prop;
						$algoliaResults[$record->getPK()]["not_".$property.'_'.$mutation] = (bool) !$record->$prop;
					}
				}
			}

			$index = $this->client->initIndex($indexName);
			$index->saveObjects($algoliaResults, [
				'objectIDKey' => 'objectID',
			]);
		}
	}

	/**
	 * @param string $name
	 * @param string $index
	 * @return object[]
	 */
	public function searchProduct(string $name, string $index): array
	{
		if (!$this->client) {
			return [];
		}

		$index = $this->client->initIndex($index);

		return $index->search($name);
	}
}
