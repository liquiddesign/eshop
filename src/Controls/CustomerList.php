<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\ShopperUser;
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
	public function __construct(Collection $customers, private readonly ShopperUser $shopperUserUser)
	{
		parent::__construct($customers);

		$this->setDefaultOnPage(20);
		$this->setDefaultOrder('fullname');

		$this->addFilterExpression('name', function (ICollection $collection, $value): void {
			$collection->where('company LIKE :query OR fullname LIKE :query OR email LIKE :query', ['query' => '%' . $value . '%']);
		}, '');

		/** @var \Forms\Form $form */
		$form = $this->getFilterForm();

		$form->addText('name');
		$form->addSubmit('submit');
	}

	public function handleReset(): void
	{
		$this->setFilters(['name' => null]);
		$this->setOrder('fullname');
		$this->getPresenter()->redirect('this');
	}

	public function render(): void
	{
		$this->template->merchant = $this->shopperUserUser->getMerchant() ?? $this->shopperUserUser->getCustomer();
		$this->template->customer = $this->shopperUserUser->getCustomer();
		$this->template->paginator = $this->getPaginator();

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/customerList.latte');
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
