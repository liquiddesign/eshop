<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\CustomerRepository;
use Eshop\DB\Merchant;
use Eshop\Shopper;
use Grid\Datalist;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use StORM\Collection;
use StORM\ICollection;

/**
 * Class Products
 * @package Eshop\Controls
 */
class CustomerList extends Datalist
{
	private Shopper $shopper;

	private CustomerRepository $customerRepository;

	public function __construct(Collection $customers, Shopper $shopper, CustomerRepository $customerRepository)
	{
		parent::__construct($customers);

		$this->setDefaultOnPage(20);
		$this->setDefaultOrder('fullname');

		$this->addFilterExpression('name', function (ICollection $collection, $value): void {
			$collection->where('company LIKE :query OR fullname LIKE :query', ['query' => '%' . $value . '%']);
		}, '');

		$this->getFilterForm()->addText('name');
		$this->getFilterForm()->addSubmit('submit');

		$this->shopper = $shopper;
		$this->customerRepository = $customerRepository;
	}

	public function handleReset(): void
	{
		$this->setFilters(['name' => null]);
		$this->setOrder('fullname');
		$this->getPresenter()->redirect('this');
	}

	public function handleLogin(string $customer): void
	{
		$this->shopper->getMerchant()->update(['activeCustomer' => $customer]);

		$this->getPresenter()->redirect(':Web:Index:default');
	}

	public function render(): void
	{
		$this->template->merchant = $merchant = $this->shopper->getMerchant() ?? $this->shopper->getCustomer();
		$this->template->customer = $this->shopper->getCustomer();
		$this->template->paginator = $this->getPaginator();
		$this->template->render($this->template->getFile() ?: __DIR__ . '/customerList.latte');
	}

	public function createComponentChangePermForm(): Multiplier
	{
		return new Multiplier(function ($itemId) {
			/** @var \Eshop\DB\Customer $customer */
			$customer = $this->getItemsOnPage()[$itemId];

			$form = new Form();

			$form->addSelect('orderPermission', null, [
				'fullWithApproval' => 'Pouze se schválením',
				'full' => 'Plné',
			])->setDefaultValue($customer->orderPermission);

			$form->onSuccess[] = function ($form, $values) use ($customer): void {
				$customer->update(['orderPermission' => $values->orderPermission]);
				$this->redirect('this');
			};

			return $form;
		});
	}
}
