<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\DB\CategoryRepository;
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

	private ?string $error = null;

	public function __construct(
		Product $product,
		AdminFormFactory $adminFormFatory,
		ParameterRepository $parameterRepository,
		ParameterGroupRepository $parameterGroupRepository,
		ParameterValueRepository $parameterValueRepository,
		CategoryRepository $categoryRepository
	)
	{
		$this->product = $product;
		$this->parameterRepository = $parameterRepository;
		$this->parameterGroupRepository = $parameterGroupRepository;
		$this->parameterValueRepository = $parameterValueRepository;

		$form = $adminFormFatory->create();
		$form->removeComponent($form->getComponent('uuid'));

		$mutation = 'cs';

		$productCategory = $product->getPrimaryCategory();

		if (!$productCategory) {
			$this->error = 'Produkt nemá žádnou kategorii!';

			return;
		}

		$parameterCategory = $categoryRepository->getParameterCategoryOfCategory($productCategory);

		if (!$parameterCategory) {
			$this->error = 'Nenalezena kategorie parametrů!';

			return;
		}

		/** @var \Eshop\DB\ParameterGroup[] $groups */
		$groups = $parameterGroupRepository->getCollection()
			->where('fk_parameterCategory', $parameterCategory);

		foreach ($groups as $group) {
			/** @var \Eshop\DB\Parameter[] $parameters */
			$parameters = $parameterRepository->many()
				->where('fk_group', $group->getPK());

			$form->addGroup($group->name ?? ' ');
			$groupContainer = $form->addContainer($group->getPK());

			foreach ($parameters as $parameter) {
				if ($parameter->type == 'bool') {
					$input = $groupContainer->addCheckbox($parameter->getPK(), $parameter->name);
				} elseif ($parameter->type == 'list') {
					$allowedKeys = \explode(';', $parameter->allowedKeys ?? '');
					$allowedValues = \explode(';', $parameter->allowedValues ?? '');
					$input = $groupContainer->addDataMultiSelect($parameter->getPK(), $parameter->name, \array_combine($allowedKeys, $allowedValues));
				} else {
					$input = $groupContainer->addLocaleText($parameter->getPK(), $parameter->name);
				}

				/** @var \Eshop\DB\ParameterValue $paramValue */
				$paramValue = $parameterValueRepository->many()->where('fk_product', $product->getPK())->where('fk_parameter', $parameter->getPK())->first();

				if ($paramValue && ($paramValue->content || $paramValue->metaValue)) {
					$content = $paramValue->jsonSerialize()['content'];
					$metaValue = $paramValue->jsonSerialize()['metaValue'];

					if ($paramValue->parameter->type == 'list' && $metaValue) {
						$metaValue = \explode(';', $metaValue);
					}

					if ($parameter->type == 'bool' || $parameter->type == 'list') {
						$input->setDefaultValue($metaValue);
					} else {
						$input->setDefaults($content);
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

		$mutations = $this->parameterValueRepository->getConnection()->getAvailableMutations();

		foreach ($values as $containerKey => $container) {
			foreach ($container as $itemKey => $itemValue) {
				/** @var \Eshop\DB\Parameter $parameter */
				$parameter = $this->parameterRepository->one($itemKey);

				if (!\is_array($itemValue)) {
					$tempValue = [];

					foreach ($mutations as $mutationKey => $mutationValue) {
						$tempValue[$mutationKey] = $itemValue;
					}

					$itemValue = $tempValue;
				}

				foreach ($itemValue as $k => $v) {
					$itemValue[$k] = \is_array($v) ? \implode(';', $v) : $v;
				}

				$updateValues = [];

				if ($parameter->type == 'list') {
					$updateValues['metaValue'] = \implode(';', $itemValue);
				} elseif ($parameter->type == 'bool') {
					$updateValues['metaValue'] = (string)Arrays::first($itemValue);
				} else {
					$updateValues['content'] = $itemValue;
				}

				if ($paramValue = $this->parameterValueRepository->many()->where('fk_product', $this->product->getPK())->where('fk_parameter', $itemKey)->first()) {
					$paramValue->update($updateValues);
				} else {
					$this->parameterValueRepository->createOne([
							'product' => $this->product->getPK(),
							'parameter' => $itemKey
						] + $updateValues);
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