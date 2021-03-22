<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\CustomerRepository;
use Eshop\Shopper;
use Grid\Datalist;
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

	public function handleLogin(string $user): void
	{
		$this->shopper->getMerchant()->update(['activeCustomer' => $user]);

		$this->getPresenter()->redirect(':Web:Index:default');
	}

	public function handleReset(): void
	{
		$this->setFilters(['name' => null]);
		$this->setOrder('fullname');
		$this->getPresenter()->redirect('this');
	}

	public function handleDeactivateCustomerAccount($customer)
	{
		if ($customer = $this->customerRepository->one($customer)) {
			if ($customer->account) {
				$customer->account->update(['active' => false]);
			}
		}

		$this->redirect('this');
	}

	public function handleActivateCustomerAccount($customer)
	{
		if ($customer = $this->customerRepository->one($customer)) {
			if ($customer->account) {
				$customer->account->update(['active' => true]);
			}
		}

		$this->redirect('this');
	}

	public function render(): void
	{
		$this->template->render($this->template->getFile() ?: __DIR__ . '/customerList.latte');
	}
}
