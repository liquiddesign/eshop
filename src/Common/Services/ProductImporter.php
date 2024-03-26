<?php

namespace Eshop\Common\Services;

use Base\BaseHelpers;
use Base\Helpers;
use Base\ShopsConfig;
use Eshop\DB\AmountRepository;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\ProductContentRepository;
use Eshop\DB\ProductPrimaryCategoryRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\StoreRepository;
use Eshop\DB\VisibilityListItemRepository;
use League\Csv\Reader;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use Tracy\Debugger;
use Web\DB\PageRepository;

class ProductImporter
{
	public function __construct(
		protected readonly ProductRepository $productRepository,
		protected readonly AttributeRepository $attributeRepository,
		protected readonly StoreRepository $storeRepository,
		protected readonly CategoryRepository $categoryRepository,
		protected readonly ProducerRepository $producerRepository,
		protected readonly AttributeValueRepository $attributeValueRepository,
		protected readonly AttributeAssignRepository $attributeAssignRepository,
		protected readonly AmountRepository $amountRepository,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly VisibilityListItemRepository $visibilityListItemRepository,
		protected readonly ProductContentRepository $productContentRepository,
		protected readonly ProductPrimaryCategoryRepository $productPrimaryCategoryRepository,
		protected readonly PageRepository $pageRepository,
	) {
	}

