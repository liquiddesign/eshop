<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\Admin\ScriptsPresenter;
use Eshop\DB\Attribute;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRangeRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\ProducerRepository;
use Forms\Form;
use Forms\FormFactory;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Utils\Arrays;
use StORM\Collection;
use Translator\DB\TranslationRepository;
use Web\DB\SettingRepository;

/**
 * @method onFormSuccess(array $parameters)
 */
class ProductFilter extends Control
{
	public const SYSTEMIC_ATTRIBUTES = [
		'producer' => 'Výrobce',
		'availability' => 'Dostupnost',
		'delivery' => 'Doručení',
	];
	
	/**
	 * @var array<callable>&callable(): void ; Occurs after product filter form success
	 */
	public $onFormSuccess;
	
	/** @var array<callable(static): void> Occurs when component is attached to presenter */
	public $onAnchor = [];

	/** @var (callable(static $productFilter, array $attributes): array<\Eshop\DB\Attribute>)|null Called only on first call of getAttributes method. */
	public $onGetAttributes = null;

	protected Cache $cache;
	
	/**
	 * @var array<\Eshop\DB\Attribute>
	 */
	protected array $attributes;
	
	/**
	 * @var array<string>
	 */
	protected array $attributeValues = [];
	
	/**
	 * @var array<array<string>>
	 */
	protected array $rangeValues = [];
	
	public function __construct(
		protected FormFactory $formFactory,
		protected TranslationRepository $translator,
		protected AttributeRepository $attributeRepository,
		protected DisplayAmountRepository $displayAmountRepository,
		protected DisplayDeliveryRepository $displayDeliveryRepository,
		protected AttributeValueRangeRepository $attributeValueRangeRepository,
		protected ProducerRepository $producerRepository,
		protected SettingRepository $settingRepository,
		Storage $storage
	) {
		$this->cache = new Cache($storage);
	}
	
	public function render(): void
	{
		/** @var array<array<array<string>>> $filters */
		$filters = $this->getProductList()->getFilters();

		$this->template->systemicCounts = [
			'availability' => $this->getProductList()->getCachedCounts()['displayAmountsCounts'] ?? $this->displayAmountRepository->getCounts($filters),
			//'delivery' => $this->displayDeliveryRepository->getCounts($filters),
			'producer' => $this->getProductList()->getCachedCounts()['producersCounts'] ?? $this->producerRepository->getCounts($filters),
		];
		
		$this->template->attributes = $this->getAttributes();
		$this->template->attributesDefaults = $filters['attributes'] ?? [];
		
		$this->template->attributesValuesCounts = $this->getProductList()->getCachedCounts()['attributeValuesCounts'] ?? $this->attributeRepository->getCounts($this->attributeValues, $filters);
		
		foreach ($this->rangeValues as $rangeId => $valuesIds) {
			foreach ($valuesIds as $valueId) {
				$this->template->attributesValuesCounts[$rangeId] ??= 0;
				$this->template->attributesValuesCounts[$rangeId] += $this->template->attributesValuesCounts[$valueId] ?? 0;
			}
		}

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render($this->template->getFile() ?: __DIR__ . '/productFilter.latte');
	}
	
	public function createComponentForm(): Form
	{
		$filterForm = $this->formFactory->create();
		
		$filterForm->setMethod('get');
		
		$filterForm->onRender[] = function ($filterForm): void {
			$filterForm->removeComponent($filterForm[Presenter::SIGNAL_KEY]);
		};

		/** @TODO set default values based on displayed products */
		$filterForm->addInteger('priceFrom')->setRequired()->setDefaultValue(0)->setHtmlAttribute('placeholder', 0);
		$filterForm->addInteger('priceTo')->setRequired()->setDefaultValue(100000)->setHtmlAttribute('placeholder', 100000);
		
		$attributesContainer = $filterForm->addContainer('attributes');
		
		$defaults = $this->getProductList()->getFilters()['attributes'] ?? [];
		
		foreach ($this->getAttributes() as $attribute) {
			if (Arrays::contains(\array_keys($this::SYSTEMIC_ATTRIBUTES), $attribute->getPK())) {
				$attributeValues = $this->getSystemicAttributeValues($attribute->getPK());
			} else {
				$attributeValues = $this->attributeRepository->getAttributeValues($attribute)->toArrayOf('label');
				$this->attributeValues = \array_merge($this->attributeValues, \array_keys($attributeValues));
				
				if ($attribute->showRange) {
					$attributeValues = [];
					
					/** @var \Eshop\DB\AttributeValueRange $rangeAttribute */
					foreach ($this->getRangeValues($attribute) as $rangeAttribute) {
						$attributeValues[$rangeAttribute->getPK()] = $rangeAttribute->name;
						$this->rangeValues[$rangeAttribute->getPK()] = \explode(',', $rangeAttribute->concatValues);
					}
				}
			}
			
			if (!$attributeValues) {
				continue;
			}
			
			$checkboxList = $attributesContainer->addCheckboxList($attribute->getPK(), $attribute->name ?? $attribute->code, $attributeValues);
			
			if (!isset($defaults[$attribute->getPK()])) {
				continue;
			}
			
			$checkboxList->setDefaultValue($defaults[$attribute->getPK()]);
		}
		
		$submit = $filterForm->addSubmit('submit', $this->translator->translate('filter.showProducts', 'Zobrazit produkty'));
		$submit->setHtmlAttribute('name', '');
		
		
		$filterForm->setDefaults($this->getPresenter()->getParameters());
		
		return $filterForm;
	}
	
