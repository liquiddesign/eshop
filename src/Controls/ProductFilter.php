<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRangeRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\ProducerRepository;
use Forms\Form;
use Forms\FormFactory;
use Nette\Application\UI\Control;
use Nette\Utils\Arrays;
use Translator\DB\TranslationRepository;

/**
 * @method onFormSuccess(array $parameters)
 * @property-read \Nette\Bridges\ApplicationLatte\Template $template
 */
class ProductFilter extends Control
{
	public const SYSTEMIC_ATTRIBUTES = [
		'producer' => 'Výrobce',
		'availability' => 'Dostupnost',
		'delivery' => 'Doručení',
	];
	
	/**
	 * @var callable[]&callable(): void; Occurs after product filter form success
	 */
	public $onFormSuccess;
	
	/** @var array<callable(static): void> Occurs when component is attached to presenter */
	public $onAnchor = [];
	
	private TranslationRepository $translator;
	
	private FormFactory $formFactory;
	
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
		AttributeRepository $attributeRepository,
		DisplayAmountRepository $displayAmountRepository,
		DisplayDeliveryRepository $displayDeliveryRepository,
		AttributeValueRangeRepository $attributeValueRangeRepository,
		ProducerRepository $producerRepository
	) {
		$this->translator = $translator;
		$this->formFactory = $formFactory;
		$this->attributeRepository = $attributeRepository;
		$this->displayAmountRepository = $displayAmountRepository;
		$this->displayDeliveryRepository = $displayDeliveryRepository;
		$this->attributeValueRangeRepository = $attributeValueRangeRepository;
		$this->producerRepository = $producerRepository;
	}
	
	public function render(): void
	{
		/** @var string[][][] $filters */
		$filters = $this->getProductList()->getFilters();
		
		$this->template->systemicCounts = [
			'availability' => $this->displayAmountRepository->getCounts($filters),
			//'delivery' => $this->displayDeliveryRepository->getCounts($filters),
			'producer' => $this->producerRepository->getCounts($filters),
		];
		
		$this->template->attributes = $attributes = $this->getAttributes();
		$this->template->clearLink = $this->presenter->link(':Eshop:Product:list', $this->getRootFilter());
		
		// @TODO: hodnoty se nacitaji 2x, zlepsit performance
		$values = [];
		
		foreach ($attributes as $attribute) {
			$values += $this->attributeRepository->getAttributeValues($attribute)->toArrayOf('uuid', [], true);
		}
		
		$this->template->attributesValuesCounts = $this->attributeRepository->getCounts($values, $filters);
		
		$this->template->render($this->template->getFile() ?: __DIR__ . '/productFilter.latte');
	}
	
	public function createComponentForm(): Form
	{
		$filterForm = $this->formFactory->create();
		
		/** @var \Grid\Datalist $datalist */
		$datalist = $this->getParent();
		
		$datalist->makeFilterForm($filterForm, false, true);
		
		$filterForm->addInteger('priceFrom')->setHtmlAttribute('placeholder', 0);
		$filterForm->addInteger('priceTo')->setHtmlAttribute('placeholder', 100000);
		
		//$filterForm->setDefaults($this->getProductList()->getFilters());
		
		$attributesContainer = $filterForm->addContainer('attributes');
		
		$defaults = $this->getProductList()->getFilters()['attributes'] ?? [];
		
		foreach ($this->getAttributes() as $attribute) {
			$attributeValues = Arrays::contains(\array_keys($this::SYSTEMIC_ATTRIBUTES), $attribute->getPK()) ?
				$this->getSystemicAttributeValues($attribute->getPK()) :
				($attribute->showRange ?
					$this->attributeValueRangeRepository->getCollection()
						->join(['attributeValue' => 'eshop_attributevalue'], 'attributeValue.fk_attributeValueRange = this.uuid')
						->where('attributeValue.fk_attribute', $attribute->getPK())
						->toArrayOf('name') :
					$this->attributeRepository->getAttributeValues($attribute)->toArrayOf('label'));
			
			if (!$attributeValues) {
				continue;
			}
			
			$checkboxList = $attributesContainer->addCheckboxList(
				$attribute->getPK(),
				$attribute->name ?? $attribute->code,
				$attributeValues,
			);
			
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
		
		return $filterForm;
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
	
	/**
	 * @return string[]
	 */
	private function getRootFilter(): array
	{
		foreach (['category', 'producer'] as $parameter) {
			if ($this->presenter->getParameter($parameter)) {
				return [$parameter => $this->presenter->getParameter($parameter)];
			}
		}
		
		return [];
	}
	
	/**
	 * @param string $uuid
	 * @return array<string, string>
	 */
	private function getSystemicAttributeValues(string $uuid): array
	{
		switch ($uuid) {
			case 'availability':
				return $this->displayAmountRepository->getArrayForSelect(false);
			case 'delivery':
				return $this->displayDeliveryRepository->getArrayForSelect(false);
			case 'producer':
				return $this->producerRepository->getCollection()
					->join(['product' => 'eshop_product'], 'product.fk_producer = this.uuid', [], 'INNER')
					->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'nxnCategory.fk_product = product.uuid')
					->join(['category' => 'eshop_category'], 'nxnCategory.fk_category = category.uuid')
					->where('category.path LIKE :s', ['s' => ($this->getCategoryPath() ?? '') . '%'])
					->toArrayOf('name');
			default:
				return [];
		}
	}
}
