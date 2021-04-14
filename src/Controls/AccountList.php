<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\CatalogPermission;
use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Merchant;
use Eshop\Shopper;
use Grid\Datalist;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use Security\DB\AccountRepository;
use StORM\Collection;
use StORM\ICollection;

/**
 * Class Products
 * @package Eshop\Controls
 */
class AccountList extends Datalist
{
	private Shopper $shopper;

	private AccountRepository $accountRepository;

	private CatalogPermissionRepository $catalogPermissionRepository;

	public function __construct(Collection $accounts, Shopper $shopper, AccountRepository $accountRepository, CatalogPermissionRepository $catalogPermissionRepository)
	{
		parent::__construct($accounts);

		$this->setDefaultOnPage(20);
		$this->setDefaultOrder('login');

		$this->addFilterExpression('login', function (ICollection $collection, $value): void {
			$collection->where('login LIKE :query', ['query' => '%' . $value . '%']);
		}, '');

		$this->getFilterForm()->addText('login');
		$this->getFilterForm()->addSubmit('submit');

		$this->shopper = $shopper;
		$this->accountRepository = $accountRepository;
		$this->catalogPermissionRepository = $catalogPermissionRepository;
	}

	public function handleLogin(string $customer): void
	{
		$this->shopper->getMerchant()->update(['activeCustomer' => $customer]);

		$this->getPresenter()->redirect(':Web:Index:default');
	}

	public function handleReset(): void
	{
		$this->setFilters(['login' => null]);
		$this->setOrder('login');
		$this->getPresenter()->redirect('this');
	}

	public function handleDeactivateAccount($account)
	{
		$this->accountRepository->one($account)->update(['active' => false]);
		$this->redirect('this');
	}

	public function handleActivateAccount($account)
	{
		$this->accountRepository->one($account)->update(['active' => true]);
		$this->redirect('this');
	}

	public function render(): void
	{
		$this->template->merchant = $merchant = $this->shopper->getMerchant() ?? $this->shopper->getCustomer();
		$this->template->customer = $this->getPresenter()->getParameter('customer');

		if ($merchant instanceof Merchant) {
			$this->template->customerGroup = $this->shopper->getMerchant()->customerGroup;
		}

		$this->template->paginator = $this->getPaginator();
		$this->template->render($this->template->getFile() ?: __DIR__ . '/accountList.latte');
	}

	public function createComponentChangePermForm(): Multiplier
	{
		return new Multiplier(function ($itemId) {
			/** @var \Security\DB\Account $account */
			$account = $this->getItemsOnPage()[$itemId];

			/** @var \Eshop\DB\CatalogPermission $catalogPerm */
			$catalogPerm = $this->catalogPermissionRepository->many()
				->where('fk_account', $account->getPK())
				->where('fk_customer', $this->getParameter('customer'))
				->first();

			$form = new Form();

			$form->addSelect('catalogPermission', null, [
				'none' => 'Žádné',
				'catalog' => 'Katalogy',
				'price' => 'Ceny'
			])->setDefaultValue($catalogPerm->catalogPermission);

			$form->addCheckbox('buyAllowed')->setHtmlAttribute('onChange','this.form.submit()')->setDefaultValue($catalogPerm->buyAllowed);

			$form->onSuccess[] = function ($form, $values) use ($catalogPerm): void {
				$catalogPerm->update([
					'catalogPermission' => $values->catalogPermission,
					'buyAllowed' => $values->buyAllowed
				]);
				$this->redirect('this');
			};

			return $form;
		});
	}
}
