<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Admin\Controls\AccountFormFactory;
use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\AddressRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\MerchantRepository;
use Forms\Container;
use Forms\Form;
use Grid\Datagrid;
use League\Csv\Writer;
use Messages\DB\TemplateRepository;
use Nette\Application\Responses\FileResponse;
use Nette\Forms\Controls\Button;
use Nette\Mail\Mailer;
use Nette\Utils\Arrays;
use Security\DB\Account;
use Security\DB\AccountRepository;
use StORM\ICollection;

class CustomerPresenter extends BackendPresenter
{
	/** @inject */
	public AccountFormFactory $accountFormFactory;
	
	/** @inject */
	public CustomerRepository $customerRepository;
	
	/** @inject */
	public AccountRepository $accountRepository;
	
	/** @inject */
	public MerchantRepository $merchantRepository;
	
	/** @inject */
	public TemplateRepository $templateRepository;
	
	/** @inject */
	public ProductRepository $productRepo;
	
	/** @inject */
	public PaymentTypeRepository $paymentTypeRepo;
	
	/** @inject */
	public DeliveryTypeRepository $deliveryTypeRepo;
	
	/** @inject */
	public CurrencyRepository $currencyRepo;
	
	/** @inject */
	public CustomerGroupRepository $groupsRepo;
	
	/** @inject */
	public AddressRepository $addressRepo;
	
	/** @inject */
	public Mailer $mailer;
	
	/** @inject */
	public PricelistRepository $pricelistRepo;
	
	/** @inject */
	public CatalogPermissionRepository $catalogPermissionRepo;
	
	public const TABS = [
		'customers' => 'Zákazníci',
		'accounts' => 'Účty',
	];
	
	/** @persistent */
	public string $tab = 'customers';
	
