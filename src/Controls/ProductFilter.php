<?php
declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\AttributeCategoryRepository;
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

	private AttributeCategoryRepository $attributeCategoryRepository;

	private AttributeValueRepository $attributeValueRepository;

	private ?array $selectedCategory;

	public function __construct(
		FormFactory $formFactory,
		TranslationRepository $translator,
		CategoryRepository $categoryRepository,
		ProductRepository $productRepository,
		AttributeRepository $attributeRepository,
		AttributeCategoryRepository $attributeCategoryRepository,
		AttributeValueRepository $attributeValueRepository
	)
	{
		$this->translator = $translator;
		$this->formFactory = $formFactory;
		$this->categoryRepository = $categoryRepository;
		$this->productRepository = $productRepository;
		$this->attributeRepository = $attributeRepository;
		$this->attributeCategoryRepository = $attributeCategoryRepository;
		$this->attributeValueRepository = $attributeValueRepository;
	}

	/**
	 * @return \StORM\Entity[]
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getSelectedCategories(): array
	{
		$category = $this->getParent()->getFilters()['category'] ?? null;

		return $this->selectedCategory ??= $category ? $this->categoryRepository->getAttributeCategoriesOfCategory($this->categoryRepository->one(['path' => $category]))->toArray() : null;
	}

	public function render(): void
	{
		$collection = $this->getParent()->getSource()->setSelect(['this.uuid']);

		$this->template->attributeCounts = $this->attributeRepository->getCounts($collection);

		$this->template->render($this->template->getFile() ?: __DIR__ . '/productFilter.latte');
	}

	public function createComponentForm(): Form
	{
		$filterForm = $this->formFactory->create();

		$filterForm->addInteger('priceFrom')->setRequired()->setDefaultValue(0);
		$filterForm->addInteger('priceTo')->setRequired()->setDefaultValue(100000);

		$attributesContainer = $filterForm->addContainer('attributes');

		foreach ($this->getSelectedCategories() as $attributeCategory) {
			$attributes = $this->attributeRepository->getAttributes($attributeCategory)->where('showFilter', true);

			if (\count($attributes) == 0) {
				continue;
			}

			foreach ($attributes as $attribute) {
				$attributeValues = $this->attributeRepository->getAttributeValues($attribute)->toArrayOf('label');

				$attributesContainer->addCheckboxList($attribute->getPK(), $attribute->name ?? $attribute->code, $attributeValues);
			}
		}

		$filterForm->addSubmit('submit', $this->translator->translate('filter.showProducts', 'Zobrazit produkty'));

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

		$this->getPresenter()->redirect('this', ["$parent-priceFrom" => null, "$parent-priceTo" => null, "$parent-parameters" => null]);
	}

	public function handleClearFilter($filter): void
	{
		$filtersParameters = $this->getParent()->getFilters()['parameters'];
		$parent = $this->getParent()->getName();

		foreach ($filtersParameters as $key => $group) {
			foreach ($group as $pKey => $parameter) {
				if ($pKey == $filter) {
					unset($filtersParameters[$key][$pKey]);
					break;
				}
			}
		}

		$this->getPresenter()->redirect('this', ["$parent-parameters" => $filtersParameters]);
	}
}