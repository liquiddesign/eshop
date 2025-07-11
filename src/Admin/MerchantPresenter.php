<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Admin\Controls\AccountFormFactory;
use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Merchant;
use Eshop\DB\MerchantRepository;
use Eshop\DB\Pricelist;
use Eshop\DB\PricelistRepository;
use Eshop\DB\VisibilityListRepository;
use Eshop\Services\ProductsCache\GeneralProductsCacheProvider;
use Forms\Form;
use Grid\Datagrid;
use Messages\DB\TemplateRepository;
use Nette\Application\UI\Presenter;
use Nette\DI\Attributes\Inject;
use Nette\Mail\Mailer;
use Nette\Security\Passwords;
use Security\DB\Account;
use Security\DB\AccountRepository;

class MerchantPresenter extends BackendPresenter
{
	protected const CONFIGURATIONS = [
		'customers' => true,
		'showUnregisteredGroup' => true,
	];

	#[Inject]
	public AccountFormFactory $accountFormFactory;

	#[Inject]
	public MerchantRepository $merchantRepository;

	#[Inject]
	public AccountRepository $accountRepository;

	#[Inject]
	public TemplateRepository $templateRepository;

	#[Inject]
	public CustomerGroupRepository $customerGroupRepository;

	#[Inject]
	public CustomerRepository $customerRepository;

	#[Inject]
	public PricelistRepository $pricelistRepository;

	#[Inject]
	public Mailer $mailer;
	
	#[Inject]
	public Passwords $passwords;

	#[Inject]
	public VisibilityListRepository $visibilityListRepository;

	#[Inject]
	public GeneralProductsCacheProvider $productsCacheGetterService;

	/**
	 * @var null|callable(array<mixed> $values, \Admin\Controls\AdminForm $form): bool
	 */
	protected mixed $onMerchantFormUniqueValidation = null;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->merchantRepository->many()
			->setGroupBy(['this.uuid'])
			->select([
				'pricelists_names' => "GROUP_CONCAT(DISTINCT pricelists.name SEPARATOR ', ')",
				'visibilityLists_names' => "GROUP_CONCAT(DISTINCT visibilityLists.name SEPARATOR ', ')",
		]), 20, 'this.code', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'this.code', ['class' => 'fit']);
		$grid->addColumnText('Jméno a příjmení', 'fullname', '%s', 'this.fullname');
		$grid->addColumnText('Ceníky / Viditelníky', ['pricelists_names', 'visibilityLists_names'], '%s<hr style="margin: 0">%s');
		$grid->addColumnText(
			'E-mail',
			'email',
			'<a href="mailto:%1$s"><i class="far fa-envelope"></i> %1$s</a>',
		)->onRenderCell[] = [
			$grid,
			'decoratorEmpty',
		];
		$grid->addColumn('Skupiny', function (Merchant $object, Datagrid $datagrid) {
			$htmlToReturn = '';

			foreach ($object->customerGroups->clear()->toArray() as $customerGroup) {
				$htmlToReturn .= '<span>' . $customerGroup->name . '</span><br>';
			}

			return $htmlToReturn;
		});

		$this->addCustomFieldsToMerchantGrid($grid);

		$btnSecondary = 'btn btn-sm btn-outline-primary';
		$grid->addColumn('', function (Merchant $object, Datagrid $datagrid) use ($btnSecondary) {
			return $object->accounts->clear()->first() !== null ?
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link(
					'editAccount',
					$object,
				) . "'>Detail&nbsp;účtu</a>" :
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link(
					'newAccount',
					$object,
				) . "'>Vytvořit&nbsp;účet</a>";
		}, '%s', null, ['class' => 'minimal']);

		$grid->addColumn('Login', function (Merchant $object, Datagrid $grid) use ($btnSecondary) {
			/** @var \Security\DB\Account $account */
			$account = $object->accounts->clear()->first();

			$link = $object->accounts->clear()->first() ? $grid->getPresenter()->link(
				'loginMerchant!',
				[$account->login],
			) : '#';

			return "<a class='" . ($object->accounts->clear()->first() ? '' : 'disabled') . " $btnSecondary' target='_blank' href='$link'><i class='fa fa-sign-in-alt'></i></a>";
		}, '%s', null, ['class' => 'minimal']);

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete([$this->accountFormFactory, 'deleteAccountHolder']);

		$grid->addButtonBulkEdit('form', ['visibilityLists', 'pricelists', 'customerGroups'], copyRawValues: ['pricelists' => 'pricelists']);
		$grid->addButtonDeleteSelected([$this->accountFormFactory, 'deleteAccountHolder']);

		$grid->addFilterTextInput('search', ['this.code', 'this.fullName', 'this.email'], null, 'Jméno, kód, e-mail');

