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
		private string $applicationId,
		private string $adminApiKey,
		protected IRequest $httpRequest,
		protected ProductRepository $productRepository,
		protected SettingRepository $settingRepository,
		protected CategoryRepository $categoryRepository,
		private string $indexPrefix = '',
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
