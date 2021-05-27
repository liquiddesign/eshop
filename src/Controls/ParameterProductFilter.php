<?php
declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\CategoryRepository;
use Eshop\DB\ParameterAvailableValueRepository;
use Eshop\DB\ParameterCategory;
use Eshop\DB\ParameterCategoryRepository;
use Eshop\DB\ParameterGroupRepository;
use Eshop\DB\ParameterRepository;
use Eshop\DB\ProductRepository;
use Nette\Application\UI\Control;
use Translator\DB\TranslationRepository;
use Forms\FormFactory;
use Forms\Form;

class ParameterProductFilter extends Control
{
	private ParameterRepository $parameterRepository;

	private TranslationRepository $translator;

	private ParameterGroupRepository $parameterGroupRepository;

	private ParameterCategoryRepository $parameterCategoryRepository;

	private FormFactory $formFactory;

	private CategoryRepository $categoryRepository;

	private ProductRepository $productRepository;

	private ParameterAvailableValueRepository $parameterAvailableValueRepository;

	private ?array $selectedCategory;

	public function __construct(
		ParameterRepository $parameterRepository,
		TranslationRepository $translator,
		ParameterGroupRepository $parameterGroupRepository,
		ParameterCategoryRepository $parameterCategoryRepository,
		FormFactory $formFactory,
		CategoryRepository $categoryRepository,
		ProductRepository $productRepository,
		ParameterAvailableValueRepository $parameterAvailableValueRepository
	)
	{
		$this->parameterRepository = $parameterRepository;
		$this->translator = $translator;
		$this->parameterGroupRepository = $parameterGroupRepository;
		$this->parameterCategoryRepository = $parameterCategoryRepository;
		$this->formFactory = $formFactory;
		$this->categoryRepository = $categoryRepository;
		$this->productRepository = $productRepository;
		$this->parameterAvailableValueRepository = $parameterAvailableValueRepository;
	}

	/**
	 * @return \StORM\Entity[]
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getSelectedCategories(): array
	{
		$category = $this->getParent()->getFilters()['category'] ?? null;

		return $this->selectedCategory ??= $category ? $this->categoryRepository->getParameterCategoriesOfCategory($this->categoryRepository->one(['path' => $category]))->toArray() : null;
	}

	public function render(): void
	{
		$collection = $this->getParent()->getSource()->setSelect(['this.uuid']);

		$this->template->parameterCounts = $this->parameterRepository->getCounts($collection);

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
		$groups = $this->parameterGroupRepository->getCollection()->where('fk_parametercategory', \array_keys($this->getSelectedCategories()));

		foreach ($groups as $group) {
			/** @var \Eshop\DB\Parameter[] $parameters */
			$parameters = $this->parameterRepository->getCollection()->where('fk_group', $group->getPK());

			if (\count($parameters) == 0) {
				continue;
			}

			$groupContainer = $parametersContainer->addContainer($group->getPK());

			foreach ($parameters as $parameter) {
				if ($parameter->type == 'bool') {
					$groupContainer->addCheckbox($parameter->getPK(), $parameter->name);
				} elseif ($parameter->type == 'list') {
					$allowedKeys = \array_values($this->parameterAvailableValueRepository->many()->where('fk_parameter', $parameter->getPK())->toArrayOf('allowedKey'));
					$allowedValues = \array_values($this->parameterAvailableValueRepository->many()->where('fk_parameter', $parameter->getPK())->toArrayOf('allowedValue'));
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