	/**
	 * @param string $filePath
	 * @param string $delimiter
	 * @param bool $addNew
	 * @param bool $overwriteExisting
	 * @param bool $updateAttributes
	 * @param bool $createAttributeValues
	 * @param string $searchCriteria
	 * @param array<string> $importColumns
	 * @param array<callable> $onImport
	 * @return array<string|int>
	 * @throws \League\Csv\Exception
	 * @throws \League\Csv\InvalidArgument
	 * @throws \League\Csv\SyntaxError
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
		array $onImport = [],
	): array {
		$selectedShop = $this->shopsConfig->getSelectedShop();
		$mutations = $this->productRepository->getConnection()->getAvailableMutations();

		$reader = Reader::createFromPath($filePath);

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

		$productsToDeleteCategories = [];
		$productsToDeletePrimaryCategories = [];
		$productPrimaryCategoriesToSync = [];

		$products = $this->productRepository->many()->setSelect([
			'uuid',
			'code',
			'fullCode' => 'CONCAT(code,".",subCode)',
			'ean',
			'supplierContentLock',
			'mpn',
		], [], true)->fetchArray(\stdClass::class);

		$csvHeader = $reader->getHeader();

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

		$productColumns = [
			'name' => 'Název',
		];

		foreach ($productColumns as $key => $value) {
			foreach ($mutations as $tmpMutation) {
				$importColumns[$key . $tmpMutation] = $value . $tmpMutation;
			}

			unset($importColumns[$key]);
		}

		$parsedVisibilityColumns = [];

		foreach ($csvHeader as $headerItem) {
			// Find column by key or name in predefined columns
			if (isset($importColumns[$headerItem])) {
				$parsedHeader[$headerItem] = $headerItem;
			} elseif ($key = \array_search($headerItem, $importColumns)) {
				$parsedHeader[$key] = $headerItem;
			} else {
				if (\str_contains($headerItem, '#')) {
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
		$importedProductsPKs = [];

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
				$product = Helpers::arrayFind($products, function (\stdClass $x) use ($code, $codePrefix, $ean): bool {
					return $x->code === $code || $x->fullCode === $code ||
						$x->code === $codePrefix || $x->fullCode === $codePrefix ||
						$x->ean === $ean;
				});
			} elseif ($code && $searchCode) {
				$product = Helpers::arrayFind($products, function (\stdClass $x) use ($code, $codePrefix): bool {
					return $x->code === $code || $x->fullCode === $code ||
						$x->code === $codePrefix || $x->fullCode === $codePrefix;
				});
			} elseif ($ean && $searchEan) {
				$product = Helpers::arrayFind($products, function (\stdClass $x) use ($ean): bool {
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

			/**
			  * @var string $key
			  * @var string $value
			 */
			foreach ($record as $key => $value) {
				$key = \array_search($key, $parsedHeader);

				if (!$key) {
					continue;
				}

				if ($key === 'producer') {
					if (\str_contains($value, '#')) {
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
				} elseif (\str_starts_with((string) $key, 'name_')) {
					[$key, $mutationSuffix] = \explode('_', (string) $key);

					$newValues[$key][$mutationSuffix] = $value;
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
						$masterProduct = Helpers::arrayFind($products, function (\stdClass $x) use ($value): bool {
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

				$importedProductsPKs[] = $product->uuid;
			} catch (\Exception $e) {
				throw new \Exception('Chyba při zpracování dat!');
			}

			Arrays::invoke($onImport, $importedProductsPKs);

			foreach ($record as $key => $value) {
				if (!\str_starts_with((string) $key, 'perex_') && !\str_starts_with((string) $key, 'content_') &&
					!\str_starts_with((string) $key, 'Popisek_') && !\str_starts_with((string) $key, 'Obsah_')) {
					continue;
				}

				[$key, $mutationSuffix] = \explode('_', (string) $key);

				$key = match ($key) {
					'Popisek' => 'perex',
					'Obsah' => 'content',
					default => $key,
				};

				$relatedToSync['content'][$key][$mutationSuffix] = \preg_replace('/(\r\n|\r|\n|\x{2028})/u', '<br>', $value);

				$relatedToSync['content']['shop'] = $selectedShop?->getPK();
				$relatedToSync['content']['product'] = $product->uuid;
			}

			foreach ($record as $key => $value) {
				if (isset($parsedVisibilityColumns[$key])) {
					$relatedToSync['visibility'][$parsedVisibilityColumns[$key]['visibilityList']][$product->uuid][$parsedVisibilityColumns[$key]['property']] =
						$parsedVisibilityColumns[$key]['property'] === 'priority' ? \intval($value) : ($value === '1');
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
				} elseif ($key === 'primaryCategories' || $key === 'Primární kategorie') {
					$productsToDeletePrimaryCategories[] = $product->uuid;
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

						$productPrimaryCategoriesToSync[] = [
							'category' => $category->uuid,
							'categoryType' => $categoryType,
							'product' => $product->uuid,
						];
					}

					if (isset($newValues['primaryCategories'])) {
						$valuesToUpdate[$product->uuid]['primaryCategories'] = $newValues['primaryCategories'];
					}
				}
			}
			
			if ($relatedToSync['content']) {
				$productContent = $this->productContentRepository->many()->where('fk_product', $relatedToSync['content']['product']);
				
				if ($relatedToSync['content']['shop']) {
					$productContent->where('fk_shop', $relatedToSync['content']['shop']);
				}
				
				$productContent = $productContent->first();
				
				if ($productContent) {
					$productContent->update($relatedToSync['content']);
				} else {
					$this->productContentRepository->createOne($relatedToSync['content']);
				}
			}

			foreach ($relatedToSync['visibility'] as $visibilityListPK => $value) {
				foreach ($value as $productPK => $data) {
					$this->visibilityListItemRepository->syncOne([
							'visibilityList' => $visibilityListPK,
							'product' => $productPK,
						] + $data, ignore: false);
				}
			}

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

				$attributeValues = \str_contains($value, ':') ? \explode(':', $value) : [$value];

				foreach ($attributeValues as $attributeString) {
					if (\str_contains($attributeString, '#')) {
						$attributeValueCode = \explode('#', $attributeString);

						if (\count($attributeValueCode) !== 2) {
							continue;
						}

						$attributeValueCode = $attributeValueCode[1];
					} else {
						$attributeValueCode = $attributeString;
					}

					/** @var \stdClass|null|false|\Eshop\DB\AttributeValue $attributeValue */
					$attributeValue = BaseHelpers::arrayFind($groupedAttributeValues[$key] ?? [], function (\stdClass $x) use ($attributeValueCode): bool {
						return $x->code === $attributeValueCode;
					});

					if (!$attributeValue && !$createAttributeValues) {
						continue;
					}

					if (!$attributeValue) {
						$labels = [];

						foreach (\array_keys($mutations) as $mutation) {
							$labels[$mutation] = $attributeValueCode;
						}

						$attributeValue = $this->attributeValueRepository->createOne([
							'code' => $attributeValueCode,
							'label' => $labels,
							'attribute' => $key,
						], false, true);

						$attributeValuesToCreate[] = $attributeValue;

						if (!isset($groupedAttributeValues[$key][$attributeValue->getPK()])) {
							$label = $attributeValue->getValue('label', $mutation);
							$labels = [];

							foreach ($mutations as $suffix) {
								$labels["label$suffix"] = $label;
							}

							$groupedAttributeValues[$key][$attributeValue->getPK()] = (object) ([
									'uuid' => $attributeValue->getPK(),
									'code' => $attributeValue->code,
									'attribute' => $attributeValue->getValue('attribute'),
								] + $labels);

							unset($labels);
						}
					}

					$attributeAssignsToSync[] = [
						'product' => $product->uuid,
						'value' => $attributeValue->uuid,
					];
				}
			}
		}

		foreach (\array_chunk($productsToDeleteCategories, 1000) as $products) {
			$this->categoryRepository->getConnection()->rows(['eshop_product_nxn_eshop_category'])
				->where('fk_product', $products)
				->delete();
		}

		foreach (\array_chunk($productsToDeletePrimaryCategories, 1000) as $products) {
			$this->productPrimaryCategoryRepository->getConnection()->rows(['eshop_product_nxn_eshop_category'])
				->where('fk_product', $products)
				->delete();
		}

		$this->attributeAssignRepository->syncMany($attributeAssignsToSync);
		$this->productPrimaryCategoryRepository->syncMany($productPrimaryCategoriesToSync);
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
	 * @param string $filePath
	 * @param string $delimiter
	 * @return array<string|int>
	 * @throws \League\Csv\Exception
	 * @throws \League\Csv\InvalidArgument
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function importPagesCsv(string $filePath, string $delimiter = ';',): array
	{
		Debugger::timer();

		$reader = Reader::createFromPath($filePath);

		$reader->setDelimiter($delimiter);
		$reader->setHeaderOffset(0);

		$connection = $this->productRepository->getConnection();

		$mutations = $connection->getAvailableMutations();
		
		$importColumns = [
			'code' => 'Kód',
			'ean' => 'EAN',
			'title' => 'SEO Titulek',
			'description' => 'SEO Popis',
			'url' => 'URL',
		];

		$header = $reader->getHeader();
		$parsedHeader = [];

		foreach ($header as $headerItem) {
			if (isset($importColumns[$headerItem])) {
				$parsedHeader[$headerItem] = $headerItem;

				continue;
			}

			if ($key = \array_search($headerItem, $importColumns)) {
				$parsedHeader[$headerItem] = $key;

				continue;
			}

			foreach ($mutations as $mutation => $suffix) {
				if (\str_ends_with($headerItem, $suffix)) {
					$headerItemWithMutation = $headerItem;
					$headerItemWithoutMutation = Strings::substring($headerItemWithMutation, 0, Strings::indexOf($headerItem, $suffix));

					if (isset($importColumns[$headerItemWithoutMutation])) {
						$parsedHeader[$headerItemWithMutation] = [$headerItemWithoutMutation, $mutation];
					} elseif ($key = \array_search($headerItemWithoutMutation, $importColumns)) {
						$parsedHeader[$headerItemWithMutation] = [$key, $mutation];
					}

					continue 2;
				}
			}
		}

		if (\count($parsedHeader) === 0) {
			throw new \Exception('Soubor neobsahuje hlavičku nebo nebyl nalezen žádný použitelný sloupec!');
		}

		if (!\array_search('code', $parsedHeader) && !\array_search('ean', $parsedHeader)) {
			throw new \Exception('Soubor neobsahuje kód ani EAN!');
		}

		$records = $reader->getRecords();

		$createdProducts = 0;
		$updatedProducts = 0;
		$skippedProducts = 0;

		/** @var 'all'|'code'|'ean' $searchCriteria */
		$searchCriteria = 'all';

		$searchCode = $searchCriteria === 'all' || $searchCriteria === 'code';
		$searchEan = $searchCriteria === 'all' || $searchCriteria === 'ean';

		$products = $this->productRepository->many()
			->setSelect([
				'this.uuid',
				'this.code',
				'fullCode' => 'CONCAT(this.code,".",this.subCode)',
				'this.ean',
				'this.supplierContentLock',
				'this.mpn',
				'export_page_uuid' => 'export_page.uuid',
			], [], true)
			->join(['export_page' => 'web_page'], "export_page.params like CONCAT('%product=', this.uuid, '&%') and export_page.type = 'product_detail'")
			->setGroupBy(['this.uuid'])
			->fetchArray(\stdClass::class);

		foreach ($records as $record) {
			$newValues = [];
			$code = null;
			$ean = null;
			$codePrefix = null;

			// Take Code or Ean based on search criteria - if search by, delete it from record

			$parsedHeaderCode = null;

			if ($parsedHeaderCodeKey = \array_search('code', $parsedHeader)) {
				$parsedHeaderCode = $parsedHeader[$parsedHeaderCodeKey];
			}

			$parsedHeaderEan = null;

			if ($parsedHeaderEanKey = \array_search('ean', $parsedHeader)) {
				$parsedHeaderEan = $parsedHeader[$parsedHeaderEanKey];
			}

			/** @var string|null $codeFromRecord */
			$codeFromRecord = $parsedHeaderCode ? ($searchCode ? Arrays::pick($record, $parsedHeaderCodeKey, null) : ($record[$parsedHeaderCodeKey] ?? null)) : null;
			/** @var string|null $eanFromRecord */
			$eanFromRecord = $parsedHeaderEan ? ($searchEan ? Arrays::pick($record, $parsedHeaderEanKey, null) : ($record[$parsedHeaderEanKey] ?? null)) : null;
			/** @var \stdClass|null $product */
			$product = null;

			// Sanitize and prefix code and ean

			if ($parsedHeaderCodeKey && $codeFromRecord) {
				$codeBase = Strings::trim($codeFromRecord);
				$codePrefix = Strings::trim('00' . $codeFromRecord);

				$code = $codeBase;
			}

			if ($parsedHeaderEanKey && $eanFromRecord) {
				$ean = Strings::trim($eanFromRecord);
			}

			// Fast local search of product based on criteria

			if ($code && $ean && $searchCode && $searchEan) {
				$product = BaseHelpers::arrayFind($products, function (\stdClass $x) use ($code, $codePrefix, $ean): bool {
					return $x->code === $code || $x->fullCode === $code ||
						$x->code === $codePrefix || $x->fullCode === $codePrefix ||
						$x->ean === $ean;
				});
			} elseif ($code && $searchCode) {
				$product = BaseHelpers::arrayFind($products, function (\stdClass $x) use ($code, $codePrefix): bool {
					return $x->code === $code || $x->fullCode === $code ||
						$x->code === $codePrefix || $x->fullCode === $codePrefix;
				});
			} elseif ($ean && $searchEan) {
				$product = BaseHelpers::arrayFind($products, function (\stdClass $x) use ($ean): bool {
					return $x->ean === $ean;
				});
			}

			// Continue based on settings and data

			if (($searchCode && $searchEan && !$code && !$ean) ||
				($searchCode && !$searchEan && !$code) ||
				($searchEan && !$searchCode && !$ean) ||
				!$product
			) {
				$skippedProducts++;

				continue;
			}

			$updatedProducts++;

			foreach ($record as $key => $value) {
				$parsedKey = $parsedHeader[$key] ?? (($searchedKey = \array_search($key, $parsedHeader)) ? $parsedHeader[$searchedKey] : null);

				if (!$parsedKey) {
					continue;
				}

				$column = \is_array($parsedKey) ? $parsedKey[0] : $parsedKey;
				$mutation = \is_array($parsedKey) ? $parsedKey[1] : null;
				$value = Strings::length($value) > 0 ? $value : null;

				if ($mutation) {
					if ($column === 'url' && \str_starts_with($value, '/')) {
						$value = \ltrim($value, '/');
					}

					$newValues[$column][$mutation] = $value;
				} else {
					$newValues[$column] = $value;
				}
			}

			try {
				if (\count($newValues) > 0) {
                    // phpcs:ignore
                    $newValues['uuid'] = $product->export_page_uuid;
					$newValues['type'] = 'product_detail';
					$newValues['params'] = "product=$product->uuid&";

					$this->pageRepository->syncOne($newValues, null, true);
				}
			} catch (\Exception $e) {
				throw new \Exception('Chyba při zpracování dat!');
			}
		}

		return [
			'createdProducts' => $createdProducts,
			'updatedProducts' => $updatedProducts,
			'skippedProducts' => $skippedProducts,
			'elapsedTimeInSeconds' => (int) Debugger::timer(),
		];
	}
}
