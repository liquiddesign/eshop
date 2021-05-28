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
				if (!isset($resultCategories[$category->getPK()]) && (!$categoryPK || $category->getPK() == $categoryPK)) {
					$attributes = $this->attributeRepository->getAttributesByCategories($category);

					$resultCategories[$category->getPK()]['products'][$productKey]['product'] = $product;

					foreach ($attributes as $attributeKey => $attribute) {
						$values = $this->attributeValueRepository->many()
							->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_value')
							->where('assign.fk_product', $product->getPK())
							->toArray();

						if (\count($values) == 0) {
							continue;
						}

						$resultCategories[$category->getPK()]['products'][$productKey]['attributes'][$attributeKey]['attribute'] = $attribute;
						$resultCategories[$category->getPK()]['products'][$productKey]['attributes'][$attributeKey]['values'] = $values;
					}

					$resultCategories[$category->getPK()]['category'] = $category;
				}
			}
		}

		bdump($resultCategories);

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
		return $this->getCategories('name');
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