	public function createComponentCustomers()
	{
		$grid = $this->gridFactory->create($this->customerRepository->many(), 20, 'createdTs', 'DESC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Registrace', "createdTs|date", '%s', 'createdTs', ['class' => 'fit']);
		$grid->addColumn('Název / Jméno', function (Customer $customer) {
			return $customer->company ?: $customer->fullname;
		});
		$grid->addColumnText('Obchodník', 'merchant.fullname', '%s', 'merchant.fullname');
		$grid->addColumnText('Skupina', 'group.name', '%s', 'group.name');
		$grid->addColumnText('Telefon', 'phone', '<a href="tel:%1$s"><i class="fa fa-phone-alt"></i> %1$s</a>')->onRenderCell[] = [$grid, 'decoratorEmpty'];
		$grid->addColumnText('Email', 'email', '<a href="mailto:%1$s"><i class="far fa-envelope"></i> %1$s</a>')->onRenderCell[] = [$grid, 'decoratorEmpty'];
		
		$btnSecondary = 'btn btn-sm btn-outline-primary';
		$grid->addColumn('Feed', function (Customer $customer) use ($btnSecondary) {
			return "<a class='$btnSecondary' target='_blank' href='" . $this->link('//:Eshop:Export:customer', $customer->getPK()) . "'><i class='fa fa-sm fa-rss'></i></a>";
		}, '%s', null, ['class' => 'minimal']);
		
		$grid->addColumn('', function (Customer $object, Datagrid $datagrid) use ($btnSecondary) {
			return \count($object->accounts) > 0 ?
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('this', ['tab' => 'accounts', 'accountGrid-company' => $object->company ?: $object->fullname]) . "'>Účty</a>" :
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('newAccount', $object) . "'>Vytvořit&nbsp;účet</a>";
		}, '%s', null, ['class' => 'minimal']);
		
		$grid->addColumnLink('editAddress', 'Adresy');
		$grid->addColumnLinkDetail('edit');
		
		$grid->addColumnActionDelete([$this->accountFormFactory, 'deleteAccountHolder']);
		
		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected([$this->accountFormFactory, 'deleteAccountHolder']);
		
		$grid->addButtonBulkEdit('form', ['pricelists', 'merchant', 'group', 'newsletter'], 'customers');
		
		$submit = $grid->getForm()->addSubmit('downloadEmails', 'Export e-mailů');
		$submit->setHtmlAttribute('class', 'btn btn-sm btn-outline-primary');
		$submit->onClick[] = [$this, 'exportCustomers'];
		
		$grid->addFilterTextInput('search', ['this.fullname', 'this.email', 'this.phone'], null, 'Jméno a příjmení, email, telefon');
		
		if (\count($this->merchantRepository->getListForSelect()) > 0) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$source->where('fk_merchant', $value);
			}, '', 'merchant', 'Obchodník', $this->merchantRepository->getListForSelect(), ['placeholder' => '- Obchodník -']);
		}
		
		if (\count($this->groupsRepo->getListForSelect()) > 0) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$source->where('fk_group', $value);
			}, '', 'group', 'Skupina', $this->groupsRepo->getListForSelect(), ['placeholder' => '- Skupina -']);
		}
		
		$grid->addFilterCheckboxInput('newsletter', "newsletter = 1", 'Newsletter');
		
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function exportCustomers(Button $button)
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);
		
		$tempFilename = \tempnam($this->tempDir, "csv");
		$collection = $grid->getSource()->where($grid->getSourceIdName(), $grid->getSelectedIds());
		$this->customerRepository->csvExport($collection, Writer::createFromPath($tempFilename, 'w+'));
		
		$response = new FileResponse($tempFilename, "zakaznici.csv", 'text/csv');
		$this->sendResponse($response);
	}
	
	public function handleLoginCustomer($login)
	{
		$this->user->login($login, '', [Customer::class], true);
		
		$this->presenter->redirect(':Web:Index:default');
	}
	
	public function actionEditAccount(Account $account): void
	{
		/** @var Form $form */
		$form = $this->getComponent('accountForm');
		$form['account']->setDefaults($account->toArray());
		
		$permission = $this->catalogPermissionRepo->many()->where('fk_account', $account->getPK())->first();
		
		if ($permission) {
			$form['permission']->setDefaults($permission->toArray());
			
			$this->accountFormFactory->onUpdateAccount[] = function (Account $account, array $values) use ($permission, $form) {
				$permission->update($values['permission']);
				$this->flashMessage('Uloženo', 'success');
				$form->processRedirect('editAccount', 'default', [$account]);
			};
		}
	}
	
	public function actionNewAccount(?Customer $customer = null)
	{
		$form = $this->getComponent('accountForm');
		$form['account']['password']->setRequired();
		
		if ($customer) {
			$form['permission']['customer']->setDefaultValue($customer);
		}
		
		$this->accountFormFactory->onCreateAccount[] = function (Account $account, array $values) use ($form) {
			$this->catalogPermissionRepo->createOne($values['permission'] + ['account' => $account]);
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('editAccount', 'default', [$account]);
		};
	}
	
	public function renderNewAccount(?Customer $customer = null): void
	{
		$this->template->headerLabel = 'Nový účet zákazníka';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Nový účet zákazníka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('accountForm')];
	}
	
	public function createComponentForm()
	{
		$form = $this->formFactory->create();
		
		$form->addText('fullname', 'Jméno a příjmení');
		$form->addText('company', 'Firma');
		$form->addText('ic', 'IČ');
		$form->addText('dic', 'DIČ');
		$form->addText('phone', 'Telefon');
		
		$form->addText('email', 'E-mail')->addRule($form::EMAIL)->setRequired();
		$form->addText('ccEmails', 'Kopie emailů')->setHtmlAttribute('data-info', 'Zadejte emailové adresy oddělené středníkem (;).');
		$form->addCheckbox('newsletter', 'Přihlášen k newsletteru');
		
		$form->addDataMultiSelect('pricelists', 'Ceníky', $this->pricelistRepo->many()->toArrayOf('name'))
			->setHtmlAttribute('placeholder', 'Vyberte položky...');
		
		$customersForSelect = $this->customerRepository->getArrayForSelect();
		
		if ($customer = $this->getParameter('customer')) {
			unset($customersForSelect[$customer->getPK()]);
		}
		
		$form->addDataSelect('parentCustomer', 'Nadřazený zákazník', $customersForSelect)->setPrompt('Žádná');
		$form->addDataSelect('merchant', 'Obchodník', $this->merchantRepository->getArrayForSelect())->setPrompt('Žádná');
		$form->addDataSelect('group', 'Skupina', $this->groupsRepo->getRegisteredGroupsArray())->setPrompt('Žádná');
		
		$form->addGroup('Nákup a preference');
		$form->addSelect('orderPermission', 'Objednání', [
			'fullWithApproval' => 'Pouze se schválením',
			'full' => 'Povoleno',
		])->setDefaultValue('full');
		$form->addDataSelect('preferredCurrency', 'Preferovaná měna nákupu', $this->currencyRepo->getArrayForSelect())->setPrompt('Žádný');
		$form->addDataSelect('preferredPaymentType', 'Preferovaná platba', $this->paymentTypeRepo->many()->toArrayOf('code'))->setPrompt('Žádná');
		$form->addDataSelect('preferredDeliveryType', 'Preferovaná doprava', $this->deliveryTypeRepo->many()->toArrayOf('code'))->setPrompt('Žádná');
		$form->addDataMultiSelect('exclusivePaymentTypes', 'Povolené exkluzivní platby', $this->paymentTypeRepo->many()->toArrayOf('code'))
			->setHtmlAttribute('placeholder', 'Vyberte položky...');
		$form->addDataMultiSelect('exclusiveDeliveryTypes', 'Povolené exkluzivní dopravy', $this->deliveryTypeRepo->many()->toArrayOf('code'))
			->setHtmlAttribute('placeholder', 'Vyberte položky...');
		$form->addInteger('discountLevelPct', 'Slevová hladina (%)')->setDefaultValue(0)->setRequired();
		$form->addText('productRoundingPct', 'Zokrouhlení od procent (%)')->setNullable()->setHtmlType('number')->addCondition($form::FILLED)->addRule(Form::INTEGER);
		$form->addGroup('Exporty');
		$form->addCheckbox('allowExport', 'Feed povolen');
		$form->addText('ediCompany', 'EDI: Identifikátor firmy')
			->setHtmlAttribute('Bude použito při exportu objednávky do formátu EDI.');
		$form->addText('ediBranch', 'EDI: Identifikátor pobočky')
			->setHtmlAttribute('Bude použito při exportu objednávky do formátu EDI.');
		
		//$form->bind($this->customerRepository->getStructure(), []);
		
		$form->addSubmits(!$this->getParameter('customer'));
		
		return $form;
	}
	
	public function createComponentEditAddress()
	{
		$form = $this->formFactory->create();
		
		$form->addGroup('Fakturační adresa');
		$billAddress = $form->addContainer('billAddress');
		$billAddress->addText('street', 'Ulice');
		$billAddress->addText('city', 'Město');
		$billAddress->addText('zipcode', 'PSČ');
		$billAddress->addText('state', 'Stát');
		
		$form->addGroup('Doručovací adresa');
		$deliveryAddress = $form->addContainer('deliveryAddress');
		$deliveryAddress->addText('name', ' Jméno a příjmení / název firmy');
		$deliveryAddress->addText('street', 'Ulice');
		$deliveryAddress->addText('city', 'Město');
		$deliveryAddress->addText('zipcode', 'PSČ');
		$deliveryAddress->addText('state', 'Stát');
		
		$form->bind(null, ['deliveryAddress' => $this->addressRepo->getStructure(), 'billAddress' => $this->addressRepo->getStructure()]);
		
		$form->addSubmits();
		
		return $form;
	}
	
	
	public function renderDefault(?Customer $customer = null): void
	{
		if ($this->tab == 'customers') {
			$this->template->headerLabel = 'Zákazníci';
			$this->template->headerTree = [
				['Zákazníci', 'default'],
			];
			$this->template->displayButtons = [$this->createNewItemButton('new')];
			$this->template->displayControls = [$this->getComponent('customers')];
		} elseif ($this->tab == 'accounts') {
			$this->template->headerLabel = 'Účty';
			$this->template->headerTree = [
				['Zákazníci', 'default'],
				['Účty']
			];
			$this->template->displayButtons = [$this->createNewItemButton('newAccount')];
			$this->template->displayControls = [$this->getComponent('accountGrid')];
		}
		
		$this->template->tabs = self::TABS;
	}
	
	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nový zákazník';
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Nový zákazník'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function renderEdit(): void
	{
		$this->template->headerLabel = 'Detail zákazníka - ' . $this->getParameter('customer')->fullname;
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Detail zákazníka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function renderEditAddress(): void
	{
		$this->template->headerLabel = 'Detail adresy zákazníka - ' . $this->getParameter('customer')->fullname;
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Detail adresy'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('editAddress')];
	}
	
	public function renderEditAccount(Account $account): void
	{
		$this->template->headerLabel = 'Detail účtu - ' . $account->login;
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Účty', 'default'],
			['Detail účtu'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('accountForm')];
	}
	
	public function actionNew()
	{
		/** @var Form $form */
		$form = $this->getComponent('form');
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			//$accounts = $values['accounts'];
			//unset($values['accounts']);
			
			$customer = $this->customerRepository->syncOne($values, null, true);
			
			/*
			foreach ($accounts as $account) {
				
				$permission = $this->catalogPermissionRepo->many()
					->where('fk_account', $account)
					->first();
				
				
				$realPermission = $this->catalogPermissionRepo->many()
					->where('fk_account', $account)
					->where('fk_customer', $customer->getPK())
					->first();
				
				$newValues = [
					'customer' => $customer->getPK(),
					'account' => $account,
				];
				
				if ($permission) {
					$newValues += [
						'catalogPermission' => $permission->catalogPermission,
						'buyAllowed' => $permission->buyAllowed,
						'orderAllowed' => $permission->orderAllowed,
					];
				}
				
				if ($realPermission) {
					$realPermission->update($newValues);
				} else {
					$this->catalogPermissionRepo->createOne($newValues);
				}
			}*/
			
			$this->flashMessage('Vytvořeno', 'success');
			$form->processRedirect('edit', 'default', [$customer]);
		};
	}
	
	public function actionEdit(Customer $customer)
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');
		
		$form->setDefaults($customer->toArray(['pricelists', 'exclusivePaymentTypes', 'exclusiveDeliveryTypes', 'accounts']));
		
		$form->onSuccess[] = function (AdminForm $form) use ($customer) {
			$values = $form->getValues('array');
			
			/*
			foreach ($this->catalogPermissionRepo->many()->where('fk_customer', $customer->getPK()) as $permission) {
				if (Arrays::contains($values['accounts'], $permission->getValue('account'))) {
					unset($values['accounts'][$permission->getValue('account')]);
				} else {
					$permission->delete();
				}
			}
			
			foreach ($values['accounts'] as $account) {
				$permission = $this->catalogPermissionRepo->many()
					->where('fk_account', $account)
					->first();
				
				$realPermission = $this->catalogPermissionRepo->many()
					->where('fk_account', $account)
					->where('fk_customer', $customer->getPK())
					->first();
				
				$newValues = [
					'customer' => $customer->getPK(),
					'account' => $account,
				];
				
				if ($permission) {
					$newValues += [
						'catalogPermission' => $permission->catalogPermission,
						'buyAllowed' => $permission->buyAllowed,
						'orderAllowed' => $permission->orderAllowed,
					];
				}
				
				if ($realPermission) {
					$realPermission->update($newValues);
				} else {
					$this->catalogPermissionRepo->createOne($newValues);
				}
			}
			*/
			unset($values['accounts']);
			
			/** @var \Eshop\DB\Customer $customer */
			$customer = $this->customerRepository->syncOne($values, null, true);
			
			$form->getPresenter()->flashMessage('Uloženo', 'success');
			$form->processRedirect('edit', 'default', [$customer]);
		};
		
		$this->renderEdit();
	}
	
	public function actionEditAddress(Customer $customer): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('editAddress');
		
		$form->setDefaults($customer->jsonSerialize());
		
		$form->onSuccess[] = function (AdminForm $form) use ($customer) {
			$values = $form->getValues('array');
			
			$bill = $this->addressRepo->syncOne($values['billAddress']);
			$delivery = $this->addressRepo->syncOne($values['deliveryAddress']);
			
			$customer->update([
				'billAddress' => $bill,
				'deliveryAddress' => $delivery,
			]);
			
			$form->getPresenter()->flashMessage('Uloženo', 'success');
			$form->processRedirect('this', 'default');
		};
		
		$this->renderEditAddress();
	}
	
	public function createComponentAccountForm(): AdminForm
	{
		$callback = function (Form $form) {
			
			$form->addGroup('Oprávnění a zákazník');
			$container = $form->addContainer('permission');
			$container->addDataSelect('customer', 'Zákazník', $this->customerRepository->getArrayForSelect())->setPrompt('-Zvolte-')->setRequired();
			$container->addSelect('catalogPermission', 'Zobrazení', [
				'none' => 'Žádné',
				'catalog' => 'Katalogy',
				'price' => 'Ceny',
			])->setDefaultValue('price');
			$container->addCheckbox('buyAllowed', 'Povolit nákup')->setDefaultValue(true);
		};
		
		$form = $this->accountFormFactory->create(false, $callback);
		
		return $form;
	}
	
	public function createComponentAccountGrid()
	{
		$collection = $this->accountRepository->many()
			->join(['catalogPermission' => 'eshop_catalogpermission'], 'catalogPermission.fk_account = this.uuid')
			->join(['customer' => 'eshop_customer'], 'customer.uuid = catalogPermission.fk_customer')
			->where('customer.uuid IS NOT NULL')
			->select(['company' => 'customer.company', 'fullname' => 'customer.fullname'])
			->select(['permission' => 'catalogPermission.catalogPermission', 'buyAllowed' => 'catalogPermission.buyAllowed']);
		
		$grid = $this->gridFactory->create($collection, 20, 'createdTs', 'DESC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Vytvořen', 'tsRegistered|date', '%s', 'tsRegistered', ['class' => 'fit']);
		$grid->addColumnText('Login', 'login', '%s', 'login', ['class' => 'fit']);
		$grid->addColumn('Zákazník', function (Account $account) {
			return $account->company ?: $account->fullname;
		});
		$grid->addColumn('Oprávnění', function (Account $account) {
			$label = ['none' => 'Žádné', 'catalog' => 'Katalogy', 'price' => 'Ceny',];
			
			return '' . $label[$account->permission] . ' + ' . ($account->buyAllowed ? 'nákup' : 'bez nákupu');
		});
		
		$grid->addColumnText('Aktivní od', 'activeFrom', '%s', 'activeFrom', ['class' => 'fit']);
		$grid->addColumnText('Aktivní do', 'activeTo', '%s', 'activeTo', ['class' => 'fit']);
		$grid->addColumnInputCheckbox('Aktivní', 'active');
		$grid->addColumnInputCheckbox('Autorizovaný', 'authorized');
		$btnSecondary = 'btn btn-sm btn-outline-primary';
		$grid->addColumn('Login', function (Account $object, Datagrid $grid) use ($btnSecondary) {
			$link = $grid->getPresenter()->link('loginCustomer!', [$object->login]);
			
			return "<a class='$btnSecondary' target='_blank' href='$link'><i class='fa fa-sign-in-alt'></i></a>";
		}, '%s', null, ['class' => 'minimal']);
		$grid->addColumnLinkDetail('editAccount');
		
		$grid->addColumnActionDelete();
		
		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();
		
		$grid->addFilterTextInput('search', ['this.login'], null, 'Login');
		$grid->addFilterTextInput('company', ['customer.company', 'customer.fullname', 'customer.ic'], null, 'Zákazník, IČ');
		
		
		
		$grid->addFilterButtons();
		
		return $grid;
	}
}