<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Merchant;
use Eshop\ShopperUser;
use Grid\Datalist;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use Nette\Localization\Translator;
use Nette\Utils\Arrays;
use Security\DB\AccountRepository;
use StORM\Collection;
use StORM\ICollection;

/**
 * Class Products
 * @package Eshop\Controls
 */
class AccountList extends Datalist
{
	/**
	 * @var array<callable(self, array|object): void|callable(array|object): void>
	 */
	public $onSuccess = [];

	public function __construct(
		private readonly ShopperUser $shopperUser,
		private readonly AccountRepository $accountRepository,
		private readonly CatalogPermissionRepository $catalogPermissionRepository,
		private readonly CustomerRepository $customerRepository,
		private readonly Translator $translator,
		?Collection $accounts = null
	) {
		parent::__construct($accounts ?? $this->accountRepository->many());

		$this->setDefaultOnPage(10);
		$this->setDefaultOrder('tsRegistered', 'DESC');

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
				$user = $this->shopperUser->getMerchant() ?? $this->shopperUser->getCustomer();

				$collection->join(['catalogpermission' => 'eshop_catalogpermission'], 'this.uuid = catalogpermission.fk_account')
					->join(['customer' => 'eshop_customer'], 'customer.uuid = catalogpermission.fk_customer')
					->join(['nxn' => 'eshop_merchant_nxn_eshop_customer'], 'customer.uuid = nxn.fk_customer');

				$collection->select(['customerFullname' => 'customer.fullname', 'customerCompany' => 'customer.company']);

				if ($user instanceof Merchant) {
					$collection->where('nxn.fk_merchant', $user);
				} else {
					$collection->where('customer.fk_parentCustomer', $user->getPK());
				}
			}
		}, '');

		$this->addFilterExpression('tsRegistered', function (ICollection $collection, $value): void {
			$collection->where('DATE(tsRegistered)', $value);
		}, '');

		/** @var \Forms\Form $form */
		$form = $this->getFilterForm();

		$form->addText('tsRegistered');
		$form->addText('login');
		$form->addSubmit('submit');
	}

	public function handleLogin(string $account): void
	{
		/** @var \Security\DB\Account $account */
		$account = $this->accountRepository->one($account, true);

		$customer = $this->customerRepository->many()
			->join(['catalogpermission' => 'eshop_catalogpermission'], 'this.uuid = catalogpermission.fk_customer')
			->where('catalogpermission.fk_account', $account->getPK())
			->first();

		$this->shopperUser->getMerchant()->update(['activeCustomer' => $customer->getPK()]);
		$this->shopperUser->getMerchant()->update(['activeCustomerAccount' => $account->getPK()]);

		$this->getPresenter()->redirect(':Web:Index:default');
	}

	public function handleReset(): void
	{
		$this->setFilters(null);
		$this->setOrder('login');
		$this->getPresenter()->redirect('this');
	}

	public function handleDeactivateAccount($account): void
	{
		$this->accountRepository->one($account)->update(['active' => false]);
		$this->redirect('this');
	}

	public function handleActivateAccount($account): void
	{
		$this->accountRepository->one($account)->update(['active' => true]);
		$this->redirect('this');
	}

	public function render(): void
	{
		$this->template->merchant = $merchant = $this->shopperUser->getMerchant() ?? $this->shopperUser->getCustomer();
		$this->template->isCustomer = $this->shopperUser->getCustomer();
		$this->template->selectedCustomer = $this->getPresenter()->getParameter('selectedCustomer');
		$this->template->paginator = $this->getPaginator();

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/accountList.latte');
	}

	public function createComponentChangePermFormMulti(): Multiplier
	{
		$catalogPermission = [
			'none' => $this->translator->translate('catalogPermission.notShown', 'Nezobrazeno'),
			'catalog' => $this->translator->translate('catalogPermission.withoutPrice', 'Bez cen'),
			'price' => $this->translator->translate('catalogPermission.withPrice', 'S cenami'),
		];

		return new Multiplier(function ($itemId) use ($catalogPermission) {
			/** @var \Security\DB\Account $account */
			$account = $this->getItemsOnPage()[$itemId];

			/** @var \Eshop\DB\CatalogPermission $catalogPerm */
			$catalogPerm = $this->catalogPermissionRepository->many()
				->where('fk_account', $account->getPK())
				->where('fk_customer', $this->getPresenter()->getParameter('selectedCustomer'))
				->first();

			$form = new Form();

			$form->addSelect('catalogPermission', null, $catalogPermission)->setDefaultValue($catalogPerm->catalogPermission);
			$form->addCheckbox('buyAllowed')->setHtmlAttribute('onChange', 'this.form.submit()')->setDefaultValue($catalogPerm->buyAllowed);
			$form->addCheckbox('viewAllOrders')->setHtmlAttribute('onChange', 'this.form.submit()')->setDefaultValue($catalogPerm->viewAllOrders);

			$form->onSuccess[] = function ($form, $values) use ($catalogPerm): void {
				$catalogPerm->update($values);
				$this->redirect('this');
			};

			return $form;
		});
	}

	public function createComponentChangePermForm(): Form
	{
		$catalogPermission = [
			'none' => $this->translator->translate('catalogPermission.notShown', 'Nezobrazeno'),
			'catalog' => $this->translator->translate('catalogPermission.withoutPrice', 'Bez cen'),
			'price' => $this->translator->translate('catalogPermission.withPrice', 'S cenami'),
		];

		$form = new Form();

		foreach ($this->getItemsOnPage() as $account) {
			$container = $form->addContainer($account->getPK());

			$container->addCheckbox('check');

			/** @var \Eshop\DB\CatalogPermission|null $catalogPerm */
			$catalogPerm = $this->catalogPermissionRepository->many()
				->where('fk_account', $account->getPK())
				->first();

			$catalogPermissionInput = $container->addSelect('catalogPermission', null, $catalogPermission);
			$buyAllowed = $container->addCheckbox('buyAllowed');
			$viewAllOrders = $container->addCheckbox('viewAllOrders');

			if (!$catalogPerm) {
				continue;
			}

			$catalogPermissionInput->setDefaultValue($catalogPerm->catalogPermission);
			$buyAllowed->setDefaultValue($catalogPerm->buyAllowed);
			$viewAllOrders->setDefaultValue($catalogPerm->viewAllOrders);
		}

		$form->addSelect('catalogPermission', null, [
			'none' => $this->translator->translate('accountList.PermNone', 'Nezobrazeno'),
			'catalog' => $this->translator->translate('accountList.PermCatalog', 'Bez cen'),
			'price' => $this->translator->translate('accountList.PermPrice', 'S cenami'),
		])->setPrompt($this->translator->translate('accountList.noChange', 'Původní'));
		$form->addSelect('buyAllowed', null, [
			false => $this->translator->translate('accountList.no', 'Ne'),
			true => $this->translator->translate('accountList.yes', 'Ano'),
		])->setPrompt($this->translator->translate('accountList.noChange', 'Původní'));
		$form->addSelect('viewAllOrders', null, [
			false => $this->translator->translate('accountList.no', 'Ne'),
			true => $this->translator->translate('accountList.yes', 'Ano'),
		])->setPrompt($this->translator->translate('accountList.noChange', 'Původní'));

		$form->addSubmit('submitAll');
		$form->addSubmit('submitBulk');

		$form->onSuccess[] = function (\Nette\Forms\Form $form): void {
			$values = $form->getValues('array');

			$bulkValues = [];

			$bulkValues['catalogPermission'] = Arrays::pick($values, 'catalogPermission');
			$bulkValues['buyAllowed'] = Arrays::pick($values, 'buyAllowed');
			$bulkValues['viewAllOrders'] = Arrays::pick($values, 'viewAllOrders');

			/** @var \Nette\Forms\Controls\SubmitButton $submitter */
			$submitter = $form->isSubmitted();
			$submitName = $submitter->getName();

			if ($submitName === 'submitAll') {
				foreach ($values as $key => $value) {
					unset($value['check']);
					$this->catalogPermissionRepository->many()->where('fk_account', $key)->update($value);
				}
			} elseif ($submitName === 'submitBulk') {
				$values = \array_filter($values, function ($value) {
					return $value['check'];
				});

				if (\count($values) === 0) {
					$values = $this->getFilteredSource()->toArray();
				}

				foreach ($bulkValues as $key => $value) {
					if ($value === null) {
						unset($bulkValues[$key]);
					}
				}

				if (\count($bulkValues) > 0) {
					foreach (\array_keys($values) as $key) {
						$this->catalogPermissionRepository->many()->where('fk_account', $key)->update($bulkValues);
					}
				}
			}

			$this->redirect('this');
		};

		return $form;
	}
}
