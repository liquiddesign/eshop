<?php

namespace Eshop\Common\Services;

use Base\ShopsConfig;
use Eshop\DB\AmountRepository;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValue;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductContentRepository;
use Eshop\DB\ProductPrimaryCategoryRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\StoreRepository;
use Eshop\DB\VisibilityListItemRepository;
use ForceUTF8\Encoding;
use League\Csv\Reader;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use Tracy\Debugger;

class ProductImporter
{
	public function __construct(
		private readonly ProductRepository $productRepository,
		private readonly AttributeRepository $attributeRepository,
		private readonly StoreRepository $storeRepository,
		private readonly CategoryRepository $categoryRepository,
		private readonly ProducerRepository $producerRepository,
		private readonly AttributeValueRepository $attributeValueRepository,
		private readonly AttributeAssignRepository $attributeAssignRepository,
		private readonly AmountRepository $amountRepository,
		private readonly ShopsConfig $shopsConfig,
		private readonly VisibilityListItemRepository $visibilityListItemRepository,
		private readonly ProductContentRepository $productContentRepository,
		private readonly ProductPrimaryCategoryRepository $productPrimaryCategoryRepository,
	) {
	}

	/**
	 * @param string $filePath
	 * @param string $delimiter
	 * @param bool $addNew
	 * @param bool $overwriteExisting
	 * @param bool $updateAttributes
	 * @param bool $createAttributeValues
	 * @return array<string|int>
	 * @throws \League\Csv\Exception
	 * @throws \League\Csv\InvalidArgument
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function importCsv(
		string $filePath,
		string $delimiter = ';',
		bool $addNew = false,
		bool $overwriteExisting = true,
		bool $updateAttributes = false,
		bool $createAttributeValues = false,
		string $searchCriteria = 'all',
		array $importColumns = [],
	): array {
		$selectedShop = $this->shopsConfig->getSelectedShop();

		$csvData = FileSystem::read($filePath);

		$csvData = Encoding::toUTF8($csvData);
		$reader = Reader::createFromString($csvData);

		$reader->setDelimiter($delimiter);
		$reader->setHeaderOffset(0);
		$mutation = $this->productRepository->getConnection()->getMutation();
		$mutationSuffix = $this->productRepository->getConnection()->getMutationSuffix();

		$producers = $this->producerRepository->many()->setIndex('code')->toArrayOf('uuid');
		$stores = $this->storeRepository->many()->setIndex('code')->toArrayOf('uuid');
		$categoriesCollection = $this->categoryRepository->many()
			->setIndex('code')
			->select(['categoryTypePK' => 'this.fk_type']);

		$categories = [];

		while ($tempCategory = $categoriesCollection->fetch(\stdClass::class)) {
			/** @var \stdClass $tempCategory */

