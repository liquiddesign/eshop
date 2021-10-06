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

/**
 * @method onFormSuccess(array $parameters)
 */
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
	
	private Collection $attributes;
	
	private ?array $selectedCategories;

	/**
	 * @var callable[]&callable(): void; Occurs after product filter form success
	 */
	public $onFormSuccess;
	
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
	
	private function getAttributes(): Collection
	{
		$categoryPath = $this->getCategoryPath();

		return $this->attributes ??= ($categoryPath ? $this->attributeRepository->getAttributesByCategory($categoryPath)->where('showFilter', true) :  $this->attributeRepository->many()->where('1=0'));
	}
	
	public function render(): void
	{
		// @TODO: fixed for non category based, not for UUID a encapsule to entity
		$category = $this->getCategoryPath();
		
		$this->template->attributes = $this->getAttributes()->toArray();
		
		$this->template->displayAmountCounts = $category ? $this->categoryRepository->getCountsGrouped('this.fk_displayAmount', $this->getProductList()->getFilters())[$category] ?? [] : [];
		$this->template->displayDeliveryCounts = $category ? $this->categoryRepository->getCountsGrouped('this.fk_displayDelivery', $this->getProductList()->getFilters())[$category] ?? []  : [];
		$this->template->attributesValuesCounts = $category ? $this->categoryRepository->getCountsGrouped('assign.fk_value', $this->getProductList()->getFilters())[$category] ?? []  : [];
		
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
		
		$defaults = $this->getProductList()->getFilters()['attributes'] ?? [];
		
		/** @var \Eshop\DB\Attribute $attribute */
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
