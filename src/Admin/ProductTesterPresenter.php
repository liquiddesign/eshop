<?php

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Eshop\Admin\Controls\IProductTesterResultFactory;
use Eshop\Admin\Controls\ProductTesterResult;
use Eshop\BackendPresenter;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroup;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Nette\DI\Attributes\Inject;

class ProductTesterPresenter extends BackendPresenter
{
	#[Inject]
	public CustomerRepository $customerRepository;

	#[Inject]
	public CustomerGroupRepository $customerGroupRepository;

	#[Inject]
	public ProductRepository $productRepository;

	#[Inject]
	public IProductTesterResultFactory $productTesterResultFactory;

	public function actionDefault(array $tester): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('testerForm');

		$form->setDefaults($tester);
	}

	public function renderDefault(array $tester): void
	{
		$this->template->headerLabel = 'Tester produktů';
		$this->template->headerTree = [
			['Tester produktů'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('testerForm')];

		if ($tester) {
			$this->template->displayControls[] = $this->getComponent('result');
		}

		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('testerForm');

		if (isset($tester['product'])) {
			$product = $this->productRepository->one($tester['product'], true);
			/** @phpstan-ignore-next-line */
			$this->template->select2AjaxDefaults[$form['product']->getHtmlId()] = [$tester['product'] => $product->name];
		}

		if (isset($tester['customer'])) {
			$customer = $this->customerRepository->one($tester['customer'], true);
			/** @phpstan-ignore-next-line */
			$this->template->select2AjaxDefaults[$form['customer']->getHtmlId()] = [$tester['customer'] => $customer->fullname];
		}

		if (!isset($tester['group'])) {
			return;
		}

		$group = $this->customerGroupRepository->one($tester['group'], true);
		/** @phpstan-ignore-next-line */
		$this->template->select2AjaxDefaults[$form['group']->getHtmlId()] = [$tester['group'] => $group->name];
	}

	public function createComponentResult(): ProductTesterResult
	{
		return $this->productTesterResultFactory->create($this->getParameter('tester'));
	}

	public function createComponentTesterForm(): AdminForm
	{
		$form = $this->formFactory->create(defaultGroup: false);

		$form->monitor(BackendPresenter::class, function (BackendPresenter $presenter) use ($form): void {
			$typeInput = $form->addSelect('type', 'Hledat podle', [
				'customer' => 'Zákazník',
//				'group' => 'Skupina zákazníků',
//				'custom' => 'Ručně',
			])->setRequired();

			$form->addSelectAjax('product', 'Produkt', '- Vyberte produkt -', Product::class);

			$customerInput = $form->addSelectAjax('customer', 'Zákazník', '- Vyberte zákazníka -', Customer::class);
			$groupInput = $form->addSelectAjax('group', 'Skupina zákazníků', '- Vyberte skupinu -', CustomerGroup::class);

			$customerInput->addConditionOn($typeInput, $form::Equal, 'customer')
				->toggle($customerInput->getHtmlId() . '-toogle');

			$groupInput->addConditionOn($typeInput, $form::Equal, 'group')
				->toggle($groupInput->getHtmlId() . '-toogle');

			$form->addSubmit('submit', 'Spustit test');
		});

		$form->onValidate[] = function (AdminForm $form): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValuesWithAjax();

			if (!isset($values['product'])) {
				/** @phpstan-ignore-next-line */
				$form['product']->addError('Toto pole je povinné.');
			}

			if ($values['type'] === 'customer') {
				if (!isset($values['customer'])) {
					/** @phpstan-ignore-next-line */
					$form['customer']->addError('Toto pole je povinné.');
				}
			} elseif ($values['type'] === 'group') {
				if (!isset($values['group'])) {
					/** @phpstan-ignore-next-line */
					$form['group']->addError('Toto pole je povinné.');
				}
			} else {
				if (!isset($values['priceLists'])) {
					/** @phpstan-ignore-next-line */
					$form['priceLists']->addError('Toto pole je povinné.');
				}

				if (!isset($values['visibilityLists'])) {
					/** @phpstan-ignore-next-line */
					$form['visibilityLists']->addError('Toto pole je povinné.');
				}
			}
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValuesWithAjax();

			$this->redirect('this', ['tester' => $values]);
		};

		return $form;
	}
}
