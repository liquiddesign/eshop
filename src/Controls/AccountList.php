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

	private CustomerRepository $customerRepository;

	public function __construct(Shopper $shopper, AccountRepository $accountRepository, CatalogPermissionRepository $catalogPermissionRepository, CustomerRepository $customerRepository, ?Collection $accounts = null)
	{
		$this->shopper = $shopper;
		$this->accountRepository = $accountRepository;
		$this->catalogPermissionRepository = $catalogPermissionRepository;
		$this->customerRepository = $customerRepository;

		parent::__construct($accounts ?? $this->accountRepository->many());

		$this->setDefaultOnPage(20);
		$this->setDefaultOrder('login');

		$this->addFilterExpression('login', function (ICollection $collection, $value): void {
			$collection->where('login LIKE :query OR this.fullname LIKE :query', ['query' => '%' . $value . '%']);
		}, '');

		$this->addFilterExpression('customer', function (ICollection $collection, $customer): void {
			if ($customer) {
				$customer = $this->customerRepository->one($customer, true);

				$collection->join(['catalogpermission' => 'eshop_catalogpermission'], 'this.uuid = catalogpermission.fk_account')
					->join(['customer' => 'eshop_customer'], 'customer.uuid = catalogpermission.fk_customer')
					->where('catalogpermission.fk_customer', $customer->getPK());

				$collection->select(['customerFullname' => 'customer.fullname', 'customerCompany' => 'customer.company']);
			}
		}, '');

		$this->addFilterExpression('noCustomer', function (ICollection $collection, $enable): void {
			if ($enable) {
				$user = $this->shopper->getMerchant() ?? $this->shopper->getCustomer();

				$collection->join(['catalogpermission' => 'eshop_catalogpermission'], 'this.uuid = catalogpermission.fk_account')
					->join(['customer' => 'eshop_customer'], 'customer.uuid = catalogpermission.fk_customer');

				$collection->select(['customerFullname' => 'customer.fullname', 'customerCompany' => 'customer.company']);

				if ($user instanceof Merchant) {
					if ($user->customerGroup) {
						$collection->where('customer.fk_group=:customerGroup OR customer.fk_merchant=:merchant', [
							'customerGroup' => $user->customerGroup,
							'merchant' => $user,
						]);
					} else {
						$collection->where('customer.fk_merchant', $user);
					}
				} else {
					$collection->where('customer.fk_parentCustomer', $user->getPK());
				}
			}
		}, '');

		$this->getFilterForm()->addText('login');
		$this->getFilterForm()->addSubmit('submit');
	}

	public function handleLogin(string $account): void
	{
		$customer = $this->customerRepository->many()->join(['catalogpermission' => 'eshop_catalogpermission'], 'this.uuid = catalogpermission.fk_account')->first();

		$this->shopper->getMerchant()->update(['activeCustomer' => $this->getPresenter()->getParameter('customer') ?? $customer]);
		$this->shopper->getMerchant()->update(['activeCustomerAccount' => $account]);

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
		$this->template->isCustomer = $this->shopper->getCustomer();
		$this->template->selectedCustomer = $this->getPresenter()->getParameter('selectedCustomer');
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

			$form->addCheckbox('buyAllowed')->setHtmlAttribute('onChange', 'this.form.submit()')->setDefaultValue($catalogPerm->buyAllowed);

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
