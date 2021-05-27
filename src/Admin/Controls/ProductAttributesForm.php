<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\DB\AttributeRelationRepository;
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

	private AttributeRelationRepository $attributeRelationRepository;

	private ?string $error = null;

	public function __construct(
		Product $product,
		AdminFormFactory $adminFormFactory,
		AttributeRepository $attributeRepository,
		AttributeValueRepository $attributeValueRepository,
		AttributeRelationRepository $attributeRelationRepository,
		CategoryRepository $categoryRepository
	)
	{
		$this->product = $product;
		$this->attributeRepository = $attributeRepository;
		$this->attributeValueRepository = $attributeValueRepository;
		$this->attributeRelationRepository = $attributeRelationRepository;

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
			$attributes = $this->attributeRepository->getAttributes($attributeCategory);

			if (\count($attributes) == 0) {
				continue;
			}

			$form->addGroup($attributeCategory->name ?? $attributeCategory->code);

			foreach ($attributes as $attribute) {
				$attributeValues = $this->attributeRepository->getAttributeValues($attribute)->toArrayOf('label');

				$form->addDataMultiSelect($attribute->getPK(), $attribute->name ?? $attribute->code, $attributeValues);
			}
		}

		$form->addSubmit('submit', 'Uložit');

		$form->onValidate[] = [$this, 'validate'];

		$form->onSuccess[] = [$this, 'submit'];

		$this->addComponent($form, 'form');
	}

	public function validate(AdminForm $form)
	{
		// Check for relations
	}

	public function submit(AdminForm $form)
	{
		$values = $form->getValues('array');

		bdump($values);

		$this->attributeRelationRepository->many()->where('fk_product', $this->product->getPK())->delete();

		foreach ($values as $containerKey => $container) {

		}

		$this->redirect('this');
	}

	public function render()
	{
		$this->template->error = $this->error;
		$this->template->render(__DIR__ . '/productParametersForm.latte');
	}
}