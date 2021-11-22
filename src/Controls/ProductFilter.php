<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRangeRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\ProducerRepository;
use Forms\Form;
use Forms\FormFactory;
use Nette\Application\UI\Control;
use Translator\DB\TranslationRepository;

/**
 * @method onFormSuccess(array $parameters)
 */
class ProductFilter extends Control
{
	/**
	 * @var callable[]&callable(): void; Occurs after product filter form success
	 */
	public $onFormSuccess;

	private TranslationRepository $translator;

	private FormFactory $formFactory;

	private CategoryRepository $categoryRepository;

	private AttributeRepository $attributeRepository;

	private DisplayAmountRepository $displayAmountRepository;

	private DisplayDeliveryRepository $displayDeliveryRepository;

	private AttributeValueRangeRepository $attributeValueRangeRepository;

	private ProducerRepository $producerRepository;

	/**
	 * @var \Eshop\DB\Attribute[]
	 */
	private array $attributes;

	public function __construct(
		FormFactory $formFactory,
		TranslationRepository $translator,
		CategoryRepository $categoryRepository,
		AttributeRepository $attributeRepository,
		DisplayAmountRepository $displayAmountRepository,
		DisplayDeliveryRepository $displayDeliveryRepository,
		AttributeValueRangeRepository $attributeValueRangeRepository,
		ProducerRepository $producerRepository
	) {
		$this->translator = $translator;
		$this->formFactory = $formFactory;
		$this->categoryRepository = $categoryRepository;
		$this->attributeRepository = $attributeRepository;
		$this->displayAmountRepository = $displayAmountRepository;
		$this->displayDeliveryRepository = $displayDeliveryRepository;
		$this->attributeValueRangeRepository = $attributeValueRangeRepository;
		$this->producerRepository = $producerRepository;
	}

	public function render(): void
	{
		// @TODO: fixed for non category based, not for UUID a encapsule to entity
		$category = $this->getCategoryPath();

		$this->template->attributes = $this->getAttributes();

		$this->template->displayAmountCounts = $category ? $this->categoryRepository->getCountsGrouped('this.fk_displayAmount', $this->getProductList()->getFilters())[$category] ?? [] : [];
		$this->template->displayDeliveryCounts = $category ? $this->categoryRepository->getCountsGrouped('this.fk_displayDelivery', $this->getProductList()->getFilters())[$category] ?? [] : [];
		$this->template->attributesValuesCounts = $category ? $this->categoryRepository->getCountsGrouped('assign.fk_value', $this->getProductList()->getFilters())[$category] ?? [] : [];
		$this->template->producersCount = $category ? $this->categoryRepository->getCountsGrouped('this.fk_producer', $this->getProductList()->getFilters())[$category] ?? [] : [];

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/productFilter.latte');
	}

	public function createComponentForm(): Form
	{
		$filterForm = $this->formFactory->create();

		$filterForm->addInteger('priceFrom')->setRequired()->setDefaultValue(0);
		$filterForm->addInteger('priceTo')->setRequired()->setDefaultValue(100000);
		$filterForm->addCheckboxList('availability', null, $this->displayAmountRepository->getArrayForSelect());
		$filterForm->addCheckboxList('delivery', null, $this->displayDeliveryRepository->getArrayForSelect());
		$filterForm->addCheckboxList('producers', null, $this->producerRepository->getCollection()
			->join(['product' => 'eshop_product'], 'product.fk_producer = this.uuid', [], 'INNER')
			->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'nxnCategory.fk_product = product.uuid')
			->join(['category' => 'eshop_category'], 'nxnCategory.fk_category = category.uuid')
			->where('category.path LIKE :s', ['s' => ($this->getCategoryPath() ?? '') . '%'])
			->toArrayOf('name'));

		$filterForm->setDefaults($this->getProductList()->getFilters());

		$attributesContainer = $filterForm->addContainer('attributes');

		$defaults = $this->getProductList()->getFilters()['attributes'] ?? [];

