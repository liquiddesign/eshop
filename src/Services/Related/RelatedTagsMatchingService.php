<?php

declare(strict_types=1);

namespace Eshop\Services\Related;

use Base\Bridges\AutoWireService;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\Related;
use Eshop\DB\RelatedRepository;
use Eshop\DB\RelatedType;
use Eshop\DB\RelatedTypeRepository;
use Nette\Utils\Strings;

/**
 * Service for matching products via relatedTags when Related.slave is null.
 * Works specifically with tonerForPrinter RelatedType.
 */
class RelatedTagsMatchingService implements AutoWireService
{
	private const TONER_FOR_PRINTER_CODE = 'tonerForPrinter';

	public function __construct(
		private readonly ProductRepository $productRepository,
		private readonly RelatedRepository $relatedRepository,
		private readonly RelatedTypeRepository $relatedTypeRepository,
	) {
	}

	/**
	 * Find products that match a Related record's slaveName via their relatedTags.
	 * Only applies when Related.slave is null and slaveName is filled.
	 * @return array<\Eshop\DB\Product>
	 */
	public function getProductsByRelatedSlaveName(Related $related): array
	{
		if ($related->slave !== null || $related->slaveName === null || $related->slaveName === '') {
			return [];
		}

		$tonerType = $this->getTonerForPrinterType();

		if ($tonerType === null || $related->getValue('type') !== $tonerType->getPK()) {
			return [];
		}

		return $this->findProductsByTag($related->slaveName);
	}

	/**
	 * Find all products where one of their relatedTags matches (case-insensitive) any of the given tags.
	 * Batch variant of findProductsByTag() — 1 SQL query for multiple tags.
	 * @param array<string> $tags
	 * @return array<string, array<string, \Eshop\DB\Product>> mapping normalized tag => [productPK => Product]
	 */
	public function findProductsByTags(array $tags): array
	{
		$normalizedTags = \array_unique(\array_filter(\array_map(
			static fn(string $t): string => \mb_strtolower(Strings::trim($t)),
			$tags,
		), static fn(string $t): bool => $t !== ''));

		if ($normalizedTags === []) {
			return [];
		}

		$orConditions = [];
		$params = [];

		foreach ($normalizedTags as $i => $tag) {
			$exact = "tagExact$i";
			$start = "tagStart$i";
			$end = "tagEnd$i";
			$middle = "tagMiddle$i";
			$orConditions[] = "(LOWER(this.relatedTags) = :$exact OR LOWER(this.relatedTags) LIKE :$start OR LOWER(this.relatedTags) LIKE :$end OR LOWER(this.relatedTags) LIKE :$middle)";
			$params[$exact] = $tag;
			$params[$start] = $tag . ',%';
			$params[$end] = '%,' . $tag;
			$params[$middle] = '%,' . $tag . ',%';
		}

		/** @var array<string, \Eshop\DB\Product> $matchedProducts */
		$matchedProducts = $this->productRepository->getProducts()
			->where('(' . \implode(' OR ', $orConditions) . ')', $params)
			->toArray();

		$result = \array_fill_keys(\array_values($normalizedTags), []);

		foreach ($matchedProducts as $product) {
			$productTags = $this->parseRelatedTags($product->relatedTags);

			foreach ($productTags as $pt) {
				$normalized = \mb_strtolower($pt);

				if (!isset($result[$normalized])) {
					continue;
				}

				$result[$normalized][(string) $product->getPK()] = $product;
			}
		}

		return $result;
	}

	/**
	 * Find all products where one of their relatedTags exactly matches (case-insensitive) the given tag.
	 * @return array<\Eshop\DB\Product>
	 */
	public function findProductsByTag(string $tag): array
	{
		$normalizedTag = \mb_strtolower(Strings::trim($tag));

		if ($normalizedTag === '') {
			return [];
		}

		$result = $this->findProductsByTags([$tag]);

		return $result[$normalizedTag] ?? [];
	}

