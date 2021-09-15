<?php
declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\Attribute;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRangeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\ProductRepository;
use Nette\Application\UI\Control;
use StORM\Collection;
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

	private DisplayAmountRepository $displayAmountRepository;

	private DisplayDeliveryRepository $displayDeliveryRepository;

	private AttributeValueRangeRepository $attributeValueRangeRepository;

	private ?array $selectedCategories;

	public function __construct(
		FormFactory                   $formFactory,
		TranslationRepository         $translator,
		CategoryRepository            $categoryRepository,
		ProductRepository             $productRepository,
		AttributeRepository           $attributeRepository,
		AttributeValueRepository      $attributeValueRepository,
		DisplayAmountRepository       $displayAmountRepository,
		DisplayDeliveryRepository     $displayDeliveryRepository,
		AttributeValueRangeRepository $attributeValueRangeRepository,
		array                         $configuration = []
	)
	{
		$this->translator = $translator;
		$this->formFactory = $formFactory;
		$this->categoryRepository = $categoryRepository;
		$this->productRepository = $productRepository;
		$this->attributeRepository = $attributeRepository;
		$this->attributeValueRepository = $attributeValueRepository;
		$this->displayAmountRepository = $displayAmountRepository;
		$this->displayDeliveryRepository = $displayDeliveryRepository;
		$this->attributeValueRangeRepository = $attributeValueRangeRepository;
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
		/** @var Collection $collection */
		$collection = $this->getParent()->getFilteredSource()->setSelect(['this.uuid', 'this.fk_displayAmount', 'this.priority'])->setOrderBy(['this.priority']);

		$displayAmountCounts = $this->displayAmountRepository->many()->setGroupBy(['this.uuid'])
			->join(['product' => $collection], 'product.fk_displayAmount= this.uuid', $collection->getVars())
			->select(['count' => 'COUNT(DISTINCT product.uuid)'])
			->setOrderBy(['this.priority'])
			->toArray();

		$this->template->displayAmountCounts = $displayAmountCounts;

		/** @var Collection $collection */
		$collection = $this->getParent()->getFilteredSource()->setSelect(['this.uuid', 'this.fk_displayDelivery', 'this.priority'])->setOrderBy(['this.priority']);

		$displayDeliveryCounts = $this->displayDeliveryRepository->many()->setGroupBy(['this.uuid'])
			->join(['product' => $collection], 'product.fk_displayDelivery= this.uuid', $collection->getVars())
			->select(['count' => 'COUNT(DISTINCT product.uuid)'])
			->setOrderBy(['this.priority'])
			->toArray();

		$this->template->displayDeliveryCounts = $displayDeliveryCounts;
		$this->template->attributes = $this->attributeRepository->getAttributesByCategories($this->getSelectedCategories())->where('showFilter', true)->toArray();
		$this->template->attributesValuesCounts = $this->attributeRepository->getCounts($this->getParent()->getSource(), $this->getSelectedCategories(), $this->getParent()->getFilters()['attributes'] ?? []);
		$this->template->render($this->template->getFile() ?: __DIR__ . '/productFilter.latte');
	}

	public function createComponentForm(): Form
	{
		$filterForm = $this->formFactory->create();

		$filterForm->addInteger('priceFrom')->setRequired()->setDefaultValue(0);
		$filterForm->addInteger('priceTo')->setRequired()->setDefaultValue(100000);
		$filterForm->addCheckboxList('availability', null, $this->displayAmountRepository->getArrayForSelect());
		$filterForm->addCheckboxList('delivery', null, $this->displayDeliveryRepository->getArrayForSelect());

		$attributesContainer = $filterForm->addContainer('attributes');

		/** @var Attribute[] $attributes */
		$attributes = $this->attributeRepository->getAttributesByCategories($this->getSelectedCategories())->where('showFilter', true);

		/** @var ProductList $parent */
		$parent = $this->getParent();

		$defaults = $parent->getFilters()['attributes'] ?? [];

		foreach ($attributes as $attribute) {
			$attributeValues = $attribute->showRange ?
				$this->attributeValueRangeRepository->many()
					->join(['attributeValue' => 'eshop_attributevalue'], 'attributeValue.fk_attributeValueRange = this.uuid')
					->join(['attribute' => 'eshop_attribute'], 'attributeValue.fk_attribute = attribute.uuid')
					->toArrayOf('name') :
				$this->attributeRepository->getAttributeValues($attribute)->toArrayOf('label');

			if (\count($attributeValues) == 0) {
				continue;
			}

			$checkboxList = $attributesContainer->addCheckboxList($attribute->getPK(), $attribute->name ?? $attribute->code, $attributeValues);

			if (isset($defaults[$attribute->getPK()])) {
				$checkboxList->setDefaultValue($defaults[$attribute->getPK()]);
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

		$this->getPresenter()->redirect('this', [
			"$parent-priceFrom" => null,
			"$parent-priceTo" => null,
			"$parent-attributes" => null,
			"$parent-availability" => null,
			"$parent-delivery" => null
		]);
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
			}
		}

		$this->getPresenter()->redirect('this', ["$parent-attributes" => $filtersParameters]);
	}
}