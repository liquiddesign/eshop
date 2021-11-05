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

	private bool $errorEnabled;

	public function __construct(
		Product $product,
		AdminFormFactory $adminFormFactory,
		AttributeRepository $attributeRepository,
		AttributeValueRepository $attributeValueRepository,
		AttributeAssignRepository $attributeAssignRepository,
		CategoryRepository $categoryRepository,
		bool $errorEnabled = true
	) {
		$this->product = $product;
		$this->attributeRepository = $attributeRepository;
		$this->attributeValueRepository = $attributeValueRepository;
		$this->attributeAssignRepository = $attributeAssignRepository;
		$this->errorEnabled = $errorEnabled;

		$form = $adminFormFactory->create(false, false, false, false, false);
		$form->removeComponent($form->getComponent('uuid'));
		$form->addGroup('Atributy');

		$productCategory = $product->getPrimaryCategory();

		if (!$productCategory) {
			$this->error = 'Produkt nemá žádnou kategorii!';

			return;
		}

		$categories = $categoryRepository->getBranch($productCategory);

		$attributes = $this->attributeRepository->getAttributesByCategories(\array_values($categories), true);

		if (\count($attributes) === 0) {
			$this->error = 'Produkt nemá žádné atributy!';

			return;
		}

		foreach ($attributes as $attribute) {
			$attributeValues = $this->attributeRepository->getAttributeValues($attribute, true)->toArrayOf('internalLabel');

			$select = $form->addDataMultiSelect($attribute->getPK(), $attribute->name ?? $attribute->code, $attributeValues);

			$existingValues = $this->attributeAssignRepository->many()
				->join(['attributeValue' => 'eshop_attributevalue'], 'this.fk_value = attributeValue.uuid')
				->where('fk_product', $this->product->getPK())
				->where('fk_value', \array_keys($attributeValues))
				->select(['existingValues' => 'attributeValue.uuid'])
				->toArrayOf('existingValues');

			$select->setDefaultValue(\array_values($existingValues));
		}

		$form->addSubmits(false, true);

		$form->onValidate[] = [$this, 'validate'];

		$form->onSuccess[] = [$this, 'submit'];

		$this->addComponent($form, 'form');
	}

	public function validate(AdminForm $form): void
	{
	}

	public function submit(AdminForm $form): void
	{
		$values = $form->getValues('array');

		$this->attributeAssignRepository->many()->where('fk_product', $this->product->getPK())->delete();

		foreach ($values as $attributeKey => $attributeValues) {
			foreach ($attributeValues as $attributeValueKey) {
				$this->attributeAssignRepository->syncOne([
					'product' => $this->product->getPK(),
					'value' => $attributeValueKey,
				]);
			}
		}

		$form->processRedirect('this', 'default', [$this->product]);
	}

	public function render(): void
	{
		$this->template->errorEnabled = $this->errorEnabled;
		$this->template->error = $this->error;
		$this->template->render(__DIR__ . '/productAttributesForm.latte');
	}
}
