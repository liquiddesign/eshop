<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\Controls\ProductFilter;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\AttributeRepository;
use Eshop\DB\Product;
use Nette\Application\UI\Control;
use Nette\Utils\Arrays;

class ProductAttributesForm extends Control
{
	private Product $product;

	private AttributeRepository $attributeRepository;

	private AttributeAssignRepository $attributeAssignRepository;

	private ?string $error = null;

	private bool $errorEnabled;

	public function __construct(
		Product $product,
		AdminFormFactory $adminFormFactory,
		AttributeRepository $attributeRepository,
		AttributeAssignRepository $attributeAssignRepository,
		bool $errorEnabled = true
	) {
		$this->product = $product;
		$this->attributeRepository = $attributeRepository;
		$this->attributeAssignRepository = $attributeAssignRepository;
		$this->errorEnabled = $errorEnabled;

		$form = $adminFormFactory->create(false, false, false, false, false);
		$form->removeComponent($form->getComponent('uuid'));
		$form->addGroup('Atributy');

		/** @var \Eshop\DB\Category[] $productCategories */
		$productCategories = $product->categories->toArray();

		if (\count($productCategories) === 0) {
			$this->error = 'Produkt nemá žádnou kategorii!';

			return;
		}

		$attributes = [];

		foreach ($productCategories as $category) {
			$attributes += $this->attributeRepository->getAttributesByCategory($category->path, true)->toArray();
		}

		if (\count($attributes) === 0) {
			$this->error = 'Produkt nemá žádné atributy!';

			return;
		}

		foreach ($attributes as $attribute) {
			if (Arrays::contains(\array_keys(ProductFilter::SYSTEMIC_ATTRIBUTES), $attribute->getPK())) {
				continue;
			}

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

		$form->onSuccess[] = [$this, 'submit'];

		$this->addComponent($form, 'form');
	}

	public function submit(AdminForm $form): void
	{
		$values = $form->getValues('array');

		$this->attributeAssignRepository->many()->where('fk_product', $this->product->getPK())->delete();

		foreach ($values as $attributeValues) {
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

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render(__DIR__ . '/productAttributesForm.latte');
	}
}
