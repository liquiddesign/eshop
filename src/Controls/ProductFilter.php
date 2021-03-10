<?php
declare(strict_types=1);

namespace Eshop\Controls;

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
	private ParameterRepository $parameterRepository;

	private TranslationRepository $translator;

	private ParameterGroupRepository $parameterGroupRepository;

	private ParameterCategoryRepository $parameterCategoryRepository;

	private FormFactory $formFactory;

	private CategoryRepository $categoryRepository;

	private ?ParameterCategory $selectedCategory;

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
		$category = $this->getParent()->getFilters()['category'] ?? null;

		return $this->selectedCategory ??= $category ? $this->categoryRepository->getParameterCategoryOfCategory($this->categoryRepository->one(['path' => $category])) : null;
	}

	public function render(): void
	{
		$this->template->groups = $this->parameterGroupRepository->getCollection();
		$this->template->render($this->template->getFile() ?: __DIR__ . '/productFilter.latte');
	}

	public function createComponentForm(): Form
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
					$allowedKeys = \explode(';', $parameter->allowedKeys ?? '');
					$allowedValues = \explode(';', $parameter->allowedValues ?? '');
					$groupContainer->addCheckboxList($parameter->getPK(), $parameter->name, \array_combine($allowedKeys, $allowedValues));
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
			$parameters = [];

			$parent = $this->getParent()->getName();

			foreach ($form->getValues('array') as $name => $values) {
				$parameters["$parent-$name"] = $values;
			}


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