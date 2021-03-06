<?php

declare(strict_types=1);

namespace Eshop\Controls;

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

	public function __construct(Collection $customers, Shopper $shopper)
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

	public function render(): void
	{
		$this->template->render(__DIR__ . '/customerList.latte');
	}
}
