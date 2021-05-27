<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\Product;
use Nette\Application\UI\Control;

class ProductAttributesForm extends Control
{
	private Product $product;

	private AttributeRepository $attributeRepository;

	private AttributeValueRepository $attributeValueRepository;

	private AttributeAssignRepository $attributeAssignRepository;

	private ?string $error = null;

	public function __construct(
		Product $product,
		AdminFormFactory $adminFormFactory,
		AttributeRepository $attributeRepository,
		AttributeValueRepository $attributeValueRepository,
		AttributeAssignRepository $attributeAssignRepository,
		CategoryRepository $categoryRepository
	)
	{
		$this->product = $product;
		$this->attributeRepository = $attributeRepository;
		$this->attributeValueRepository = $attributeValueRepository;
		$this->attributeAssignRepository = $attributeAssignRepository;

		$form = $adminFormFactory->create();
		$form->removeComponent($form->getComponent('uuid'));

		$productCategory = $product->getPrimaryCategory();

		if (!$productCategory) {
			$this->error = 'Produkt nemá žádnou kategorii!';

			return;
		}

		$attributeCategories = $categoryRepository->getAttributeCategoriesOfCategory($productCategory);

		if (!$attributeCategories) {
			$this->error = 'Nenalezena kategorie atributů!';

			return;
		}

		foreach ($attributeCategories as $attributeCategory) {
			$attributes = $this->attributeRepository->getAttributes($attributeCategory, true);

			if (\count($attributes) == 0) {
				continue;
			}

			$form->addGroup($attributeCategory->name ?? $attributeCategory->code);

			foreach ($attributes as $attribute) {
				$attributeValues = $this->attributeRepository->getAttributeValues($attribute, true)->toArrayOf('label');

				$select = $form->addDataMultiSelect($attribute->getPK(), $attribute->name ?? $attribute->code, $attributeValues);

				$existingValues = $this->attributeAssignRepository->many()
					->join(['attributeValue' => 'eshop_attributevalue'],'this.fk_value = attributeValue.uuid')
					->where('fk_product', $this->product->getPK())
					->where('fk_value', \array_keys($attributeValues))
					->select(['existingValues' => 'attributeValue.uuid'])
					->toArrayOf('existingValues');

				$select->setDefaultValue(\array_values($existingValues));
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

		$this->attributeAssignRepository->many()->where('fk_product', $this->product->getPK())->delete();

		foreach ($values as $attributeKey => $attributeValues) {
			foreach ($attributeValues as $attributeValueKey) {
				$this->attributeAssignRepository->syncOne([
					'product' => $this->product->getPK(),
					'value' => $attributeValueKey
				]);
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