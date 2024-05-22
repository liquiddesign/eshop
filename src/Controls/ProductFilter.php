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
use Eshop\ShopperUser;
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
	public array $onFormSuccess;

	/** @var (callable(static $productFilter, array $attributes): array<\Eshop\DB\Attribute>)|null Called only on first call of getAttributes method. */
	public $onGetAttributes = null;

	/** @var null|callable(float $min): float */
	public $onGetPriceMin = null;

	/** @var null|callable(float $max): float */
	public $onGetPriceMax = null;

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

	/**
	 * @var array<string, array<string, int>>
	 */
	protected array $systemicCounts;

	/**
	 * @var array<string, int>
	 */
	protected array $attributesValuesCounts;

	public function __construct(
		protected FormFactory $formFactory,
		protected TranslationRepository $translator,
		protected AttributeRepository $attributeRepository,
		protected DisplayAmountRepository $displayAmountRepository,
		protected DisplayDeliveryRepository $displayDeliveryRepository,
		protected AttributeValueRangeRepository $attributeValueRangeRepository,
		protected ProducerRepository $producerRepository,
		protected SettingRepository $settingRepository,
		protected ShopperUser $shopperUser,
		Storage $storage
	) {
		$this->cache = new Cache($storage);
	}
	
	public function render(): void
	{
		/** @var array<array<array<string>>> $filters */
		$filters = $this->getProductList()->getFilters();

		$this->template->systemicCounts = $this->getSystemicCounts();
		
		$this->template->attributes = $this->getAttributes();
		$this->template->attributesDefaults = $filters['attributes'] ?? [];
		$this->template->attributesValuesCounts = $this->getAttributesValuesCounts();

		$this->template->mainPriceType = $this->shopperUser->getMainPriceType();

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render($template->getFile() ?: __DIR__ . '/productFilter.latte');
	}
	
	public function createComponentForm(): Form
	{
		$filterForm = $this->formFactory->create();
		
		$filterForm->setMethod('get');

		$filterForm->onRender[] = function ($filterForm): void {
			$filterForm->removeComponent($filterForm[Presenter::SIGNAL_KEY]);
		};

		$productList = $this->getProductList();

		$productList->getItemsOnPage();
		$providerOutput = $productList->getProviderOutput();

		$withVat = $this->shopperUser->getMainPriceType() === 'withVat';

		$priceFrom = $providerOutput[$withVat ? 'priceVatMin' : 'priceMin'] ?? 0;
		$priceTo = $providerOutput[$withVat ? 'priceVatMax' : 'priceMax'] ?? 100000;

		if ($this->onGetPriceMin) {
			$priceFrom = \call_user_func($this->onGetPriceMin, $priceFrom);
		}

		if ($this->onGetPriceMax) {
			$priceTo = \call_user_func($this->onGetPriceMax, $priceTo);
		}

		$filterForm->addText('priceFrom')
			->setNullable()
			->setHtmlAttribute('placeholder', $priceFrom)
			->addCondition($filterForm::Filled)->addRule($filterForm::Integer);

		$filterForm->addText('priceTo')
			->setNullable()
			->setHtmlAttribute('placeholder', $priceTo)
			->addCondition($filterForm::Filled)->addRule($filterForm::Integer);
		
		$attributesContainer = $filterForm->addContainer('attributes');
		
		$defaults = $productList->getFilters()['attributes'] ?? [];

		foreach ($this->getAttributes() as $attribute) {
			if (Arrays::contains(\array_keys($this::SYSTEMIC_ATTRIBUTES), $attribute->getPK())) {
				$attributeValues = $this->getSystemicAttributeValues((string) $attribute->getPK());
				$systemicCounts = $this->getSystemicCounts();

				foreach (\array_keys($attributeValues) as $attributeValue) {
					if (isset($systemicCounts[$attribute->getPK()][$attributeValue]) && $systemicCounts[$attribute->getPK()][$attributeValue] > 0) {
						continue;
					}

					if (isset($defaults[$attribute->getPK()]) && Arrays::contains($defaults[$attribute->getPK()], $attributeValue)) {
						continue;
					}

					unset($attributeValues[$attributeValue]);
				}
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

				$attributeValueCounts = $this->getAttributesValuesCounts();

				foreach (\array_keys($attributeValues) as $attributeValue) {
					if ((isset($attributeValueCounts[$attributeValue]) && $attributeValueCounts[$attributeValue] > 0)) {
						continue;
					}

					if (isset($defaults[$attribute->getPK()]) && Arrays::contains($defaults[$attribute->getPK()], $attributeValue)) {
						continue;
					}

					unset($attributeValues[$attributeValue]);
				}
			}
			
			if (!$attributeValues) {
				continue;
			}
			
			$checkboxList = $attributesContainer->addCheckboxList((string) $attribute->getPK(), $attribute->name ?? $attribute->code, $attributeValues);

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

	/**
	 * @return array<string, array<string, int>>
	 */
	protected function getSystemicCounts(): array
	{
		/** @var array<array<array<string>>> $filters */
		$filters = $this->getProductList()->getFilters();

		$providerOutput = $this->getProductList()->getProviderOutput();

		return $this->systemicCounts ??= [
			'availability' => $providerOutput['displayAmountsCounts'] ?? $this->displayAmountRepository->getCounts($filters),
			'delivery' => $providerOutput['displayDeliveriesCounts'] ?? $this->displayDeliveryRepository->getCounts($filters),
			'producer' => $providerOutput['producersCounts'] ?? $this->producerRepository->getCounts($filters),
		];
	}

	/**
	 * @return array<string, int>
	 */
	protected function getAttributesValuesCounts(): array
	{
		/** @var array<array<array<string>>> $filters */
		$filters = $this->getProductList()->getFilters();

		$providerOutput = $this->getProductList()->getProviderOutput();

		if (isset($this->attributesValuesCounts)) {
			return $this->attributesValuesCounts;
		}

		foreach ($this->rangeValues as $rangeId => $valuesIds) {
			foreach ($valuesIds as $valueId) {
				$this->template->attributesValuesCounts[$rangeId] ??= 0;
				$this->template->attributesValuesCounts[$rangeId] += $this->template->attributesValuesCounts[$valueId] ?? 0;
			}
		}

		return $this->attributesValuesCounts ??= ($providerOutput['attributeValuesCounts'] ?? $this->attributeRepository->getCounts($this->attributeValues, $filters));
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

				$mutationSuffix = $this->producerRepository->getConnection()->getMutationSuffix();

				$subQuery2 = $this->producerRepository->getConnection()->rows(['product' => 'eshop_product'])
					->where('product.fk_producer = this.uuid and nxnCategory.fk_product = product.uuid');

				$subQuery1 = $this->producerRepository->getConnection()->rows(['nxnCategory' => 'eshop_product_nxn_eshop_category'])
					->join(['category' => 'eshop_category'], 'nxnCategory.fk_category = category.uuid', type: 'INNER')
					->where('category.path LIKE :s', ['s' => $categoryPath . '%'])
					->where('EXISTS(' . $subQuery2->getSql() . ')');

				return $this->producerRepository->getCollection()
					->setSelect(['name' => "this.name$mutationSuffix"], keepIndex: true)
					->where('EXISTS(' . $subQuery1->getSql() . ')', $subQuery1->getVars())
					->toArrayOf('name');
			});
		}

		return [];
	}
}
