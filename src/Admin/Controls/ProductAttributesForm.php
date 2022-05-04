<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\Admin\ProductPresenter;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\Product;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Utils\Arrays;
use Nette\Utils\Random;

class ProductAttributesForm extends Control
{
	private Product $product;

	private AttributeRepository $attributeRepository;

	private AttributeAssignRepository $attributeAssignRepository;

	private AttributeValueRepository $attributeValueRepository;

	private ?string $error = null;

	private bool $errorEnabled;

	public function __construct(
		Product $product,
		AdminFormFactory $adminFormFactory,
		AttributeRepository $attributeRepository,
		AttributeAssignRepository $attributeAssignRepository,
		AttributeValueRepository $attributeValueRepository,
		bool $errorEnabled = true
	) {
		$this->product = $product;
		$this->attributeRepository = $attributeRepository;
		$this->attributeAssignRepository = $attributeAssignRepository;
		$this->attributeValueRepository = $attributeValueRepository;
		$this->errorEnabled = $errorEnabled;

		$form = $adminFormFactory->create(false, false, false, false, false);

		$this->monitor(Presenter::class, function (ProductPresenter $productPresenter) use ($form): void {
			$form->addHidden('editTab')->setDefaultValue($productPresenter->editTab);
		});

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

		/** @var \Eshop\DB\Attribute $attribute */
		foreach ($attributes as $attribute) {
			if ($attribute->isHardSystemic()) {
				continue;
			}

			$attributeValues = $this->attributeRepository->getAttributeValues($attribute, true)->toArrayOf('internalLabel');

			$select = $form->addMultiSelect2($attribute->getPK(), $attribute->name . ' (' . ($attribute->code ?? '-') . ')', $attributeValues, ['tags' => true]);

			$existingValues = $this->attributeAssignRepository->many()
				->join(['attributeValue' => 'eshop_attributevalue'], 'this.fk_value = attributeValue.uuid')
				->where('fk_product', $this->product->getPK())
				->where('fk_value', \array_keys($attributeValues))
				->select(['existingValues' => 'attributeValue.uuid'])
				->toArrayOf('existingValues', [], true);

			$select->setDefaultValue($existingValues);
		}

		$form->addSubmits(false, true);

		$form->onSuccess[] = [$this, 'submit'];

		$this->addComponent($form, 'form');
	}

	public function submit(AdminForm $form): void
	{
		$unsafeValues = $form->getHttpData();
		$values = $form->getValues('array');

		$this->attributeAssignRepository->many()->where('fk_product', $this->product->getPK())->delete();

		$editTab = Arrays::pick($values, 'editTab', null);

		$existingAttributeValues = $this->attributeValueRepository->many()->select(['attributePK' => 'this.fk_attribute'])->toArrayOf('attributePK');

		$mutation = $form->getPrimaryMutation() ?? 'cs';

		foreach (\array_keys($values) as $attributeKey) {
			foreach ($unsafeValues[$attributeKey] ?? [] as $attributeValueKey) {
				if (!isset($existingAttributeValues[$attributeValueKey])) {
					do {
						$code = Random::generate();

						$existingAttributeValue = $this->attributeValueRepository->one(['code' => $code]);
					} while ($existingAttributeValue);

					$attributeValueKey = $this->attributeValueRepository->createOne([
						'code' => $code,
						'label' => [$mutation => $attributeValueKey,],
						'attribute' => $attributeKey,
					])->getPK();
				}

				$this->attributeAssignRepository->syncOne([
					'product' => $this->product->getPK(),
					'value' => $attributeValueKey,
				]);
			}
		}

		$form->processRedirect('this', 'default', ['product' => $this->product, 'editTab' => $editTab]);
	}

	public function render(): void
	{
		$this->template->errorEnabled = $this->errorEnabled;
		$this->template->error = $this->error;

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render(__DIR__ . '/productAttributesForm.latte');
	}

	public function getError(): ?string
	{
		if (!$this->errorEnabled) {
			return null;
		}

		return $this->error;
	}
}
