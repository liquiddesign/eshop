<?php

declare(strict_types=1);

namespace Eshop\Front\Eshop;

use Eshop\Controls\AccountList;
use Eshop\Controls\CustomerList;
use Eshop\Controls\IAccountListFactory;
use Eshop\Controls\ICustomerListFactory;
use Eshop\Controls\IProfileFormFactory;
use Eshop\Controls\IWatcherListFactory;
use Eshop\Controls\ProfileForm;
use Eshop\Controls\WatcherList;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Merchant;
use Eshop\DB\OrderRepository;
use Eshop\Shopper;
use Forms\FormFactory;
use Nette;

abstract class ProfilePresenter extends \Eshop\Front\FrontendPresenter
{
	/** @inject */
	public \Forms\Bridges\FormsSecurity\IChangePasswordFormFactory $changePasswordFormFactory;

	/** @inject */
	public IProfileFormFactory $profileFormFactory;

	/** @inject */
	public ICustomerListFactory $customerListFactory;

	/** @inject */
	public IWatcherListFactory $watcherListFactory;

	/** @inject */
	public CustomerRepository $customerRepository;

	/** @inject */
	public OrderRepository $orderRepository;

	/** @inject */
	public Shopper $shopper;

	/** @inject */
	public FormFactory $formFactory;

	/** @inject */
	public IAccountListFactory $accountListFactory;

	/** @persistent */
	public ?string $statsFrom;

	/** @persistent */
	public ?string $statsTo;

	public function checkRequirements($element): void
	{
		parent::checkRequirements($element);

		if ($this->getUser()->isLoggedIn()) {
			return;
		}

		$this->redirect(':Web:Index:default');
	}

	public function createComponentStatsFilterForm(): Nette\Application\UI\Form
	{
		$form = new Nette\Application\UI\Form();

		$form->addText('from', $this->translator->translate('Profile.from', 'Od'))
			->setHtmlAttribute('min', (new Nette\Utils\DateTime())->modify('- 1 year')->format('Y-m-d'))
			->setHtmlAttribute('max', (new Nette\Utils\DateTime())->format('Y-m-d'))
			->setHtmlType('date');
		$form->addText('to', $this->translator->translate('Profile.to', 'Do'))
			->setHtmlAttribute('min', (new Nette\Utils\DateTime())->modify('- 1 year')->format('Y-m-d'))
			->setHtmlAttribute('max', (new Nette\Utils\DateTime())->format('Y-m-d'))
			->setHtmlType('date');
		$form->addSubmit('submit', $this->translator->translate('Profile.show', 'Zobrazit'));

		$form->onSuccess[] = function (Nette\Forms\Form $form): void {
			$values = $form->getValues('array');

			if ($values['from'] > $values['to']) {
				$this->flashMessage($this->translator->translate('Profile.invalidRange', 'Neplatný rozsah!'), 'danger');
				$this->redirect('this');
			}

			$this->statsFrom = $values['from'];
			$this->statsTo = $values['to'];

			$this->redirect('this');
		};

		return $form;
	}

	public function handleResetStatsFilter(): void
	{
		$this->statsFrom = null;
		$this->statsTo = null;
		$this->redirect('this');
	}

	public function actionStats(): void
	{
		/** @var \Nette\Application\UI\Form $form */
		$form = $this->getComponent('statsFilterForm');

		/** @var \Nette\Forms\Controls\TextInput $from */
		$from = $form['from'];
		/** @var \Nette\Forms\Controls\TextInput $to */
		$to = $form['to'];

		$from->setDefaultValue($this->statsFrom ?? (new Nette\Utils\DateTime())->modify('- 1 year')->format('Y-m-d'));
		$to->setDefaultValue($this->statsTo ?? (new Nette\Utils\DateTime())->format('Y-m-d'));
	}

	public function renderStats(): void
	{
		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		$breadcrumb->addItem($this->translator->translate('.myAccount', 'Můj účet'));
		$breadcrumb->addItem($this->translator->translate('.stats', 'Statistiky'));

		$currency = $this->shopper->getCurrency();

		$user = $this->shopper->getCustomer() ?? $this->shopper->getMerchant();
		$from = isset($this->statsFrom) ? (new Nette\Utils\DateTime($this->statsFrom)) : ((new Nette\Utils\DateTime())->modify('- 1 year'));
		$to = isset($this->statsTo) ? (new Nette\Utils\DateTime($this->statsTo)) : (new Nette\Utils\DateTime());

		/** @var array<\Eshop\DB\Order> $orders */
		$orders = $this->orderRepository->getOrdersByUser($user)->toArray();

		$this->template->monthlyOrders = $this->orderRepository->getGroupedOrdersPrices($orders, $from, $to, $currency);
		$this->template->boughtCategories = $this->orderRepository->getOrdersCategoriesGroupedByAmountPercentage($orders, $currency);
		$this->template->topProducts = $this->orderRepository->getOrdersTopProductsByAmount($orders, $currency);
	}
	
	public function renderStatsMerchant(): void
	{
		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		$breadcrumb->addItem($this->translator->translate('.myAccount', 'Můj účet'));
		$breadcrumb->addItem($this->translator->translate('.stats', 'Statistiky'));
	}
	
	public function renderEdit(): void
	{
		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		$breadcrumb->addItem($this->translator->translate('.myAccount', 'Můj účet'));
		$breadcrumb->addItem($this->translator->translate('.contactInfo', 'Kontaktní údaje a adresy'));
	}
	
