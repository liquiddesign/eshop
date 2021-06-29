<?php
declare(strict_types=1);

namespace Eshop;

use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ProductRepository;
use Nette\Http\Session;
use Nette\Http\SessionSection;
use Translator\DB\TranslationRepository;

class CompareManager
{
	private Session $session;

	private ProductRepository $productRepository;

	private CategoryRepository $categoryRepository;

	private TranslationRepository $translator;

	private AttributeRepository $attributeRepository;

	private AttributeValueRepository $attributeValueRepository;

	public function __construct(
		Session $session,
		ProductRepository $productRepository,
		CategoryRepository $categoryRepository,
		TranslationRepository $translator,
		AttributeRepository $attributeRepository,
		AttributeValueRepository $attributeValueRepository
	)
	{
		$this->session = $session;
		$this->productRepository = $productRepository;
		$this->categoryRepository = $categoryRepository;
		$this->translator = $translator;
		$this->attributeRepository = $attributeRepository;
		$this->attributeValueRepository = $attributeValueRepository;
	}

	public function getCompareList(): array
	{
		return $this->getSessionSection()->list;
	}

	private function getSessionSection(): SessionSection
	{
		$section = $this->session->getSection('compare');
		$section->list ??= [];

		return $section;
	}

	public function getParsedProductsWithPrimaryCategories(?string $categoryPK = null): array
	{
		$resultCategories = [];

		foreach ($this->getCompareList() as $productKey => $product) {
			$product = $this->productRepository->one($productKey);

			if ($category = $product->getPrimaryCategory()) {
				$categories = $this->categoryRepository->getBranch($category);

				if (!isset($resultCategories[$category->getPK()])) {
					$resultCategories[$category->getPK()]['attributes'] = [];
					$resultCategories[$category->getPK()]['products'] = [];
					$resultCategories[$category->getPK()]['category'] = $category;
				}

				if (!$categoryPK || $category->getPK() == $categoryPK) {
					$attributes = $this->attributeRepository->getAttributesByCategories(\array_values($categories));

					$resultCategories[$category->getPK()]['products'][$productKey]['product'] = $product;

					foreach ($attributes as $attributeKey => $attribute) {
						$values = $this->attributeValueRepository->getCollection()
							->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_value')
							->join(['attribute' => 'eshop_attribute'], 'this.fk_attribute = attribute.uuid')
							->where('assign.fk_product', $product->getPK())
							->where('attribute.uuid', $attributeKey)
							->where('attribute.showProduct', true)
							->toArray();

						$resultCategories[$category->getPK()]['attributes'][$attributeKey] = $attribute;

						if (\count($values) == 0) {
							continue;
						}

						$resultCategories[$category->getPK()]['products'][$productKey]['attributes'][$attributeKey] = $values;
					}
				}
			}
		}

		return $resultCategories;
	}

	public function getCategories(?string $parameterName = null): array
	{
		$resultCategories = [];

		foreach ($this->getCompareList() as $productKey => $product) {
			$product = $this->productRepository->one($productKey);

			if (!$category = $product->getPrimaryCategory()) {
				continue;
			}

			$resultCategories[$category->getPK()] = $parameterName ? $category->$parameterName : $category;
		}

		return $resultCategories;
	}

	public function getCategoriesNames(): array
	{
		$resultCategories = [];

		foreach ($this->getCompareList() as $productKey => $product) {
			$product = $this->productRepository->one($productKey);

			if (!$category = $product->getPrimaryCategory()) {
				continue;
			}

			$branch = $this->categoryRepository->getBranch($category);

			if (!isset($resultCategories[$category->getPK()])) {
				$resultCategories[$category->getPK()] = '';

				foreach ($branch as $branchCategory) {
					$resultCategories[$category->getPK()] .= $branchCategory->name . ' -> ';
				}

				if (\strlen($resultCategories[$category->getPK()]) > 0) {
					$resultCategories[$category->getPK()] = \substr($resultCategories[$category->getPK()], 0, -3);
				}
			}
		}

		return $resultCategories;
	}

	public function addProductToCompare(string $product): void
	{
		$section = $this->getSessionSection();

		/** @var \Eshop\DB\Product $product */
		$product = $this->productRepository->one($product, true);

		if (!$productCategory = $product->getPrimaryCategory()) {
			return;
		}

		$section->list[$product->getPK()] = $product;
	}

	public function clearCompareList(): void
	{
		$this->getSessionSection()->list = [];
	}

	public function removeProductFromCompare(string $searchedProduct): void
	{
		$section = $this->getSessionSection();

		unset($section->list[$searchedProduct]);
	}

	public function isProductInList(string $searchedProduct): bool
	{
		$section = $this->getCompareList();

		if (\Nette\Utils\Arrays::contains(\array_keys($section), $searchedProduct)) {
			return true;
		}

		return false;
	}

	public function getProductsInListCount(): int
	{
		$section = $this->getCompareList();

		return \count($section);
	}
}