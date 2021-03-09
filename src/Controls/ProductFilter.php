<?php
declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\Category;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ParameterCategory;
use Eshop\DB\ParameterCategoryRepository;
use Eshop\DB\ParameterGroupRepository;
use Eshop\DB\ParameterRepository;
use Nette\Application\UI\Control;
use Translator\DB\TranslationRepository;
use Forms\FormFactory;
use Forms\Form;

class ProductFilter extends Control
{
	/** @persistent */
	public $filters;

	/** @persistent */
	public $selectedCategory;

	private ParameterRepository $parameterRepository;

	private TranslationRepository $translator;

	private ParameterGroupRepository $parameterGroupRepository;

	private ParameterCategoryRepository $parameterCategoryRepository;

	private FormFactory $formFactory;

	private CategoryRepository $categoryRepository;

	public function __construct(
		ParameterRepository $parameterRepository,
		TranslationRepository $translator,
		ParameterGroupRepository $parameterGroupRepository,
		ParameterCategoryRepository $parameterCategoryRepository,
		FormFactory $formFactory,
		CategoryRepository $categoryRepository
	)
	{
		$this->parameterRepository = $parameterRepository;
		$this->translator = $translator;
		$this->parameterGroupRepository = $parameterGroupRepository;
		$this->parameterCategoryRepository = $parameterCategoryRepository;
		$this->formFactory = $formFactory;
		$this->categoryRepository = $categoryRepository;
	}

	/**
	 * @return \Eshop\DB\ParameterCategory|null
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getSelectedCategory(): ?ParameterCategory
	{
		return $this->selectedCategory ? $this->parameterCategoryRepository->one($this->selectedCategory) : null;
	}

	public function setParameterCategoryByCategory($category): void
	{
		if (!$category instanceof Category) {
			if (!$category = $this->categoryRepository->one($category)) {
				return;
			}
		}

		$parameterCategory = $this->categoryRepository->getParameterCategoryOfCategory($category);
		$this->selectedCategory = $parameterCategory ? $parameterCategory->getPK() : null;
	}

	public function render(): void
	{
		$this->setDefaults();
		$this->template->groups = $this->parameterGroupRepository->getCollection();
		$this->template->render($this->template->getFile() ?: __DIR__ . '/productFilter.latte');
	}

	public function createComponentFilterForm(): Form
	{
		$filterForm = $this->formFactory->create();

		$filterForm->addInteger('priceFrom')->setRequired()->setDefaultValue(0);
		$filterForm->addInteger('priceTo')->setRequired()->setDefaultValue(100000);

		$parametersContainer = $filterForm->addContainer('parameters');

		/** @var \Eshop\DB\ParameterGroup[] $groups */
		$groups = $this->parameterGroupRepository->getCollection()
			->where('fk_parametercategory', $this->getSelectedCategory());

		foreach ($groups as $group) {
			/** @var \Eshop\DB\Parameter[] $parameters */
			$parameters = $this->parameterRepository->many()
				->where('fk_group', $group->getPK());

			$groupContainer = $parametersContainer->addContainer($group->getPK());

			foreach ($parameters as $parameter) {
				if ($parameter->type == 'bool') {
					$groupContainer->addCheckbox($parameter->getPK(), $parameter->name);
				} elseif ($parameter->type == 'list') {
					$allowedValues = \explode(';', $parameter->allowedValues ?? '');
					$groupContainer->addMultiSelect($parameter->getPK(), $parameter->name, \array_combine($allowedValues, $allowedValues));
				} else {
					$groupContainer->addText($parameter->getPK(), $parameter->name);
				}
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
			$this->setFilters($form->getValues());
			$this->redirect('this');
		};

		return $filterForm;
	}

	public function setDefaults(): void
	{
		if (isset($this->filters)) {
			/** @var \Forms\Form $filterForm */
			$filterForm = $this->getComponent('filterForm');
			$filterForm->setDefaults($this->getFilters());
		}
	}

	public function setFilters($filters): void
	{
		$this->filters = \urlencode(\http_build_query($filters));
	}

	public function getFilters(): array
	{
		if (!isset($this->filters)) {
			return [];
		}

		\parse_str(\urldecode($this->filters), $filters);

		/** @var \Eshop\DB\Parameter[] $parameters */
		$parameters = $this->parameterRepository->getCollection()->toArray();

		$parametersFilters = &$filters['parameters'];

		foreach ($parametersFilters as $gKey => $group) {
			foreach ($group as $pKey => $parameter) {
				if (!isset($parameters[$pKey])) {
					continue;
				}

				$parametersFilters[$gKey][$pKey] = $parameters[$pKey]->type == 'bool' ? (bool)$parametersFilters[$gKey][$pKey] : $parametersFilters[$gKey][$pKey];
			}
		}

		return $filters;
	}

	public function getFiltersForTemplate(): array
	{
		$filters = $this->getFilters()['parameters'] ?? [];
		$templateFilters = [];

		/** @var \Eshop\DB\Parameter[] $parameters */
		$parameters = $this->parameterRepository->getCollection()->toArray();

		foreach ($filters as $key => $group) {
			foreach ($group as $pKey => $parameter) {
				if (\is_array($parameter)) {
					// list
					if (\count($parameter) == 0) {
						continue;
					}

					$templateFilters[$pKey] = $parameters[$pKey]->name . ': ' . \implode(', ', $parameter);
				} else {
					// bool, text
					if ($parameter) {
						$templateFilters[$pKey] =  $parameters[$pKey]->name . ($parameter !== true ? (': ' . $parameter) : '');
					}
				}
			}
		}

		return $templateFilters;
	}

	public function handleClearFilters(): void
	{
		$this->clearFilters();
		$this->redirect('this');
	}

	public function clearFilters(): void
	{
		$this->filters = null;
	}

	public function clearFilter($filter): void
	{
		$filters = $this->getFilters();
		$filtersParameters = $filters['parameters'];

		foreach ($filtersParameters as $key => $group) {
			foreach ($group as $pKey => $parameter) {
				if ($pKey == $filter) {
					unset($filters['parameters'][$key][$pKey]);
					break;
				}
			}
		}

		$this->setFilters($filters);
	}

	public function handleClearFilter($filter): void
	{
		$this->clearFilter($filter);
		$this->redirect('this');
	}
}