	public function renderCustomers(): void
	{
		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		$breadcrumb->addItem($this->translator->translate('.myAccount', 'Můj účet'));
		$breadcrumb->addItem($this->translator->translate('.myCustomers', 'Moji zákazníci'));
	}
	
	public function renderWatchers(): void
	{
		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		$breadcrumb->addItem($this->translator->translate('.myAccount', 'Můj účet'));
		$breadcrumb->addItem($this->translator->translate('.watchedProducts', 'Hlídané produkty'));
	}
	
	public function renderChangePassword(): void
	{
		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		$breadcrumb->addItem($this->translator->translate('.myAccount', 'Můj účet'));
		$breadcrumb->addItem($this->translator->translate('.changeEmailAndPwd', 'Nastavení a změna hesla'));
	}

	public function createComponentSettingsForm(): Nette\Application\UI\Form
	{
		$presenter = $this;
		$customer = $this->shopper->getCustomer();

		$form = new Nette\Application\UI\Form();
		$form->addCheckbox('newsletter');
		$form->addSubmit('submit');

		$form->onSuccess[] = function (Nette\Forms\Form $form) use ($customer, $presenter): void {
			$customer->update($form->getValues());
			$presenter->flashMessage($this->translator->translate('.msgSaved', 'Uloženo'));
			$presenter->redirect('this');
		};

		$form->setDefaults($customer->toArray());

		return $form;
	}

	public function createComponentChangePasswordForm(): \Forms\Bridges\FormsSecurity\ChangePasswordForm
	{
		$presenter = $this;

		$form = $this->changePasswordFormFactory->create();
		$form->onSuccess[] = function (Nette\Forms\Form $form) use ($presenter): void {
			$presenter->flashMessage($this->translator->translate('Profile.pwdChanged', 'Heslo bylo změněno.'), 'success');
			$presenter->redirect('this');
		};
		$form->onError[] = function (Nette\Forms\Form $form) use ($presenter): void {
			$presenter->flashMessage($this->translator->translate('.msgSaved', 'Uloženo'));
			$presenter->redirect('this');
		};

		return $form;
	}

	public function createComponentProfileForm(): ProfileForm
	{
		$form = $this->profileFormFactory->create();

		$form->onSuccess[] = function (ProfileForm $form): void {
			$form->getPresenter()->flashMessage($this->translator->translate('Profile.changesSaved', 'Změny uloženy.'));
			$form->getPresenter()->redirect('this');
		};

		return $form;
	}

	public function createComponentWatcherList(): WatcherList
	{
		$watcherList = $this->watcherListFactory->create();
		$watcherList->onAnchor[] = function (WatcherList $watcherList): void {
			$watcherList->template->setFile(\dirname(__DIR__, 6) . '/app/Eshop/Controls/watcherList.latte');
			$watcherList->template->products = $this->productRepository->getProducts()->join(['watcher' => 'eshop_watcher'], 'this.uuid = watcher.fk_product');
		};
		
		return $watcherList;
	}

	public function createComponentCustomers(): CustomerList
	{
		$user = $this->shopper->getMerchant() ?? $this->shopper->getCustomer();

		$customers = $user instanceof Merchant ? $this->customerRepository->many()
				->join(['nxn' => 'eshop_merchant_nxn_eshop_customer'], 'this.uuid = nxn.fk_customer')
				->where('nxn.fk_merchant', $user) : $this->customerRepository->many()->where('fk_parentCustomer', $user->getPK());

		$customerList = $this->customerListFactory->create($customers);
		$customerList->onAnchor[] = function (CustomerList $customerList): void {
			$customerList->template->setFile(\dirname(__DIR__, 6) . '/app/Eshop/Controls/customerList.latte');
		};

		return $customerList;
	}

	public function handleConfirmUserEmail(string $token): void
	{
		/** @var \Eshop\DB\Customer|null $customer */
		$customer = $this->customerRepository->one(['confirmationToken' => $token]);

		if (!$customer) {
			return;
		}

		$customer->update(['confirmationToken' => '']);
		$customer->accounts->update(['authorized' => true]);
		
		$this->flashMessage($this->translator->translate('profileForm.emailConfirmed', 'Email byl potvrzen.'));
		$this->redirect(':Eshop:User:login');
	}

	public function renderAccounts(?string $selectedCustomer = null): void
	{
		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		$breadcrumb->addItem($this->translator->translate('.myAccount', 'Můj účet'));
		$breadcrumb->addItem($this->translator->translate('.myCustomers', 'Moje společnosti'), $this->link(':Eshop:Profile:customers'));
		$breadcrumb->addItem($this->translator->translate('.customerAccounts', 'Servisní technici'));

		if (!$selectedCustomer = $this->getPresenter()->getParameter('selectedCustomer')) {
			return;
		}

		$this->template->selectedCustomer = $this->customerRepository->one($selectedCustomer);
	}

	public function createComponentAccounts(): AccountList
	{
		$accountList = $this->accountListFactory->create();
		$accountList->onAnchor[] = function (AccountList $accountList): void {
			$accountList->template->setFile(\dirname(__DIR__, 6) . '/app/Eshop/Controls/accountList.latte');
		};

		return $accountList;
	}

	public function actionAccounts(?string $selectedCustomer = null): void
	{
		/** @var \Eshop\Controls\AccountList $accountsList */
		$accountsList = $this->getComponent('accounts');

		if ($selectedCustomer) {
			$accountsList->setFilters(['customer' => $selectedCustomer]);
		} else {
			$accountsList->setFilters(['noCustomer' => true]);
		}
	}
}
