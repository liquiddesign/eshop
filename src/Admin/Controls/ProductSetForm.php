<?php

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\SetRepository;
use Eshop\FormValidators;
use Nette\Application\UI\Control;
use Nette\Application\UI\Multiplier;
use Nette\Http\Request;

/** @deprecated */
class ProductSetForm extends Control
{
	private ProductRepository $productRepository;

	private AdminFormFactory $adminFormFactory;

	private SetRepository $setRepository;

	private Product $product;

	private Request $request;

	public function __construct(
		AdminFormFactory $adminFormFactory,
		ProductRepository $productRepository,
		SetRepository $setRepository,
		Request $request,
		Product $product
	) {
		$this->product = $product;
		$this->productRepository = $productRepository;
		$this->adminFormFactory = $adminFormFactory;
		$this->setRepository = $setRepository;
		$this->request = $request;
		\bdump('constructor');

		$form = $this->adminFormFactory->create();

		$form->addContainer('setItems');
		$form->addContainer('newRow');

		$form->addSubmit('submitSet');

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $this->request->getPost();
			\bdump($values, 'success');

			$this->product->update(['productsSet' => true]);
			$this->setRepository->many()->where('fk_set', $this->product->getPK())->delete();

			if (isset($values['newRow']['product'])) {
				$newItemValues = $values['newRow'];

				if ($this->productRepository->getProductByCodeOrEAN($newItemValues['product'])) {
					$newItemValues['set'] = $this->product->getPK();
					$newItemValues['product'] = $this->productRepository->getProductByCodeOrEAN($newItemValues['product']);
					$newItemValues['amount'] = isset($newItemValues['amount']) ? \intval($newItemValues['amount']) : 1;
					$newItemValues['priority'] = isset($newItemValues['priority']) ? \intval($newItemValues['priority']) : 1;
					$newItemValues['discountPct'] = isset($newItemValues['discountPct']) ? \floatval(\str_replace(',', '.', $newItemValues['discountPct'])) : 0;

					unset($newItemValues['submitSet']);

					$this->setRepository->createOne($newItemValues);
				}
			}

			unset($values['newRow']);

			foreach ($values['setItems'] ?? [] as $key => $item) {
				$item['product'] = $this->productRepository->getProductByCodeOrEAN($item['product']);

				if (!$item['product']) {
					continue;
				}

				$item['uuid'] = $key;
				$item['set'] = $this->product->getPK();
				$item['amount'] = isset($item['amount']) ? \intval($item['amount']) : 1;
				$item['priority'] = isset($item['priority']) ? \intval($item['priority']) : 1;
				$item['discountPct'] = isset($item['discountPct']) ? \floatval(\str_replace(',', '.', $item['discountPct'])) : 0;

				$this->setRepository->syncOne($item);
			}

			$form->reset();

			if ($this->getPresenter()->isAjax()) {
				$this->redrawControl('setFormSnippet');
			} else {
				$this->redirect('this');
			}
		};

		$this->addComponent($form, 'setForm');
	}

	public function createComponentSetItemForm(): Multiplier
	{
		return new Multiplier(function ($id) {
			\bdump($id);

			/** @var \Admin\Controls\AdminForm $form */
			$form = $this->getComponent('setForm');

			if ($id === 'null') {
				/** @var \Forms\Container $newRowContainer */
				$newRowContainer = $form->getComponent('newRow');

				$newRowContainer->addText('product')
					->addRule(
						[FormValidators::class, 'isProductExists'],
						'Produkt neexistuje!',
						[$this->productRepository],
					);
				$newRowContainer->addText('priority')->setDefaultValue(1)->addConditionOn(
					$newRowContainer['product'],
					$form::FILLED,
				)->addRule($form::INTEGER);
				$newRowContainer->addText('amount')->addConditionOn(
					$newRowContainer['product'],
					$form::FILLED,
				)->addRule($form::INTEGER);
				$newRowContainer->addText('discountPct')->setDefaultValue(0)
					->addConditionOn($newRowContainer['product'], $form::FILLED)
					->addRule($form::FLOAT)
					->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');

				$newRowContainer->addSubmit('submitSet');
			} else {
				$setItemsContainer = $form->getComponent('setItems');

				/** @var \Eshop\DB\Set $item */
				$item = $this->setRepository->one($id);

				$itemContainer = $setItemsContainer->addContainer($item->getPK());
				$itemContainer->addText('product')
					->addRule(
						[FormValidators::class, 'isProductExists'],
						'Produkt neexistuje!',
						[$this->productRepository],
					)
					->setDefaultValue($item->product->getFullCode());
				$itemContainer->addInteger('priority')->setDefaultValue($item->priority);
				$itemContainer->addInteger('amount')->setDefaultValue($item->amount);
				$itemContainer->addText('discountPct')->setDefaultValue($item->discountPct)
					->addRule($form::FLOAT)
					->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');
			}

			return $this->adminFormFactory->create();
		});
	}

	public function render(): void
	{
		\bdump('render');
		$this->template->existingSets = $this->productRepository->getSetProducts($this->product);
		$this->template->render(__DIR__ . '/productSetForm.latte');
	}

	public function handleDeleteSetItem($uuid): void
	{
		\bdump('handle');
		$this->setRepository->many()->where('uuid', $uuid)->delete();

		if ($this->getPresenter()->isAjax()) {
			$this->redrawControl('setFormSnippet');
		} else {
			$this->redirect('this');
		}
	}
}
