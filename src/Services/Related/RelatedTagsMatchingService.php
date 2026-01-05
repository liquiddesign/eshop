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
	 * Find all products where one of their relatedTags exactly matches (case-insensitive) the given tag.
	 * @return array<\Eshop\DB\Product>
	 */
	public function findProductsByTag(string $tag): array
	{
		$normalizedTag = \mb_strtolower(Strings::trim($tag));

		if ($normalizedTag === '') {
			return [];
		}

		return $this->productRepository->getProducts()
			->where(
				'LOWER(this.relatedTags) = :exactTag OR ' .
				'LOWER(this.relatedTags) LIKE :startTag OR ' .
				'LOWER(this.relatedTags) LIKE :endTag OR ' .
				'LOWER(this.relatedTags) LIKE :middleTag',
				[
					'exactTag' => $normalizedTag,
					'startTag' => $normalizedTag . ',%',
					'endTag' => '%,' . $normalizedTag,
					'middleTag' => '%,' . $normalizedTag . ',%',
				],
			)
			->toArray();
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

		/** @var array<\Eshop\DB\Product> $tagMatchedProducts */
		$tagMatchedProducts = [];

		foreach ($nameOnlyRelations as $related) {
			if ($related->slaveName === null || $related->slaveName === '') {
				continue;
			}

			$matched = $this->findProductsByTag($related->slaveName);

			foreach ($matched as $product) {
				if (!isset($directSlaveArray[$product->getPK()]) && !isset($tagMatchedProducts[$product->getPK()])) {
					$tagMatchedProducts[$product->getPK()] = $product;
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
