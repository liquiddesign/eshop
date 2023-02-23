<?php

declare(strict_types=1);

namespace Eshop\Services;

use Algolia\AlgoliaSearch\SearchClient;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ProductRepository;
use Nette\Http\IRequest;
use Web\DB\SettingRepository;

class Algolia
{
	protected string $baseUrl;

	private ?SearchClient $client = null;

	public function __construct(
		/** @codingStandardsIgnoreStart PHP 8.0 features */
		private string $applicationId,
		private string $adminApiKey,
		protected IRequest $httpRequest,
		protected ProductRepository $productRepository,
		protected SettingRepository $settingRepository,
		protected CategoryRepository $categoryRepository,
		private string $indexPrefix = '',
		/** @codingStandardsIgnoreEnd */
	) {
		$this->baseUrl = $httpRequest->getUrl()->getBaseUrl();
	}

	public function getClient(): SearchClient
	{
		if (!$this->applicationId || !$this->adminApiKey) {
			throw new \Exception('Algolia client can not be initialized. Properties "applicationId" or "adminApiKey" are not set.');
		}

		if ($this->client) {
			return $this->client;
		}

		return SearchClient::create($this->applicationId, $this->adminApiKey);
	}

	/**
	 * @deprecated use uploadValues instead
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
						->join(['category' => 'eshop_category'], 'productXCategory.fk_category = category.uuid')
						->where('category.path LIKE :s', ['s' => "$category->path%"]);
				}

				/** @var \Eshop\DB\Product $record */
				foreach ($records as $record) {
					$algoliaResults[$record->getPK()]['objectID' ] = $record->getPK();

					foreach ($properties as $property) {
						$algoliaResults[$record->getPK()][$property] = ($property === 'page_url' ? $this->baseUrl : '') . $record->$property;
						$algoliaResults[$record->getPK()]['not_' . $property] = (bool) !$record->$property;
					}

					foreach ($mutationalProperties as $property) {
						$prop = $property . '_' . $mutation;
						$algoliaResults[$record->getPK()][$property . '_' . $mutation] = ($property === 'page_url' ? $this->baseUrl : '') . $record->$prop;
						$algoliaResults[$record->getPK()]['not_' . $property . '_' . $mutation] = (bool) !$record->$prop;
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
	 * @deprecated use search instead
	 * @param string $name
	 * @param string $index
	 * @return array<array<array<string>>>
	 */
	public function searchProduct(string $name, string $index = 'products'): array
	{
		try {
			$client = $this->getClient();
		} catch (\Throwable $e) {
			return [];
		}

		$index = $client->initIndex($index);

		return $index->search($name);
	}

	/**
	 * @param array $values Serializable array
	 * @throws \Algolia\AlgoliaSearch\Exceptions\MissingObjectId
	 */
	public function uploadValues(array $values, string $index, bool $clearIndexBeforeUpload = false): void
	{
		$index = $this->getClient()->initIndex($this->indexPrefix . $index);

		if ($clearIndexBeforeUpload) {
			$index->clearObjects();
		}

		$index->saveObjects($values);
	}

	/**
	 * @param string $query
	 * @param string $index
	 * @return array<mixed>
	 * @throws \Exception
	 */
	public function search(string $query, string $index): array
	{
		$index = $this->getClient()->initIndex($this->indexPrefix . $index);

		return $index->search($query);
	}
}