//		if ($items = $this->customerRepository->getArrayForSelect()) {
//			$grid->addFilterDataSelect(function (Collection $source, $value): void {
//				$source->where('customers.uuid', $value);
//			}, '', 'customers', null, $items)->setPrompt('- Zákazník -');
//		}

		$this->addCustomFiltersToMerchantGrid($grid);

		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentForm(): Form
	{
		/** @var \Eshop\DB\Merchant|null $merchant */
		$merchant = $this->getParameter('merchant');

		$form = $this->formFactory->create(false, false, false, false, false);

		$form->monitor(Presenter::class, function () use ($form, $merchant): void {
			$form->addGroup('Obchody');
			$this->formFactory->addShopsContainerToAdminForm($form);

			$form->addGroup('Obchodník');
			$form->addText('code', 'Kód')->setNullable();
			$form->addText('fullname', 'Jméno a příjmení')->setRequired();
			$form->addEmail('email', 'E-mail')->setRequired();
			$form->addText('phone', 'Telefon')->setNullable();

			$form->addGroup('Další možnosti');

			$form->addMultiSelect2(
				'customerGroups',
				'Skupina zákazníků',
				$this->customerGroupRepository->getArrayForSelect(true, $this::CONFIGURATIONS['showUnregisteredGroup']),
			)->setDefaultValue($merchant?->customerGroups->clear()->toArrayOf('uuid'));

			$pricelistsInput = $form->addMultiSelectAjax('pricelists', 'Ceníky', 'Zvolte ceníky', Pricelist::class);

			if ($merchant) {
				$this->template->select2AjaxDefaults[$pricelistsInput->getHtmlId()] = $merchant->getPricelists()->toArrayOf('name');
			}

			$form->addMultiSelect2('visibilityLists', 'Seznamy viditelnosti', $this->visibilityListRepository->getArrayForSelect());

			if ($this::CONFIGURATIONS['customers']) {
				$customersInput = $form->addMultiSelectAjax('customers', 'Zákazníci', 'Zvolte zákazníky', Customer::class);

				if ($merchant) {
					$this->template->select2AjaxDefaults[$customersInput->getHtmlId()] = $merchant->customers->toArrayOf('fullname');
				}
			}

			$form->addSelect('catalogPermission', 'Zdroj oprávnění', ['customer' => 'Zákazník', 'merchant' => 'Obchodník'])
				->setDefaultValue('customer')
				->setHtmlAttribute('data-info', 'Pokud se obchodník přihlásí na zákazníka, tak určuje, jestli použít oprávnění zákazníka nebo obchodníka.');

			$form->addSelect('priceListsMode', 'Zdroj ceníků', [
				'customer' => 'Zákazník',
				'merchant' => 'Obchodník (nedoporučeno)',
				'merge' => 'Kombinovat',
			])
				->setDefaultValue('customer')
				->setHtmlAttribute('data-info', 'Pokud se obchodník přihlásí na zákazníka, tak určuje, jestli použít ceníky zákazníka, obchodníka nebo spojit ceníky obou.');
			$form->addCheckbox('customersPermission', 'Oprávnění: Správa zákazníků');
			$form->addCheckbox('ordersPermission', 'Oprávnění: Správa objednávek');
			$form->addCheckbox('viewPurchasePricePermission', 'Oprávnění: Zobrazení nákupních cen');
			$form->addCheckbox('approveOfferPermission', 'Oprávnění: Schvalování nabídek');
			$form->addCheckbox(
				'customerEmailNotification',
				'Posílat e-mailem informace o objednávkách přiřazených zákazníků.',
			);

			$form->addGroup('Cache');
			$form->addText('cacheIndex', 'Index')
				->setDisabled()
				->setDefaultValue($merchant ? $this->productsCacheGetterService->getIndexByCustomer($merchant) : null);

			$form->addGroup('Externí');
			$form->addText('externalId', 'Externí ID')->setNullable();
			$form->addText('externalCode', 'Externí kód')->setNullable();

			$this->addCustomFieldsToMerchantForm($form);

			$form->addSubmits(!$merchant);
		});

		$form->onValidate[] = function (AdminForm $form) use ($merchant): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValuesWithAjax();

			$uniqueValid = true;

			if ($this->onMerchantFormUniqueValidation) {
				$uniqueValid = \call_user_func($this->onMerchantFormUniqueValidation, $values, $form);
			} else {
				$query = $this->merchantRepository->many()->where('this.email', $values['email']);

				if (isset($values['shop'])) {
					$query->where('this.fk_shop', $values['shop']);
				} else {
					$query->where('this.fk_shop IS NULL');
				}

				$duplicate = $query->first();

				if ($duplicate) {
					if (!$merchant || ($merchant->getPK() !== $duplicate->getPK())) {
						$uniqueValid = false;
					}
				}
			}

			if (!$uniqueValid) {
				/** @var \Nette\Forms\Controls\TextInput $emailInput */
				$emailInput = $form['email'];

				$emailInput->addError('Neplatná kombinace unikátních hodnot. Zkontrolujte e-mail, obchod a specifické hodnoty.');
			}

			return;
		};
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValuesWithAjax();

			/** @var \Eshop\DB\Merchant $merchant */
			$merchant = $this->merchantRepository->syncOne($values, null, true, ignore: false);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$merchant]);
		};

		return $form;
	}

	public function handleLoginMerchant(string $login): void
	{
		if (!$identity = $this->merchantRepository->getByAccountLogin($login)) {
			throw new \Exception('Merchant not found');
		}

		$this->user->login($identity, null, [Merchant::class]);

		$this->presenter->redirect(':Web:Index:default');
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Obchodníci';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function renderDetail(Merchant $merchant): void
	{
		unset($merchant);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function actionDetail(Merchant $merchant): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');

		$relations = ['pricelists'];

		if ($this::CONFIGURATIONS['customers']) {
			$relations[] = 'customers';
			$relations[] = 'visibilityLists';
		}

		$form->setDefaults($merchant->toArray($relations));
	}

	public function createComponentAccountForm(): AdminForm
	{
		$merchant = $this->getParameter('merchant');

		return $this->accountFormFactory->create((bool) $merchant->accounts->first());
	}

	public function actionEditAccount(Merchant $merchant): void
	{
		/** @var \Admin\Controls\AdminForm|array<mixed> $form */
		$form = $this->getComponent('accountForm');
		$form['account']['email']->setDefaultValue($merchant->email);

		if ($account = $merchant->accounts->clear()->first()) {
			/** @var \Forms\Container $accountForm */
			$accountForm = $form['account'];
			$accountForm->setDefaults($account->toArray());
		}

		$this->accountFormFactory->onUpdateAccount[] = function () use ($merchant): void {
			$this->flashMessage('Účet byl upraven', 'success');
			$this->redirect('editAccount', $merchant);
		};

		$this->accountFormFactory->onDeleteAccount[] = function (): void {
			$this->flashMessage('Účet byl smazán', 'success');
			$this->redirect('default');
		};
	}

	public function renderEditAccount(Merchant $merchant): void
	{
		$this->template->headerLabel = 'Detail účtu - ' . $merchant->fullname;
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Detail účtu obchodníka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('accountForm')];
	}

	public function actionNewAccount(Merchant $merchant): void
	{
		/** @var \Admin\Controls\AdminForm|array<mixed> $form */
		$form = $this->getComponent('accountForm');
		$form['account']['password']->setRequired();
		$form['account']['passwordCheck']->setRequired();
		unset($form['delete']);

		$this->accountFormFactory->onCreateAccount[] = function (Account $account) use ($merchant): void {
			$merchant->accounts->relate([$account->getPK()]);

			$this->flashMessage('Účet vytvořen', 'success');
			$this->redirect('editAccount', $merchant);
		};
	}

	public function renderNewAccount(Merchant $merchant): void
	{
		unset($merchant);

		$this->template->headerLabel = 'Nový účet';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Nový účet obchodníka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('accountForm')];
	}

	protected function addCustomFieldsToMerchantGrid(AdminGrid $grid): void
	{
		unset($grid);
	}

	protected function addCustomFiltersToMerchantGrid(AdminGrid $grid): void
	{
		unset($grid);
	}

	protected function addCustomFieldsToMerchantForm(AdminForm $form): void
	{
		unset($form);
	}
}
