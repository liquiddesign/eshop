<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use App\Admin\Controls\AdminForm;
use App\Admin\Controls\AdminFormFactory;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ParameterGroupRepository;
use Eshop\DB\ParameterRepository;
use Eshop\DB\ParameterValueRepository;
use Eshop\DB\Product;
use Nette\Application\UI\Control;
use Nette\DI\Container;

class ProductParametersForm extends Control
{
	private Product $product;

	private Container $container;

	private ParameterRepository $parameterRepository;

	private ParameterGroupRepository $parameterGroupRepository;

	private ParameterValueRepository $parameterValueRepository;

	private ?string $error = null;

	public function __construct(
		Product $product,
		Container $container,
		ParameterRepository $parameterRepository,
		ParameterGroupRepository $parameterGroupRepository,
		ParameterValueRepository $parameterValueRepository,
		CategoryRepository $categoryRepository
	)
	{
		$this->product = $product;
		$this->container = $container;
		$this->parameterRepository = $parameterRepository;
		$this->parameterGroupRepository = $parameterGroupRepository;
		$this->parameterValueRepository = $parameterValueRepository;

		$mutation = $this->parameterValueRepository->getConnection()->getMutation();

		/** @var \App\Admin\Controls\AdminForm $form */
		$form = $container->getService(AdminFormFactory::SERVICE_NAME)->create();
		$form->removeComponent($form->getComponent('uuid'));

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
					$allowedValues = \explode(';', $parameter->allowedValues ?? '');
					$input = $groupContainer->addLocaleDataMultiSelect($parameter->getPK(), $parameter->name, \array_combine($allowedValues, $allowedValues));
				} else {
					$input = $groupContainer->addLocaleText($parameter->getPK(), $parameter->name);
				}

				/** @var \Eshop\DB\ParameterValue $paramValue */
				$paramValue = $parameterValueRepository->many()->where('fk_product', $product->getPK())->where('fk_parameter', $parameter->getPK())->first();

				if ($paramValue && $paramValue->content) {
					$content = $paramValue->jsonSerialize()['content'];

					if ($paramValue->parameter->type == 'list') {
						foreach ($content as $k => $v) {
							$content[$k] = $v ? \explode(';', $v) : null;
						}
					}

					if ($parameter->type == 'bool') {
						$input->setDefaultValue($content[$mutation]);
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

				if ($paramValue = $this->parameterValueRepository->many()->where('fk_product', $this->product->getPK())->where('fk_parameter', $itemKey)->first()) {
					$paramValue->update([
						'content' => $itemValue
					]);
				} else {
					$this->parameterValueRepository->createOne([
						'content' => $itemValue,
						'product' => $this->product->getPK(),
						'parameter' => $itemKey
					]);
				}
			}
		}
	}

	public function render()
	{
		$this->template->error = $this->error;
		$this->template->render(__DIR__ . '/productParametersForm.latte');
	}
}