	/**
	 * @param string|null $rootIndex
	 * @param string|null $valueIndex
	 * @return array<mixed>|array<array<mixed>>|array<array<array<mixed>>>
	 */
	public function getClearFilters(?string $rootIndex = null, ?string $valueIndex = null): array
	{
		if (!$rootIndex) {
			foreach (['category', 'producer'] as $parameter) {
				if ($this->presenter->getParameter($parameter)) {
					return [$parameter => $this->presenter->getParameter($parameter)];
				}
			}
			
			return [];
		}
		
		/** @var array<array<array<string>>> $filters */
		$filters = $this->getProductList()->getFilters();
		
		if ($valueIndex) {
			unset($filters['attributes'][$rootIndex][$valueIndex]);
			$key = \array_search($valueIndex, $filters['attributes'][$rootIndex]);
			
			if ($key !== false) {
				unset($filters['attributes'][$rootIndex][$key]);
			}

			$filters['attributes'][$rootIndex] = \array_values($filters['attributes'][$rootIndex]);
		} else {
			unset($filters['attributes'][$rootIndex]);

			$filters['attributes'] = \array_values($filters['attributes']);
		}
		
		if (isset($filters['category'])) {
			$filters['category'] = $this->presenter->getParameter('category');
		}
		
		return $filters;
	}
	
	protected function getProductList(): ProductList
	{
		/** @var \Eshop\Controls\ProductList $parent */
		$parent = $this->getParent();
		
		return $parent;
	}
	
	protected function getCategoryPath(): ?string
	{
		return $this->getProductList()->getFilters()['category'] ?? null;
	}
	
	/**
	 * @return array<\Eshop\DB\Attribute>
	 */
	protected function getAttributes(): array
	{
		if (isset($this->attributes)) {
			return $this->attributes;
		}

		$attributes = $this->getCategoryPath() ? $this->attributeRepository->getAttributesByCategory($this->getCategoryPath())->where('showFilter', true)->toArray() : [];

		if ($this->onGetAttributes) {
			$attributes = \call_user_func($this->onGetAttributes, $this, $attributes);
		}

		return $this->attributes = $attributes;
	}
	
	protected function getRangeValues(Attribute $attribute): Collection
	{
		return $this->attributeValueRangeRepository->getCollection()
			->join(['attributeValue' => 'eshop_attributevalue'], 'attributeValue.fk_attributeValueRange = this.uuid')
			->where('attributeValue.fk_attribute', $attribute->getPK())
			->select(['concatValues' => 'GROUP_CONCAT(attributeValue.uuid)'])
			->setGroupBy(['this.uuid']);
	}
	
	/**
	 * @param string $uuid
	 * @return array<string, string>
	 */
	protected function getSystemicAttributeValues(string $uuid): array
	{
		if ($uuid === 'availability') {
			return $this->displayAmountRepository->getArrayForSelect(false);
		}

		if ($uuid === 'delivery') {
			return $this->displayDeliveryRepository->getArrayForSelect(false);
		}

		if ($uuid === 'producer') {
			$categoryPath = $this->getCategoryPath() ?? '';

			return $this->cache->load("getSystemicAttributeValues-$uuid-$categoryPath", function (&$dependencies) use ($categoryPath) {
				$dependencies = [
					Cache::Tags => [ScriptsPresenter::ATTRIBUTES_CACHE_TAG, ScriptsPresenter::PRODUCERS_CACHE_TAG,],
				];

				return $this->producerRepository->getCollection()
					->join(['product' => 'eshop_product'], 'product.fk_producer = this.uuid', [], 'INNER')
					->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'nxnCategory.fk_product = product.uuid')
					->join(['category' => 'eshop_category'], 'nxnCategory.fk_category = category.uuid')
					->where('category.path LIKE :s', ['s' => $categoryPath . '%'])
					->toArrayOf('name');
			});
		}

		return [];
	}
}
