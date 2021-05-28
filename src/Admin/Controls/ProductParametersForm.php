<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ParameterAvailableValueRepository;
use Eshop\DB\ParameterGroupRepository;
use Eshop\DB\ParameterRepository;
use Eshop\DB\ParameterValueRepository;
use Eshop\DB\Product;
use Nette\Application\UI\Control;
use Nette\Utils\Arrays;

class ProductParametersForm extends Control
{
	private Product $product;

	private ParameterRepository $parameterRepository;

	private ParameterGroupRepository $parameterGroupRepository;

	private ParameterValueRepository $parameterValueRepository;

	private ParameterAvailableValueRepository $parameterAvailableValueRepository;

	private ?string $error = null;

	public function __construct(
		Product $product,
		AdminFormFactory $adminFormFatory,
		ParameterRepository $parameterRepository,
		ParameterGroupRepository $parameterGroupRepository,
		ParameterValueRepository $parameterValueRepository,
		CategoryRepository $categoryRepository,
		ParameterAvailableValueRepository $parameterAvailableValueRepository
	)
	{
		$this->product = $product;
		$this->parameterRepository = $parameterRepository;
		$this->parameterGroupRepository = $parameterGroupRepository;
		$this->parameterValueRepository = $parameterValueRepository;
		$this->parameterAvailableValueRepository = $parameterAvailableValueRepository;

		$form = $adminFormFatory->create();
		$form->removeComponent($form->getComponent('uuid'));

		$productCategory = $product->getPrimaryCategory();

		if (!$productCategory) {
			$this->error = 'Produkt nemá žádnou kategorii!';

			return;
		}

		$parameterCategories = $categoryRepository->getParameterCategoriesOfCategory($productCategory);

		if (!$parameterCategories) {
			$this->error = 'Nenalezena kategorie parametrů!';

			return;
		}

		foreach ($parameterCategories as $parameterCategory) {
			/** @var \Eshop\DB\ParameterGroup[] $groups */
			$groups = $parameterGroupRepository->getCollection()
				->where('fk_parameterCategory', $parameterCategory->getPK());

			foreach ($groups as $group) {
				/** @var \Eshop\DB\Parameter[] $parameters */
				$parameters = $parameterRepository->getCollection()
					->where('fk_group', $group->getPK());

				if (\count($parameters) == 0) {
					continue;
				}

				$form->addGroup($group->name ?? $group->internalName);
				$groupContainer = $form->addContainer($group->getPK());

				foreach ($parameters as $parameter) {
					if ($parameter->type == 'bool') {
						$input = $groupContainer->addCheckbox($parameter->getPK(), $parameter->name);
					} elseif ($parameter->type == 'list') {
						$allowedKeys = \array_values($this->parameterAvailableValueRepository->many()->where('fk_parameter', $parameter->getPK())->toArrayOf('allowedKey'));
						$allowedValues = \array_values($this->parameterAvailableValueRepository->many()->where('fk_parameter', $parameter->getPK())->toArrayOf('allowedValue'));
						$input = $groupContainer->addDataMultiSelect($parameter->getPK(), $parameter->name, \array_combine($allowedKeys, $allowedValues));
					} else {
//						$input = $groupContainer->addLocaleText($parameter->getPK(), $parameter->name);
					}

					if ($parameter->type == 'bool') {
						if ($paramValue = $parameterValueRepository->many()->where('fk_product', $product->getPK())->where('value.fk_parameter', $parameter->getPK())->first()) {
							$input->setDefaultValue($paramValue->value->allowedKey);
						}
					} elseif ($parameter->type == 'list') {
						/** @var \Eshop\DB\ParameterValue[] $paramValue */
						$paramValues = $parameterValueRepository->many()
							->where('fk_product', $product->getPK())
							->where('value.fk_parameter', $parameter->getPK())
							->select(['allowedKey' => 'value.allowedKey'])
							->toArrayOf('allowedKey');
						$input->setDefaultValue(\array_values($paramValues));
					}
				}
			}
		}

		$form->addSubmit('submit', 'Uložit');

		$form->onValidate[] = [$this, 'validate'];

		$form->onSuccess[] = [$this, 'submit'];

		$this->addComponent($form, 'form');
	}

	public function validate(AdminForm $form)
	{

	}

	public function submit(AdminForm $form)
	{
		$values = $form->getValues('array');

		$this->parameterValueRepository->many()->where('fk_product', $this->product->getPK())->delete();

		foreach ($values as $containerKey => $container) {
			foreach ($container as $itemKey => $itemValue) {
				/** @var \Eshop\DB\Parameter $parameter */
				$parameter = $this->parameterRepository->one($itemKey);

				$itemValue = \is_array($itemValue) ? $itemValue : [$itemValue];

				foreach ($itemValue as $itemValueKey) {
					$availableValue = $this->parameterAvailableValueRepository->many()
						->where('fk_parameter', $parameter->getPK())
						->where('allowedKey', $itemValueKey)
						->first();

					$this->parameterValueRepository->createOne([
						'product' => $this->product->getPK(),
						'value' => $availableValue->getPK()
					]);
				}
			}
		}

		$this->redirect('this');
	}

	public function render()
	{
		$this->template->error = $this->error;
		$this->template->render(__DIR__ . '/productParametersForm.latte');
	}
}