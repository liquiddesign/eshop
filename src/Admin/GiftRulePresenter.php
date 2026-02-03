<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\GiftRule;
use Eshop\DB\GiftRuleProductRepository;
use Eshop\DB\GiftRuleRepository;
use Eshop\DB\Product;
use Nette\Application\UI\Presenter;
use StORM\DIConnection;

class GiftRulePresenter extends BackendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public GiftRuleRepository $giftRuleRepository;

	#[\Nette\DI\Attributes\Inject]
	public GiftRuleProductRepository $giftRuleProductRepository;

	#[\Nette\DI\Attributes\Inject]
	public CurrencyRepository $currencyRepository;

	#[\Nette\DI\Attributes\Inject]
	public CustomerGroupRepository $customerGroupRepository;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create(
			$this->giftRuleRepository->many(),
			20,
			'priority',
			'ASC',
			true,
		);

		$grid->addColumnSelector();
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnText('Cena od', 'priceFrom', '%s Kč', 'priceFrom', ['class' => 'text-right fit']);
		$grid->addColumnText('Cena do', 'priceTo', '%s Kč', 'priceTo', ['class' => 'text-right fit']);
		$grid->addColumnText('Měna', 'currency.code', '%s', 'currency.code', ['class' => 'fit']);
		$grid->addColumnText('Obchod', 'shop.name', '%s', 'shop.name', ['class' => 'fit']);
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('Aktivní', 'active', '', '', 'active');

		$grid->addColumnLink('products', 'Produkty');
		$grid->addColumnLinkDetail('detail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['name'], null, 'Název');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentForm(): AdminForm
	{
		$form = $this->formFactory->create(true, useShops: true);

		/** @var \Eshop\DB\GiftRule|null $rule */
		$rule = $this->getParameter('giftRule');

		$form->addText('name', 'Název pravidla')
			->setRequired();

		$form->addText('priceFrom', 'Cena od (Kč)')
			->setRequired()
			->addRule($form::FLOAT, 'Zadejte platné číslo');

		$form->addText('priceTo', 'Cena do (Kč)')
			->setRequired()
			->addRule($form::FLOAT, 'Zadejte platné číslo');

		$form->addSelect('currency', 'Měna', $this->currencyRepository->getArrayForSelect())
			->setRequired();

		$form->addMultiSelect2(
			'customerGroups',
			'Skupiny zákazníků',
			$this->customerGroupRepository->getArrayForSelect(),
		)->setHtmlAttribute('data-info', 'Prázdné = platí pro všechny skupiny');

		$form->addInteger('priority', 'Priorita')
			->setDefaultValue(10)
			->setRequired();

		$form->addCheckbox('active', 'Aktivní')
			->setDefaultValue(true);

		$form->addSubmits(!$rule);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$customerGroups = $values['customerGroups'] ?? [];
			unset($values['customerGroups']);

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			/** @var \Eshop\DB\GiftRule $object */
			$object = $this->giftRuleRepository->syncOne($values, null, true);

			// Sync M:N customer groups
			$object->customerGroups->unrelateAll();

			foreach ($customerGroups as $groupId) {
				$object->customerGroups->relate([$groupId]);
			}

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$object]);
		};

		return $form;
	}

	public function createComponentProductsGrid(): AdminGrid
	{
		/** @var \Eshop\DB\GiftRule $rule */
		$rule = $this->getParameter('giftRule');

		$grid = $this->gridFactory->create(
			$this->giftRuleProductRepository->getProductsForRule($rule),
			20,
			'priority',
			'ASC',
			true,
		);

		$grid->addColumnSelector();
		$grid->addColumnText('Produkt', 'product.name', '%s', 'product.name');
		$grid->addColumnText('Kód', 'product.code', '%s', 'product.code', ['class' => 'fit']);
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);

		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterButtons(['products', (string) $rule->getPK()]);

		return $grid;
	}

	public function createComponentProductForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		/** @var \Eshop\DB\GiftRule $rule */
		$rule = $this->getParameter('giftRule');

		$form->monitor(Presenter::class, function () use ($form, $rule): void {
			$form->addSelectAjax('product', 'Produkt', '- Vyberte produkt -', Product::class);

			$form->addInteger('priority', 'Priorita')
				->setDefaultValue(10)
				->setRequired();

			$form->addHidden('giftRule', $rule->getPK());

			$form->addSubmits(true, false);
		});

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValuesWithAjax();

			// Zkontrolujeme, zda produkt už není přiřazen k pravidlu
			$existing = $this->giftRuleProductRepository->many()
				->where('this.fk_giftRule', $values['giftRule'])
				->where('this.fk_product', $values['product'])
				->first();

			if ($existing !== null) {
				$this->flashMessage('Produkt je již přiřazen k tomuto pravidlu', 'warning');
				$this->redirect('products', $this->getParameter('giftRule'));
			}

			$values['uuid'] = DIConnection::generateUuid();

			$this->giftRuleProductRepository->createOne($values);

			$this->flashMessage('Produkt přidán', 'success');
			$this->redirect('products', $this->getParameter('giftRule'));
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Dárky k nákupu';
		$this->template->headerTree = [
			['Dárky k nákupu'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nové pravidlo dárků';
		$this->template->headerTree = [
			['Dárky k nákupu', 'default'],
			['Nové pravidlo'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function actionDetail(GiftRule $giftRule): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('form');
		$form->setDefaults($giftRule->toArray(['customerGroups']));
	}

	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail pravidla dárků';
		$this->template->headerTree = [
			['Dárky k nákupu', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function renderProducts(GiftRule $giftRule): void
	{
		$this->template->headerLabel = 'Produkty pro pravidlo: ' . $giftRule->name;
		$this->template->headerTree = [
			['Dárky k nákupu', 'default'],
			['Produkty'],
		];
		$this->template->displayButtons = [
			$this->createBackButton('default'),
			$this->createNewItemButton('productNew', [$giftRule]),
		];
		$this->template->displayControls = [$this->getComponent('productsGrid')];
	}

	public function renderProductNew(GiftRule $giftRule): void
	{
		$this->template->headerLabel = 'Přidat produkt do pravidla: ' . $giftRule->name;
		$this->template->headerTree = [
			['Dárky k nákupu', 'default'],
			['Produkty', 'products', $giftRule],
			['Nový produkt'],
		];
		$this->template->displayButtons = [$this->createBackButton('products', $giftRule)];
		$this->template->displayControls = [$this->getComponent('productForm')];
	}
}
