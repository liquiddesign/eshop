<?php
declare(strict_types=1);

namespace Eshop;

use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ProductRepository;
use Nette\Http\Session;
use Nette\Http\SessionSection;
use Web\DB\PageRepository;

class CompareManager
{
	private Session $session;

	private ProductRepository $productRepository;

	private CategoryRepository $categoryRepository;

	private AttributeRepository $attributeRepository;

	private AttributeValueRepository $attributeValueRepository;

	private PageRepository $pageRepository;

	public function __construct(
		Session $session,
		ProductRepository $productRepository,
		CategoryRepository $categoryRepository,
		AttributeRepository $attributeRepository,
		AttributeValueRepository $attributeValueRepository,
		PageRepository $pageRepository
	) {
		$this->session = $session;
		$this->productRepository = $productRepository;
		$this->categoryRepository = $categoryRepository;
		$this->attributeRepository = $attributeRepository;
		$this->attributeValueRepository = $attributeValueRepository;
		$this->pageRepository = $pageRepository;
	}

	/**
	 * @return \Eshop\DB\Product[]
	 */
	public function getCompareList(): array
	{
		return $this->getSessionSection()->get('list');
	}

	/**
	 * @param string|null $categoryPK
	 * @return array<int|string, array<string, array<array<string, array<int|string, array<\Eshop\DB\AttributeValue>>|\Eshop\DB\Product|null>|\StORM\Entity|null>|\Eshop\DB\Category>>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getParsedProductsWithPrimaryCategories(?string $categoryPK = null): array
	{
		$resultCategories = [];

		foreach ($this->getCompareList() as $productKey => $product) {
			$product = $this->productRepository->one($productKey);

			if (!$category = $product->getPrimaryCategory()) {
				continue;
			}

			$categories = $this->categoryRepository->getBranch($category);

			if (!isset($resultCategories[$category->getPK()])) {
				$resultCategories[$category->getPK()]['attributes'] = [];
				$resultCategories[$category->getPK()]['products'] = [];
				$resultCategories[$category->getPK()]['category'] = $category;
			}

			if ($categoryPK && $category->getPK() !== $categoryPK) {
				continue;
			}

			$attributes = $this->attributeRepository->getAttributesByCategories(\array_values($categories));

			$resultCategories[$category->getPK()]['products'][$productKey]['product'] = $product;

			foreach ($attributes as $attributeKey => $attribute) {
				/** @var \Eshop\DB\AttributeValue[] $values */
				$values = $this->attributeValueRepository->getCollection()
					->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_value')
					->join(['attribute' => 'eshop_attribute'], 'this.fk_attribute = attribute.uuid')
					->where('assign.fk_product', $product->getPK())
					->where('attribute.uuid', $attributeKey)
					->where('attribute.showProduct', true)
					->toArray();

				$resultCategories[$category->getPK()]['attributes'][$attributeKey] = $attribute;

				if (\count($values) === 0) {
					continue;
				}

				$resultCategories[$category->getPK()]['products'][$productKey]['attributes'][$attributeKey] = $values;

				foreach (\array_keys($values) as $attributeValueKey) {
					$resultCategories[$category->getPK()]['products'][$productKey]['attributes'][$attributeKey][$attributeValueKey]
						->setValue('page', $this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $attributeValueKey]));
				}
			}
		}

		return $resultCategories;
	}

	/**
	 * @param string|null $parameterName
	 * @return string[]|\Eshop\DB\Category[]
	 * @throws \StORM\Exception\NotFoundException
	 */
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

	/**
	 * @return string[]
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getCategoriesNames(): array
	{
		$resultCategories = [];

		foreach ($this->getCompareList() as $productKey => $product) {
			$product = $this->productRepository->one($productKey);

			if (!$category = $product->getPrimaryCategory()) {
				continue;
			}

			$branch = $this->categoryRepository->getBranch($category);

			if (isset($resultCategories[$category->getPK()])) {
				continue;
			}

			$resultCategories[$category->getPK()] = '';

			foreach ($branch as $branchCategory) {
				$resultCategories[$category->getPK()] .= $branchCategory->name . ' -> ';
			}

			if (\strlen($resultCategories[$category->getPK()]) <= 0) {
				continue;
			}

			$resultCategories[$category->getPK()] = \substr($resultCategories[$category->getPK()], 0, -3);
		}

		return $resultCategories;
	}

	public function addProductToCompare(string $product): void
	{
		$section = $this->getSessionSection();

		/** @var \Eshop\DB\Product $product */
		$product = $this->productRepository->one($product, true);

		if (!$product->getPrimaryCategory()) {
			return;
		}

		$list = $section->get('list');
		$list[$product->getPK()] = $product;
		$section->set('list', $list);
	}

	public function clearCompareList(): void
	{
		$this->getSessionSection()->set('list', []);
	}

	public function removeProductFromCompare(string $searchedProduct): void
	{
		$section = $this->getSessionSection();

		$list = $section->get('list');
		unset($list[$searchedProduct]);

		$section->set('list', $list);
	}

	public function isProductInList(string $searchedProduct): bool
	{
		$section = $this->getCompareList();

		return \Nette\Utils\Arrays::contains(\array_keys($section), $searchedProduct);
	}

	public function getProductsInListCount(): int
	{
		$section = $this->getCompareList();

		return \count($section);
	}

	private function getSessionSection(): SessionSection
	{
		$section = $this->session->getSection('compare');
		$section->list ??= [];

		return $section;
	}
}
