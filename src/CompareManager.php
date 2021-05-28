<?php
declare(strict_types=1);

namespace Eshop;

use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ParameterCategory;
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

	public function getProductsInCategory(ParameterCategory $parameterCategory): array
	{
		return $this->getCompareList()[$parameterCategory->getPK()] ?? [];
	}

	/**
	 * @param string|null $parameterCategory
	 * @return array contains asociative arrays with parameter and parameter values for products
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getParameters(?string $parameterCategory): array
	{
		$categories = $this->getCategories();

		/** @var \Eshop\DB\ParameterCategory $parameterCategory */
		if (!$parameterCategory && !\Nette\Utils\Arrays::contains($categories, $parameterCategory)) {
			$parameterCategory = \Nette\Utils\Arrays::first($categories);
		} else {
			$parameterCategory = $this->parameterCategoryRepository->one($parameterCategory);
		}

		if (!$parameterCategory) {
			return [];
		}

		/** @var \Eshop\DB\Product[] $products */
		$products = $this->getProductsInCategory($parameterCategory);

		/** @var \Eshop\DB\ParameterGroup $groups */
		$groups = $this->parameterGroupRepository->getCollection()
			->where('fk_parametercategory', $parameterCategory->getPK())
			->toArray();

		$data = [
			'products' => $products,
			'groups' => $groups,
			'parameters' => [],
			'values' => []
		];

		foreach ($groups as $group) {
			/** @var \Eshop\DB\Parameter[] $parameters */
			$parameters = $this->parameterRepository->getCollection()
				->where('fk_group', $group->getPK())
				->toArray();
			$data['parameters'] += $parameters;

			foreach ($parameters as $parameter) {
				foreach ($products as $product) {
					/** @var \Eshop\DB\ParameterValue $value */
					$value = $this->parameterValueRepository->getCollection()
						->where('fk_product', $product->getPK())
						->where('fk_parameter', $parameter->getPK())
						->first();

					if (!($value && ($value->content || $value->metaValue))) {
						$data['values'][$group->getPK()][$parameter->getPK()][$product->getPK()] = '-';
						continue;
					}

					if($value->parameter->type == 'bool'){
						$data['values'][$group->getPK()][$parameter->getPK()][$product->getPK()] = $value->metaValue ? $this->translator->translate('.yes', 'Ano') : $this->translator->translate('.no', 'Ne');
					}elseif ($value->parameter->type == 'list'){
						$allowed = \array_combine(\explode(';', $parameter->allowedKeys ?? ''), \explode(';', $parameter->allowedValues ?? ''));
						$metaValue = \explode(';', $value->metaValue);
						$finalMeta = [];
						foreach ($metaValue as $metaV){
							$finalMeta[] = $allowed[$metaV];
						}

						$data['values'][$group->getPK()][$parameter->getPK()][$product->getPK()] = \implode(', ',$finalMeta);
					}else{
						$data['values'][$group->getPK()][$parameter->getPK()][$product->getPK()] = $value->content;
					}
				}
			}
		}

		return $data;
	}

	/**
	 * @param string|null $parameter
	 * @return array
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getCategories(?string $parameter = null): array
	{
		$resultCategories = [];

		foreach (\array_keys($this->getCompareList()) as $categoryPK) {
			if (!$category = $this->parameterCategoryRepository->one($categoryPK)) {
				continue;
			}

			$resultCategories[$category->getPK()] = $parameter ? $category->$parameter : $category;
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
		};

		if (!$parameterCategory = $this->categoryRepository->getParameterCategoryOfCategory($productCategory)) {
			return;
		}

		$section->list[$parameterCategory->getPK()][$product->getPK()] = $product;
	}

	public function clearCompareList(): void
	{
		$this->getSessionSection()->list = [];
	}

	public function removeProductFromCompare(string $searchedProduct): void
	{
		$section = $this->getSessionSection();

		foreach ($section->list as $key => $value) {
			if (\Nette\Utils\Arrays::contains(\array_keys($value), $searchedProduct)) {
				unset($section->list[$key][$searchedProduct]);

				if (\count($section->list[$key]) == 0) {
					unset($section->list[$key]);
				}

				return;
			}
		}
	}

	public function isProductInList(string $searchedProduct): bool
	{
		$section = $this->getCompareList();

		foreach ($section as $key => $value) {
			if (\Nette\Utils\Arrays::contains(\array_keys($value), $searchedProduct)) {
				return true;
			}
		}

		return false;
	}

	public function getProductsInListCount(): int
	{
		$section = $this->getCompareList();

		$count = 0;

		foreach ($section as $key => $value) {
			$count += \count($value);
		}

		return $count;
	}
}