	/**
	 * Get slave products for a master product, combining both:
	 * 1. Direct slave relations (fk_slave IS NOT NULL)
	 * 2. Tag-matched products (fk_slave IS NULL but slaveName matches relatedTags)
	 * @return array<\Eshop\DB\Product>
	 */
	public function getSlaveProductsIncludingTagMatched(
		Product|string $masterProduct,
		bool $onlyVisible = false,
	): array {
		$tonerType = $this->getTonerForPrinterType();

		if ($tonerType === null) {
			return [];
		}

		$masterPK = $masterProduct instanceof Product ? $masterProduct->getPK() : $masterProduct;

		/** @var array<\Eshop\DB\Product> $directSlaveArray */
		$directSlaveArray = $onlyVisible
			? ($this->productRepository->getSlaveProductsByRelationAndMasterVisible($tonerType, $masterProduct)?->toArray() ?? [])
			: ($this->productRepository->getSlaveProductsByRelationAndMaster($tonerType, $masterProduct)?->toArray() ?? []);

		$nameOnlyRelations = $this->relatedRepository->getCollection()
			->where('this.fk_master', $masterPK)
			->where('this.fk_type', $tonerType->getPK())
			->where('this.fk_slave IS NULL')
			->where('this.slaveName IS NOT NULL')
			->where("this.slaveName != ''")
			->where('this.hidden', false)
			->toArray();

		// Collect all slaveNames for batch query
		$slaveNames = [];

		foreach ($nameOnlyRelations as $related) {
			if ($related->slaveName !== null && $related->slaveName !== '') {
				$slaveNames[] = $related->slaveName;
			}
		}

		/** @var array<string, \Eshop\DB\Product> $tagMatchedProducts */
		$tagMatchedProducts = [];

		if ($slaveNames !== []) {
			$matchedByTag = $this->findProductsByTags($slaveNames);

			foreach ($matchedByTag as $products) {
				foreach ($products as $pk => $product) {
					if (!isset($directSlaveArray[$pk]) && !isset($tagMatchedProducts[$pk])) {
						$tagMatchedProducts[$pk] = $product;
					}
				}
			}
		}

		if ($onlyVisible && $tagMatchedProducts !== []) {
			/** @var array<\Eshop\DB\Product> $tagMatchedProducts */
			$tagMatchedProducts = $this->productRepository->getProducts()
				->where('this.uuid', \array_keys($tagMatchedProducts))
				->where('this.hidden', false)
				->toArray();
		}

		return \array_merge($directSlaveArray, $tagMatchedProducts);
	}

	/**
	 * Reverse lookup: Given a product with relatedTags, find master products
	 * that have Related records where slaveName matches one of this product's tags.
	 * @return array<\Eshop\DB\Product>
	 */
	public function getMasterProductsByProductTags(Product|string $product): array
	{
		$tonerType = $this->getTonerForPrinterType();

		if ($tonerType === null) {
			return [];
		}

		$productEntity = $product instanceof Product
			? $product
			: $this->productRepository->one($product);

		if ($productEntity === null || $productEntity->relatedTags === null || $productEntity->relatedTags === '') {
			return [];
		}

		$tags = $this->parseRelatedTags($productEntity->relatedTags);

		if ($tags === []) {
			return [];
		}

		$relatedRecords = $this->relatedRepository->getCollection()
			->where('this.fk_type', $tonerType->getPK())
			->where('this.fk_slave IS NULL')
			->where('this.hidden', false);

		$orConditions = [];
		$params = [];

		foreach ($tags as $i => $tag) {
			$paramName = "tag$i";
			$orConditions[] = "LOWER(this.slaveName) = :$paramName";
			$params[$paramName] = \mb_strtolower($tag);
		}

		$relatedRecords->where('(' . \implode(' OR ', $orConditions) . ')', $params);

		$masterPKs = $relatedRecords->toArrayOf('master', [], true);

		if ($masterPKs === []) {
			return [];
		}

		return $this->productRepository->getProducts()
			->where('this.uuid', $masterPKs)
			->toArray();
	}

	/**
	 * Parse relatedTags string into array of normalized tags.
	 * @return array<string>
	 */
	public function parseRelatedTags(string|null $relatedTags): array
	{
		if ($relatedTags === null || $relatedTags === '') {
			return [];
		}

		return \array_filter(
			\array_map(
				static fn(string $tag): string => Strings::trim($tag),
				\explode(',', $relatedTags),
			),
			static fn(string $tag): bool => $tag !== '',
		);
	}

	/**
	 * Get slave products for a master product with matching tags.
	 * @return array<\Eshop\DB\Product>
	 */
	public function getSlaveProductsWithMatchingTags(
		RelatedType|string $relatedType,
		Product|string $product,
		bool $onlyVisible = false,
	): array {
		$productsFromRepository = $this->productRepository
			->getSlaveProductsForTagsMatching($relatedType, $product, $onlyVisible);

		if ($productsFromRepository !== null) {
			return $productsFromRepository;
		}

		return $this->getSlaveProductsIncludingTagMatched($product, $onlyVisible);
	}

	private function getTonerForPrinterType(): RelatedType|null
	{
		return $this->relatedTypeRepository->one(self::TONER_FOR_PRINTER_CODE);
	}
}