		foreach ($this->getAttributes() as $attribute) {
			$attributeValues = $attribute->showRange ?
				$this->attributeValueRangeRepository->many()
					->join(['attributeValue' => 'eshop_attributevalue'], 'attributeValue.fk_attributeValueRange = this.uuid')
					->where('attributeValue.fk_attribute', $attribute->getPK())
					->toArrayOf('name') :
				$this->attributeRepository->getAttributeValues($attribute)->toArrayOf('label');

			if (!$attributeValues) {
				continue;
			}

			$checkboxList = $attributesContainer->addCheckboxList($attribute->getPK(), $attribute->name ?? $attribute->code, $attributeValues);

			if (!isset($defaults[$attribute->getPK()])) {
				continue;
			}

			$checkboxList->setDefaultValue($defaults[$attribute->getPK()]);
		}

		$filterForm->addSubmit('submit', $this->translator->translate('filter.showProducts', 'Zobrazit produkty'));

		$filterForm->onValidate[] = function (\Nette\Forms\Container $form): void {
			$values = $form->getValues('array');

			if ($values['priceFrom'] <= $values['priceTo']) {
				return;
			}

			/** @var \Nette\Forms\Controls\TextInput $priceTo */
			$priceTo = $form['priceTo'];

			$priceTo->addError($this->translator->translate('filter.wrongPriceRange', 'Neplatný rozsah cen!'));
			$this->flashMessage($this->translator->translate('form.submitError', 'Chybně vyplněný formulář!'), 'error');
		};

		$filterForm->onSuccess[] = function (Form $form): void {
			$parameters = [];

			$parent = $this->getParent()->getName();

			foreach ($form->getValues('array') as $name => $values) {
				$parameters["$parent-$name"] = $values;
			}

			$this->onFormSuccess($parameters);
		};

		return $filterForm;
	}

	public function handleClearFilters(): void
	{
		$parent = $this->getParent()->getName();

		$this->getPresenter()->redirect('this', [
			"$parent-priceFrom" => null,
			"$parent-priceTo" => null,
			"$parent-attributes" => null,
			"$parent-availability" => null,
			"$parent-delivery" => null,
			"$parent-producers" => null,
		]);
	}

	public function handleClearFilter($searchedAttributeKey, $searchedAttributeValueKey = null): void
	{
		/** @var \Eshop\Controls\ProductList $parent */
		$parent = $this->getParent();
		$parentName = $parent->getName();
		$filters = $parent->getFilters();

		$filtersAttributes = $filters['attributes'] ?? [];
		$simpleFilters = $filters[$searchedAttributeKey] ?? [];

		if (\array_search($searchedAttributeKey, ['producers', 'availability', 'delivery']) !== false) {
			if (($index = \array_search($searchedAttributeValueKey, $simpleFilters)) !== false) {
				unset($simpleFilters[$index]);
				$simpleFilters = \array_values($simpleFilters);
			}

			$this->getPresenter()->redirect('this', ["$parentName-$searchedAttributeKey" => $simpleFilters]);
		}

		foreach ($filtersAttributes as $attributeKey => $attributeValues) {
			if ($attributeKey === $searchedAttributeKey) {
				if ($searchedAttributeValueKey) {
					$foundKey = \array_search($searchedAttributeValueKey, $attributeValues);
					unset($filtersAttributes[$attributeKey][$foundKey]);
					$filtersAttributes[$attributeKey] = \array_values($filtersAttributes[$attributeKey]);

					break;
				}
			}
		}

		$this->getPresenter()->redirect('this', ["$parentName-attributes" => $filtersAttributes,]);
	}

	private function getProductList(): ProductList
	{
		/** @var \Eshop\Controls\ProductList $parent */
		$parent = $this->getParent();

		return $parent;
	}

	private function getCategoryPath(): ?string
	{
		return $this->getProductList()->getFilters()['category'] ?? null;
	}

	/**
	 * @return \Eshop\DB\Attribute[]
	 */
	private function getAttributes(): array
	{
		return $this->attributes ??= $this->getCategoryPath() ? $this->attributeRepository->getAttributesByCategory($this->getCategoryPath())->where('showFilter', true)->toArray() : [];
	}
}