			$categories[$tempCategory->categoryTypePK][$tempCategory->code] = $tempCategory;
		}

		$categoriesCollection->__destruct();

		/** @var array<array<\Eshop\DB\ProductPrimaryCategory>> $productsPrimaryCategories */
		$productsPrimaryCategories = [];
		$productsPrimaryCategoriesCollection = $this->productPrimaryCategoryRepository->many();

		while ($tempProductPrimaryCategory = $productsPrimaryCategoriesCollection->fetch()) {
			$productsPrimaryCategories[$tempProductPrimaryCategory->getValue('product')][$tempProductPrimaryCategory->getValue('categoryType')] = $tempProductPrimaryCategory;
		}

		$productsPrimaryCategoriesCollection->__destruct();

		$productsToDeleteCategories = [];

		$products = $this->productRepository->many()->setSelect([
			'uuid',
			'code',
			'fullCode' => 'CONCAT(code,".",subCode)',
			'ean',
			'name' => "name$mutationSuffix",
			'perex' => "perex$mutationSuffix",
			'supplierContentLock',
			'mpn',
		], [], true)->fetchArray(\stdClass::class);

		$header = $reader->getHeader();

		$parsedHeader = [];
		$attributes = [];

		$groupedAttributeValues = [];
		$attributeValues = $this->attributeValueRepository->many()->setSelect([
			'uuid',
			'label' => "label$mutationSuffix",
			'code',
			'attribute' => 'fk_attribute',
		], [], true)->fetchArray(\stdClass::class);

		foreach ($attributeValues as $attributeValue) {
			if (!isset($groupedAttributeValues[$attributeValue->attribute])) {
				$groupedAttributeValues[$attributeValue->attribute] = [];
			}

			$groupedAttributeValues[$attributeValue->attribute][$attributeValue->uuid] = $attributeValue;
		}

		unset($attributeValues);

		$allowedVisibilityColumns = [
			'hidden' => 'Skryto',
			'hiddenInMenu' => 'Skryto v menu a vyhledávání',
			'unavailable' => 'Neprodejné',
			'recommended' => 'Doporučeno',
			'priority' => 'Priorita',
		];

		$parsedVisibilityColumns = [];

		foreach ($header as $headerItem) {
			// Find column by key or name in predefined columns
			if (isset($importColumns[$headerItem])) {
				$parsedHeader[$headerItem] = $headerItem;
			} elseif ($key = \array_search($headerItem, $importColumns)) {
				$parsedHeader[$key] = $headerItem;
			} else {
				if (Strings::contains($headerItem, '#')) {
					$exploded = \explode('#', $headerItem);

					if (\count($exploded) !== 2) {
						continue;
					}

					// Find visibility columns by key
					if (isset($allowedVisibilityColumns[$exploded[0]])) {
						$parsedVisibilityColumns[$headerItem] = [
							'property' => $exploded[0],
							'visibilityList' => $exploded[1],
						];

						continue;
					}

					// Find visibility columns by name
					if ($key = \array_search($exploded[0], $allowedVisibilityColumns)) {
						$parsedVisibilityColumns[$headerItem] = [
							'property' => $key,
							'visibilityList' => $exploded[1],
						];

						continue;
					}

					// Nothing found, assume it is attribute code
					$attributeCode = $exploded[1];
				} else {
					$attributeCode = $headerItem;
				}

				if ($attribute = $this->attributeRepository->many()->where('code', $attributeCode)->first()) {
					$attributes[$attribute->getPK()] = $attribute;
					$parsedHeader[$attribute->getPK()] = $headerItem;
				}
			}
		}

		if (\count($parsedHeader) === 0) {
			throw new \Exception('Soubor neobsahuje hlavičku nebo nebyl nalezen žádný použitelný sloupec!');
		}

		if (!isset($parsedHeader['code']) && !isset($parsedHeader['ean'])) {
			throw new \Exception('Soubor neobsahuje kód ani EAN!');
		}

		$valuesToUpdate = [];
		$amountsToUpdate = [];
		$attributeValuesToCreate = [];
		$attributeAssignsToSync = [];

		$createdProducts = 0;
		$updatedProducts = 0;
		$skippedProducts = 0;

		$searchCode = $searchCriteria === 'all' || $searchCriteria === 'code';
		$searchEan = $searchCriteria === 'all' || $searchCriteria === 'ean';

		foreach ($reader->getRecords() as $record) {
			$newValues = [];
			$code = null;
			$ean = null;
			$codePrefix = null;

			// Take Code or Ean based on search criteria - if search by, delete it from record

			/** @var string|null $codeFromRecord */
			$codeFromRecord = isset($parsedHeader['code']) ? ($searchCode ? Arrays::pick($record, $parsedHeader['code'], null) : ($record[$parsedHeader['code']] ?? null)) : null;
			/** @var string|null $eanFromRecord */
			$eanFromRecord = isset($parsedHeader['ean']) ? ($searchEan ? Arrays::pick($record, $parsedHeader['ean'], null) : ($record[$parsedHeader['ean']] ?? null)) : null;

			/** @var \Eshop\DB\Product|null $product */
			$product = null;

			// Sanitize and prefix code and ean

			if (isset($parsedHeader['code']) && $codeFromRecord) {
				$codeBase = Strings::trim($codeFromRecord);
				$codePrefix = Strings::trim('00' . $codeFromRecord);

				$code = $codeBase;
			}

			if (isset($parsedHeader['ean']) && $eanFromRecord) {
				$ean = Strings::trim($eanFromRecord);
			}

			// Fast local search of product based on criteria

			if ($code && $ean && $searchCode && $searchEan) {
				$product = $this->arrayFind($products, function (\stdClass $x) use ($code, $codePrefix, $ean): bool {
					return $x->code === $code || $x->fullCode === $code ||
						$x->code === $codePrefix || $x->fullCode === $codePrefix ||
						$x->ean === $ean;
				});
			} elseif ($code && $searchCode) {
				$product = $this->arrayFind($products, function (\stdClass $x) use ($code, $codePrefix): bool {
					return $x->code === $code || $x->fullCode === $code ||
						$x->code === $codePrefix || $x->fullCode === $codePrefix;
				});
			} elseif ($ean && $searchEan) {
				$product = $this->arrayFind($products, function (\stdClass $x) use ($ean): bool {
					return $x->ean === $ean;
				});
			}

			// Continue based on settings and data

			if (($searchCode && $searchEan && !$code && !$ean) ||
				($searchCode && !$searchEan && !$code) ||
				($searchEan && !$searchCode && !$ean) ||
				(!$product && !$addNew) ||
				($product && !$overwriteExisting)
			) {
				$skippedProducts++;

				continue;
			}

			if ($product) {
				$updatedProducts++;
			}

			$relatedToSync = [
				'content' => [],
				'visibility' => [],
			];

			foreach ($record as $key => $value) {
				$key = \array_search($key, $parsedHeader);

				if (!$key) {
					continue;
				}

				if ($key === 'producer') {
					if (Strings::contains($value, '#')) {
						$producerCode = \explode('#', $value);

						if (\count($producerCode) !== 2) {
							continue;
						}

						$producerCode = $producerCode[1];
					} else {
						$producerCode = $value;
					}

					if (isset($producers[$producerCode]) && Strings::length($producerCode) > 0) {
						$newValues[$key] = $producers[$producerCode];
					}
				} elseif ($key === 'storeAmount') {
					$amounts = \explode(':', $value);

					foreach ($amounts as $amount) {
						$amount = \explode('#', $amount);

						if (\count($amount) !== 2) {
							continue;
						}

						if (!isset($stores[$amount[1]])) {
							continue;
						}

						$amountsToUpdate[] = [
							'store' => $stores[$amount[1]],
							'product' => $product->uuid,
							'inStock' => \intval($amount[0]),
						];
					}
				} elseif ($key === 'name') {
					$newValues[$key][$mutation] = $value;
				} elseif ($key === 'code') {
					if (!$searchCode) {
						$newValues[$key] = $codeFromRecord ?: null;
					}
				} elseif ($key === 'ean') {
					if (!$searchEan) {
						$newValues[$key] = $eanFromRecord ?: null;
					}
				} elseif ($key === 'masterProduct') {
					$newValues[$key] = null;

					if ($value) {
						$masterProduct = $this->arrayFind($products, function (\stdClass $x) use ($value): bool {
							return $x->code === $value || $x->fullCode === $value;
						});

						if ($masterProduct) {
							$newValues[$key] = $masterProduct->uuid;
						}
					}
				} elseif (!isset($attributes[$key])) {
					$newValues[$key] = $value;
				}
			}

			try {
				if ($product) {
					if (\count($newValues) > 0) {
						$newValues['uuid'] = $product->uuid;

						$valuesToUpdate[$product->uuid] = $newValues;
					}
				} elseif (\count($newValues) > 0) {
					if ($ean) {
						$newValues['ean'] = $ean;
					}

					$newValues['code'] = $code;

					$product = $this->productRepository->createOne($newValues);
				}
			} catch (\Exception $e) {
				throw new \Exception('Chyba při zpracování dat!');
			}

			foreach ($record as $key => $value) {
				if (match ($key) {
						'perex', 'content', 'Popisek', 'Obsah' => true,
						default => false,
				}) {
					$key = match ($key) {
						'Popisek' => 'perex',
						'Obsah' => 'content',
						default => $key,
					};

					$relatedToSync['content'][$key][$mutation] = $value;
					$relatedToSync['content']['shop'] = $selectedShop?->getPK();
					$relatedToSync['content']['product'] = $product->uuid;
				} elseif (isset($parsedVisibilityColumns[$key])) {
					$relatedToSync['visibility'][] = [
						'product' => $product->uuid,
						'visibilityList' => $parsedVisibilityColumns[$key]['visibilityList'],
						$parsedVisibilityColumns[$key]['property'] => $parsedVisibilityColumns[$key]['property'] === 'priority' ? \intval($value) : ($value === '1'),
					];
				} elseif ($key === 'categories' || $key === 'Kategorie') {
					$productsToDeleteCategories[] = $product->uuid;
					$valueCategories = \explode(',', $value);

					foreach ($valueCategories as $categoryValue) {
						$categoryValue = \explode('#', $categoryValue);

						if (\count($categoryValue) !== 2) {
							continue;
						}

						[$category, $categoryType] = $categoryValue;

						if (!isset($categories[$categoryType][$category])) {
							continue;
						}

						$category = $categories[$categoryType][$category];

						$newValues['categories'][] = $category->uuid;
					}

					if (isset($newValues['categories'])) {
						$valuesToUpdate[$product->uuid]['categories'] = $newValues['categories'];
					}

					foreach ($productsPrimaryCategories[$product->uuid] ?? [] as $categoryType => $primaryCategory) {
						if (!Arrays::contains($newValues['categories'] ?? [], $primaryCategory)) {
							$this->productPrimaryCategoryRepository->many()
								->where('fk_product', $product->uuid)
								->where('fk_category', $primaryCategory)->delete();

							unset($productsPrimaryCategories[$product->uuid][$categoryType]);
						}
					}
				}
			}

			$this->productContentRepository->syncOne($relatedToSync['content']);
			$this->visibilityListItemRepository->syncMany($relatedToSync['visibility']);

			if (!$updateAttributes) {
				continue;
			}

			foreach ($record as $key => $value) {
				$key = \array_search($key, $parsedHeader);

				if (!isset($attributes[$key]) || Strings::length($value) === 0) {
					continue;
				}

				$this->attributeAssignRepository->many()
					->join(['eshop_attributevalue'], 'this.fk_value = eshop_attributevalue.uuid')
					->where('this.fk_product', $product->uuid)
					->where('eshop_attributevalue.fk_attribute', $key)
					->delete();

				$attributeValues = Strings::contains($value, ':') ? \explode(':', $value) : [$value];

				foreach ($attributeValues as $attributeString) {
					if (Strings::contains($attributeString, '#')) {
						$attributeValueCode = \explode('#', $attributeString);

						if (\count($attributeValueCode) !== 2) {
							continue;
						}

						$attributeValueCode = $attributeValueCode[1];
					} else {
						$attributeValueCode = $attributeString;
					}

					/** @var \stdClass|null|false|\Eshop\DB\AttributeValue $attributeValue */
					$attributeValue = $this->arrayFind($groupedAttributeValues[$key] ?? [], function (\stdClass $x) use ($attributeValueCode): bool {
						return $x->code === $attributeValueCode;
					});

					if (!$attributeValue && !$createAttributeValues) {
						continue;
					}

					if (!$attributeValue) {
						/** @var \stdClass|null|false|\Eshop\DB\AttributeValue $attributeValue */
						$attributeValue = $this->arrayFind($groupedAttributeValues[$key] ?? [], function (\stdClass $x) use ($attributeValueCode): bool {
							return $x->label === $attributeValueCode;
						});

						$tried = 0;

						while ($attributeValue === false || $attributeValue === null) {
							try {
								$attributeValue = $this->attributeValueRepository->createOne([
									'code' => Strings::webalize($attributeValueCode) . '-' . Random::generate(),
									'label' => [
										$mutation => $attributeValueCode,
									],
									'attribute' => $key,
								], false, true);

								$attributeValuesToCreate[] = $attributeValue;
							} catch (\Throwable $e) {
							}

							$tried++;

							if ($tried > 10) {
								throw new \Exception('Cant create new attribute value. Tried 10 times! (product:' . $product->code . ')');
							}
						}

						if (!isset($groupedAttributeValues[$key][$attributeValue->uuid])) {
							$groupedAttributeValues[$key][$attributeValue->uuid] = (object) [
								'uuid' => $attributeValue->uuid,
								'label' => $attributeValue instanceof AttributeValue ? $attributeValue->getValue('label', $mutation) : $attributeValue->label,
								'code' => $attributeValue->code,
								'attribute' => $attributeValue instanceof AttributeValue ? $attributeValue->getValue('attribute') : $attributeValue->attribute,
							];
						}
					}

					$attributeAssignsToSync[] = [
						'product' => $product->uuid,
						'value' => $attributeValue->uuid,
					];
				}
			}
		}

		foreach (\array_chunk($productsToDeleteCategories, 100) as $categories) {
			$this->categoryRepository->getConnection()->rows(['eshop_product_nxn_eshop_category'])
				->where('fk_product', $categories)
				->delete();
		}

		$this->attributeAssignRepository->syncMany($attributeAssignsToSync);
		$this->productRepository->syncMany($valuesToUpdate);
		$this->amountRepository->syncMany($amountsToUpdate);

		return [
			'createdProducts' => $createdProducts,
			'updatedProducts' => $updatedProducts,
			'skippedProducts' => $skippedProducts,
			'updatedAmounts' => \count($amountsToUpdate),
			'createdAttributeValues' => \count($attributeValuesToCreate),
			'attributeAssignsUpdated' => \count($attributeAssignsToSync),
			'elapsedTimeInSeconds' => (int) Debugger::timer(),
		];
	}

	/**
	 * @param array<\stdClass> $xs
	 * @param callable $f
	 */
	protected function arrayFind(array $xs, callable $f): ?\stdClass
	{
		foreach ($xs as $x) {
			if (\call_user_func($f, $x) === true) {
				return $x;
			}
		}

		return null;
	}
}
