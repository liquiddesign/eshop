<?php
declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ProductRepository;
use Nette\Application\UI\Control;
use Translator\DB\TranslationRepository;
use Forms\FormFactory;
use Forms\Form;

class ProductFilter extends Control
{
	private TranslationRepository $translator;

	private FormFactory $formFactory;

	private CategoryRepository $categoryRepository;

	private ProductRepository $productRepository;

	private AttributeRepository $attributeRepository;

	private AttributeValueRepository $attributeValueRepository;

	private ?array $selectedCategories;

	public function __construct(
		FormFactory $formFactory,
		TranslationRepository $translator,
		CategoryRepository $categoryRepository,
		ProductRepository $productRepository,
		AttributeRepository $attributeRepository,
		AttributeValueRepository $attributeValueRepository
	)
	{
		$this->translator = $translator;
		$this->formFactory = $formFactory;
		$this->categoryRepository = $categoryRepository;
		$this->productRepository = $productRepository;
		$this->attributeRepository = $attributeRepository;
		$this->attributeValueRepository = $attributeValueRepository;
	}

	/**
	 * @return \StORM\Entity[]
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getSelectedCategories(): array
	{
		$category = $this->getParent()->getFilters()['category'] ?? null;

		if (!$category) {
			return [];
		}

		$categories = $this->categoryRepository->getBranch($this->categoryRepository->one(['path' => $category]));

		if (!$categories) {
			return [];
		}

		return $this->selectedCategories ??= $categories;
	}

	public function render(): void
	{
		$collection = $this->getParent()->getSource()->setSelect(['this.uuid']);

		$this->template->attributesValuesCounts = $this->attributeRepository->getCounts($collection, $this->getSelectedCategories(), $this->getParent()->getFilters()['attributes'] ?? []);

		$this->template->render($this->template->getFile() ?: __DIR__ . '/productFilter.latte');
	}

	public function createComponentForm(): Form
	{
		$filterForm = $this->formFactory->create();

		$filterForm->addInteger('priceFrom')->setRequired()->setDefaultValue(0);
		$filterForm->addInteger('priceTo')->setRequired()->setDefaultValue(100000);

		$attributesContainer = $filterForm->addContainer('attributes');

		$attributes = $this->attributeRepository->getAttributesByCategories($this->getSelectedCategories())->where('showFilter', true);

		foreach ($attributes as $attribute) {
			$attributeValues = $this->attributeRepository->getAttributeValues($attribute)->toArrayOf('label');

			if (\count($attributeValues) == 0) {
				continue;
			}

			$attributesContainer->addCheckboxList($attribute->getPK(), $attribute->name ?? $attribute->code, $attributeValues);
		}

		$filterForm->addSubmit('submit', $this->translator->translate('filter.showProducts', 'Zobrazit produkty'));

		/** @var ProductList $parent */
		$parent = $this->getParent();

		$filterForm->setDefaults($parent->getFilters()['attributes'] ?? []);

		$filterForm->onValidate[] = function (Form $form) {
			$values = $form->getValues();

			if ($values['priceFrom'] > $values['priceTo']) {
				$form['priceTo']->addError($this->translator->translate('filter.wrongPriceRange', 'Neplatný rozsah cen!'));
				$this->flashMessage($this->translator->translate('form.submitError', 'Chybně vyplněný formulář!'), 'error');
			}
		};

		$filterForm->onSuccess[] = function (Form $form) {
			$parameters = [];

			$parent = $this->getParent()->getName();

			foreach ($form->getValues('array') as $name => $values) {
				$parameters["$parent-$name"] = $values;
			}

			//@TODO nefunguje filtrace ceny
			unset($parameters['products-priceFrom']);
			unset($parameters['products-priceTo']);

			$this->getPresenter()->redirect('this', $parameters);
		};

		return $filterForm;
	}


	public function handleClearFilters(): void
	{
		$parent = $this->getParent()->getName();

		$this->getPresenter()->redirect('this', ["$parent-priceFrom" => null, "$parent-priceTo" => null, "$parent-attributes" => null]);
	}

	public function handleClearFilter($searchedAttributeKey, $searchedAttributeValueKey = null): void
	{
		$filtersParameters = $this->getParent()->getFilters()['attributes'];
		$parent = $this->getParent()->getName();

		foreach ($filtersParameters as $attributeKey => $attributeValues) {
			if ($attributeKey == $searchedAttributeKey) {
				if ($searchedAttributeValueKey) {
					$foundKey = \array_search($searchedAttributeValueKey, $attributeValues);
					unset($filtersParameters[$attributeKey][$foundKey]);
					$filtersParameters[$attributeKey] = \array_values($filtersParameters[$attributeKey]);

					break;
				}
			} else {
				unset($filtersParameters[$attributeKey]);

				break;
			}
		}

		$this->getPresenter()->redirect('this', ["$parent-attributes" => $filtersParameters]);
	}
}