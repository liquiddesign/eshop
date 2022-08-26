<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Admin\Controls\AccountFormFactory;
use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\AddressRepository;
use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\LoyaltyProgramRepository;
use Eshop\DB\MerchantRepository;
use Eshop\DB\NewsletterUserGroupRepository;
use Eshop\DB\NewsletterUserRepository;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\ProductRepository;
use Eshop\Shopper;
use Forms\Form;
use Grid\Datagrid;
use League\Csv\Writer;
use Messages\DB\TemplateRepository;
use Nette\Application\Responses\FileResponse;
use Nette\Forms\Controls\Button;
use Nette\Mail\Mailer;
use Nette\Utils\Arrays;
use Nette\Utils\DateTime;
use Nette\Utils\Validators;
use Security\DB\Account;
use Security\DB\AccountRepository;
use StORM\Connection;
use StORM\ICollection;

class CustomerPresenter extends BackendPresenter
{
	public const TABS = [
		'customers' => 'Zákazníci',
		'accounts' => 'Účty',
	];
	
	protected const CONFIGURATIONS = [
		'labels' => [
			'merchants' => 'Obchodníci',
		],
		'branches' => true,
		'deliveryPayment' => true,
		'edi' => true,
		'showUnregisteredGroup' => true,
		'showAuthorized' => true,
		'sendEmailAccountActivated' => false,
		'prices' => true,
		'discountLevel' => true,
		'rounding' => true,
		'loyaltyProgram' => false,
		'targito' => false,
		'targitoOrigin' => null,
	];
	
	/** @persistent */
	public string $tab = 'customers';
	
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
	public OrderRepository $orderRepository;
	
	/** @inject */
	public AddressRepository $addressRepo;
	
	/** @inject */
	public Mailer $mailer;
	
	/** @inject */
	public PricelistRepository $pricelistRepo;
	
	/** @inject */
	public CatalogPermissionRepository $catalogPermissionRepo;
	
	/** @inject */
	public Connection $storm;
	
	/** @inject */
	public Shopper $shopper;
	
	/** @inject */
	public LoyaltyProgramRepository $loyaltyProgramRepository;
	
	/** @inject */
	public NewsletterUserRepository $newsletterUserRepository;
	
	/** @inject */
	public NewsletterUserGroupRepository $newsletterUserGroupRepository;
	
	public function createComponentCustomers(): AdminGrid
	{
		$lableMerchants = $this::CONFIGURATIONS['labels']['merchants'];
		
		$grid = $this->gridFactory->create($this->customerRepository->many()
			->select(['pricelists_names' => "GROUP_CONCAT(pricelists.name SEPARATOR ', ')"])
			->setGroupBy(['this.uuid']), 20, 'createdTs', 'DESC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Registrace', 'createdTs|date', '%s', 'createdTs', ['class' => 'fit']);
		$grid->addColumn('Název / Jméno', function (Customer $customer) {
			return $customer->company ?: $customer->fullname;
		});
		$td = '<a href="mailto:%1$s"><i class="far fa-envelope"></i> %1$s</a><br><a href="tel:%2$s"><i class="fa fa-phone-alt"></i> %2$s</a>';
		$grid->addColumnTextFit('E-mail / Telefon', ['email', 'phone'], $td)->onRenderCell[] = [$grid, 'decoratorEmpty'];
		
		
		$grid->addColumn($lableMerchants, function (Customer $customer) {
			return \implode(', ', $this->merchantRepository->many()
				->join(['merchantXcustomer' => 'eshop_merchant_nxn_eshop_customer'], 'this.uuid = merchantXcustomer.fk_merchant')
				->where('fk_customer', $customer)
				->toArrayOf('fullname'));
		});
		$grid->addColumnTextFit('Skupina', 'group.name', '%s', 'group.name');
		$grid->addColumnText('Ceníky', 'pricelists_names', '%s');
		$grid->addColumnTextFit('Sleva', 'discountLevelPct', '%s %%', 'discountLevelPct');
		$grid->addColumnTextFit('Max. sleva', 'maxDiscountProductPct', '%s %%', 'discountLevelPct');
		
		if (isset($this::CONFIGURATIONS['loyaltyProgram']) && $this::CONFIGURATIONS['loyaltyProgram']) {
			$grid->addColumn('Věrnostní program', function (Customer $object) {
				$link = $this->admin->isAllowed(':Eshop:Admin:LoyaltyProgram:programDetail') && $object->getValue('loyaltyProgram') ? $this->link(
					':Eshop:Admin:LoyaltyProgram:programDetail',
					[$object->loyaltyProgram, 'backLink' => $this->storeRequest()],
				) : '#';
				
				return $object->getValue('loyaltyProgram') ?
					"<a href='" . $link . "'>" . $object->loyaltyProgram->name . '</a><small><br>Bodů: ' . $object->getLoyaltyProgramPoints() .
					' | Sleva: ' . ($object->loyaltyProgramDiscountLevel ? $object->loyaltyProgramDiscountLevel->discountLevel : 0) . '%</small>' :
					'';
			}, '%s');
		}
		
		$btnSecondary = 'btn btn-sm btn-outline-primary';
		$grid->addColumn('Feed', function (Customer $customer) use ($btnSecondary) {
			return "<a class='$btnSecondary' target='_blank' href='" . $this->link('//:Eshop:Export:customer', $customer->getPK()) . "'><i class='fa fa-sm fa-rss'></i></a>";
		}, '%s', null, ['class' => 'minimal']);
		
		$grid->addColumn('', function (Customer $object, Datagrid $datagrid) use ($btnSecondary) {
			return \count($object->accounts) > 0 ?
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('this', ['tab' => 'accounts', 'accountGrid-company' => $object->email]) . "'>Účty</a>" :
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('newAccount', $object) . "'>Vytvořit&nbsp;účet</a>";
		}, '%s', null, ['class' => 'minimal']);
		
		$grid->addColumnLink('editAddress', 'Adresy');
		$grid->addColumnLinkDetail('edit');
		
		$grid->addColumnActionDelete([$this->accountFormFactory, 'deleteAccountHolder']);
		
		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected([$this->accountFormFactory, 'deleteAccountHolder'], false, null, 'this.uuid');
		
		$bulkEdits = ['pricelists', 'merchant', 'discountLevelPct', 'group'];
		
		if (isset($this::CONFIGURATIONS['loyaltyProgram']) && $this::CONFIGURATIONS['loyaltyProgram']) {
			$bulkEdits[] = 'loyaltyProgram';
		}
		
		$grid->addButtonBulkEdit('form', $bulkEdits, 'customers');
		
		$submit = $grid->getForm()->addSubmit('downloadEmails', 'Export e-mailů')
			->setHtmlAttribute('class', 'btn btn-sm btn-outline-primary');
		$submit->onClick[] = [$this, 'exportCustomers'];
		
		if (isset($this::CONFIGURATIONS['targito']) && $this::CONFIGURATIONS['targito']) {
			$submit = $grid->getForm()->addSubmit('downloadContactsTargito', 'Export Targito (CSV)')
				->setHtmlAttribute('class', 'btn btn-sm btn-outline-primary');
			$submit->onClick[] = [$this, 'exportTargito'];
		}
		
		$grid->addFilterTextInput('search', ['this.fullname', 'this.email', 'this.phone'], null, 'Jméno a příjmení, e-mail, telefon');
		
		if (\count($this->merchantRepository->getArrayForSelect()) > 0) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
				$source->join(['merchantXcustomer' => 'eshop_merchant_nxn_eshop_customer'], 'this.uuid = merchantXcustomer.fk_customer');
				$source->where('merchantXcustomer.fk_merchant', $value);
			}, '', 'merchant', $lableMerchants, $this->merchantRepository->getArrayForSelect(), ['placeholder' => "- $lableMerchants -"]);
		}
		
		if (\count($this->groupsRepo->getArrayForSelect(true, $this::CONFIGURATIONS['showUnregisteredGroup'])) > 0) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
				$source->where('fk_group', $value);
			}, '', 'group', 'Skupina', $this->groupsRepo->getArrayForSelect(true, $this::CONFIGURATIONS['showUnregisteredGroup']), ['placeholder' => '- Skupina -']);
		}
		
		if (\count($this->pricelistRepo->getArrayForSelect(true)) > 0) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
				$source->join(['pricelistNxN' => 'eshop_customer_nxn_eshop_pricelist'], 'this.uuid = pricelistNxN.fk_customer');
				$source->where('pricelistNxN.fk_pricelist', $value);
			}, '', 'pricelist', 'Ceník', $this->pricelistRepo->getArrayForSelect(true), ['placeholder' => '- Ceník -']);
		}
		
		if ($loyaltyPrograms = $this->loyaltyProgramRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (ICollection $source, $value): void {
				$source->where('this.fk_loyaltyProgram', $value);
			}, '', 'loyaltyPrograms', 'Věrnostní program', $loyaltyPrograms)->setPrompt('- Věrnostní program -');
		}
		
		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('accounts.uuid ' . ($value === '1' ? 'IS NOT NULL' : 'IS NULL'));
		}, '', 'accountsAssigned', 'Účet', ['0' => 'Bez účtu', '1' => 'S účtem'])->setPrompt('- Účet -');
		
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function exportCustomers(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);
		
		$tempFilename = \tempnam($this->tempDir, 'csv');
		$collection = $grid->getSource()->where($grid->getSourceIdName(), $grid->getSelectedIds());
		$this->customerRepository->csvExport($collection, Writer::createFromPath($tempFilename, 'w+'));
		
		$response = new FileResponse($tempFilename, 'customers.csv', 'text/csv');
		$this->sendResponse($response);
	}
	
	public function exportTargito(Button $button): void
	{
		unset($button);
		
		$tempFilename = \tempnam($this->tempDir, 'csv');
		
		$origin = $this::CONFIGURATIONS['targitoOrigin'] ?? null;
		
		$this->customerRepository->csvExportTargito($this->customerRepository->many(), Writer::createFromPath($tempFilename, 'w+'), $origin);
		
		$response = new FileResponse($tempFilename, 'customers.csv', 'text/csv');
		$this->sendResponse($response);
	}
	
	public function exportAccounts(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);
		
		$tempFilename = \tempnam($this->tempDir, 'csv');
		$collection = $grid->getSource()->where('this.' . $grid->getSourceIdName(), $grid->getSelectedIds());
		$this->customerRepository->csvExportAccounts($collection, Writer::createFromPath($tempFilename, 'w+'));
		
		$response = new FileResponse($tempFilename, 'accounts.csv', 'text/csv');
		$this->sendResponse($response);
	}
	
	public function handleLoginCustomer($login): void
	{
		$this->user->login($this->customerRepository->getByAccountLogin($login), null, [Customer::class]);
		
		$this->presenter->redirect(':Web:Index:default');
	}
	
	public function actionEditAccount(Account $account): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('accountForm');
		
		/** @var \Forms\Container $container */
		$container = $form['account'];
		$container->setDefaults($account->toArray());
		
		$permission = $this->catalogPermissionRepo->many()->where('fk_account', $account->getPK())->first();
		
		if ($permission) {
			/** @var \Forms\Container $container */
			$container = $form['permission'];
			$container->setDefaults($permission->toArray());
		}
		
		/** @var \Forms\Container $container */
		$container = $form['newsletter'];
		
		$newsletterUser = $this->newsletterUserRepository->one(['fk_customerAccount' => $account->getPK()]);
		
		$container->setDefaults([
			'newsletter' => (bool) $newsletterUser,
			'newsletterGroups' => $newsletterUser ? $newsletterUser->toArray(['groups'])['groups'] : [],
		]);
		
		$this->accountFormFactory->onUpdateAccount[] = function (Account $account, array $values, array $oldValues) use ($permission, $form): void {
			if ($permission) {
				$permission->update($values['permission']);
			} else {
				$this->catalogPermissionRepo->createOne($values['permission'] + ['account' => $account->getPK()]);
			}
			
			if ($this::CONFIGURATIONS['sendEmailAccountActivated']) {
				if (!$oldValues['active'] && $values['account']['active'] === true) {
					$mail = $this->templateRepository->createMessage('account.activated', ['email' => $account->login], $account->login, null, null, $account->getPreferredMutation());
					$this->mailer->send($mail);
				}
			}
			
			/** @var bool $newsletter */
			$newsletter = Arrays::pick($values['newsletter'], 'newsletter', false);
			$newsletterGroups = Arrays::pick($values['newsletter'], 'newsletterGroups', null);
			
			$this->newsletterUserRepository->many()->where('fk_customerAccount', $account->getPK())->delete();
			
			if ($newsletter && Validators::isEmail($account->login)) {
				$this->newsletterUserRepository->syncOne([
					'email' => $account->login,
					'customerAccount' => $account->getPK(),
					'groups' => $newsletterGroups,
				]);
			}
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('editAccount', 'default', [$account]);
		};
	}
	
	public function actionNewAccount(?Customer $customer = null): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('accountForm');
		$form['account']['password']->setRequired();
		
		if ($customer) {
			$form['permission']['customer']->setDefaultValue($customer);
		}
		
		$this->accountFormFactory->onCreateAccount[] = function (Account $account, array $values) use ($form): void {
			$this->catalogPermissionRepo->createOne($values['permission'] + ['account' => $account]);
			
			/** @var bool $newsletter */
			$newsletter = Arrays::pick($values['newsletter'], 'newsletter', false);
			$newsletterGroups = Arrays::pick($values['newsletter'], 'newsletterGroups', null);
			
			$this->newsletterUserRepository->many()->where('fk_customerAccount', $account->getPK())->delete();
			
			if ($newsletter && Validators::isEmail($account->login)) {
				$this->newsletterUserRepository->syncOne([
					'email' => $account->login,
					'customerAccount' => $account->getPK(),
					'groups' => $newsletterGroups,
				]);
			}
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('editAccount', 'default', [$account]);
		};
	}
	
	public function renderNewAccount(?Customer $customer = null): void
	{
		unset($customer);
		
		$this->template->headerLabel = 'Nový účet zákazníka';
		$this->template->headerTree = [
			['Obchodníci', 'default'],
			['Nový účet zákazníka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('accountForm')];
	}
	
	public function createComponentForm(): AdminForm
	{
		$lableMerchants = $this::CONFIGURATIONS['labels']['merchants'];
		
		$form = $this->formFactory->create();
		
		$form->addText('fullname', 'Jméno a příjmení');
		$form->addText('company', 'Firma');
		$form->addText('ic', 'IČ');
		$form->addText('dic', 'DIČ');
		$form->addText('phone', 'Telefon');
		
		$form->addText('email', 'E-mail')->addRule($form::EMAIL)->setRequired();
		$form->addText('ccEmails', 'Kopie e-mailů')->setHtmlAttribute('data-info', 'Zadejte e-mailové adresy oddělené středníkem (;).');
		
		$form->addDataMultiSelect('pricelists', 'Ceníky', $this->pricelistRepo->many()->toArrayOf('name'))
			->setHtmlAttribute('placeholder', 'Vyberte položky...');
		
		$customersForSelect = $this->customerRepository->getArrayForSelect();
		
		/** @var \Eshop\DB\Customer|null $customer */
		$customer = $this->getParameter('customer');
		
		if ($customer) {
			unset($customersForSelect[$customer->getPK()]);
		}
		
		$form->addDataMultiSelect('merchants', $lableMerchants, $this->merchantRepository->getArrayForSelect());
		$form->addDataSelect('group', 'Skupina', $this->groupsRepo->getArrayForSelect(true, $this::CONFIGURATIONS['showUnregisteredGroup']))
			->setPrompt('Žádná');
		
		$form->addGroup('Nákup a preference');
		
		if (isset($this::CONFIGURATIONS['branches']) && $this::CONFIGURATIONS['branches']) {
			$form->addSelect2('parentCustomer', 'Nadřazený zákazník', $customersForSelect)->setPrompt('Žádná');
			$form->addSelect('orderPermission', 'Objednání', [
				'fullWithApproval' => 'Pouze se schválením',
				'full' => 'Povoleno',
			])->setDefaultValue('full');
		}
		
		$form->addDataSelect('preferredMutation', 'Preferovaný jazyk', \array_combine($this->formFactory->formFactory->getDefaultMutations(), $this->formFactory->formFactory->getDefaultMutations()))
			->setPrompt('Automaticky');
		$form->addDataSelect('preferredCurrency', 'Preferovaná měna nákupu', $this->currencyRepo->getArrayForSelect())->setPrompt('Žádný');
		
		if (isset($this::CONFIGURATIONS['deliveryPayment']) && $this::CONFIGURATIONS['deliveryPayment']) {
			$form->addDataSelect('preferredPaymentType', 'Preferovaná platba', $this->paymentTypeRepo->getArrayForSelect())->setPrompt('Žádná');
			$form->addDataSelect('preferredDeliveryType', 'Preferovaná doprava', $this->deliveryTypeRepo->getArrayForSelect())->setPrompt('Žádná');
			$form->addDataMultiSelect('exclusivePaymentTypes', 'Povolené exkluzivní platby', $this->paymentTypeRepo->getArrayForSelect())
				->setHtmlAttribute('placeholder', 'Vyberte položky...');
			$form->addDataMultiSelect('exclusiveDeliveryTypes', 'Povolené exkluzivní dopravy', $this->deliveryTypeRepo->getArrayForSelect())
				->setHtmlAttribute('placeholder', 'Vyberte položky...');
		}
		
		if (isset($this::CONFIGURATIONS['loyaltyProgram']) && $this::CONFIGURATIONS['loyaltyProgram']) {
			$form->addSelect2('loyaltyProgram', 'Věrnostní program', $this->loyaltyProgramRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
			//->setHtmlAttribute('data-info', 'Zadejte e-mailové adresy oddělené středníkem (;).');
			
			if ($customer && $customer->getValue('loyaltyProgram')) {
				$loyaltyProgram = $this->loyaltyProgramRepository->one($customer->getValue('loyaltyProgram'), true);
				$customerTurnover = $this->orderRepository->getCustomerTotalTurnover($customer, $loyaltyProgram->turnoverFrom ? new DateTime($loyaltyProgram->turnoverFrom) : null, new DateTime());
				
				$form->addText('loyaltyProgramTurnover', 'Objem objednávek (Kč)')->setDisabled()->setDefaultValue((string) $customerTurnover);
				$form->addText('loyaltyProgramPoints', 'Stav věrnostního konta')->setDisabled()->setDefaultValue((string)$customer->getLoyaltyProgramPoints());
				$form->addText('loyaltyProgramDiscountLevel', 'Procentuální sleva věrnostního programu (%)')
					->setDisabled();
			}
		}
		
		if (isset($this::CONFIGURATIONS['discountLevel']) && $this::CONFIGURATIONS['discountLevel']) {
			$form->addInteger('discountLevelPct', 'Sleva (%)')
				->setHtmlAttribute(
					'data-info',
					'Aplikuje se vždy největší z čtveřice: procentuální slevy produktu, procentuální slevy zákazníka, slevy věrnostního programu zákazníka nebo slevového kupónu.<br>
Platí jen pokud má ceník povoleno "Povolit procentuální slevy".',
				)
				->setDefaultValue(0)
				->setRequired();
			
			$form->addInteger('maxDiscountProductPct', 'Max. sleva produktů (%)')
				->setHtmlAttribute(
					'data-info',
					'Omezuje maximální slevu z dvojice uživatel - produkt.',
				)
				->setDefaultValue(0)
				->setRequired();
		}
		
		if (isset($this::CONFIGURATIONS['rounding']) && $this::CONFIGURATIONS['rounding']) {
			$form->addText('productRoundingPct', 'Zokrouhlení od procent (%)')->setNullable()->setHtmlType('number')->addCondition($form::FILLED)->addRule(Form::INTEGER);
		}
		
		$form->addGroup('Exporty');
		$form->addCheckbox('allowExport', 'Feed povolen');
		
		if ($this::CONFIGURATIONS['edi']) {
			$form->addText('ediCompany', 'EDI: Identifikátor firmy')
				->setHtmlAttribute('Bude použito při exportu objednávky do formátu EDI.');
			$form->addText('ediBranch', 'EDI: Identifikátor pobočky')
				->setHtmlAttribute('Bude použito při exportu objednávky do formátu EDI.');
		}
		
		//$form->bind($this->customerRepository->getStructure(), []);
		
		$form->addSubmits(!$this->getParameter('customer'));
		
		return $form;
	}
	
	public function createComponentEditAddress(): AdminForm
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
		$deliveryAddress->addText('companyName', ' Název firmy');
		$deliveryAddress->addText('street', 'Ulice');
		$deliveryAddress->addText('city', 'Město');
		$deliveryAddress->addText('zipcode', 'PSČ');
		$deliveryAddress->addText('state', 'Stát');
		
		$form->bind(null, [
			'deliveryAddress' => $this->addressRepo->getStructure(),
			'billAddress' => $this->addressRepo->getStructure(),
		]);
		
		$form->addSubmits();
		
		return $form;
	}
	
	public function renderDefault(?Customer $customer = null): void
	{
		unset($customer);
		
		if ($this->tab === 'customers') {
			$this->template->headerLabel = 'Zákazníci';
			$this->template->headerTree = [
				['Zákazníci', 'default'],
			];
			$this->template->displayButtons = [$this->createNewItemButton('new')];
			$this->template->displayControls = [$this->getComponent('customers')];
		} elseif ($this->tab === 'accounts') {
			$this->template->headerLabel = 'Účty';
			$this->template->headerTree = [
				['Zákazníci', 'default'],
				['Účty'],
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
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function renderEditAddress(): void
	{
		$this->template->headerLabel = 'Adresy';
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Adresy'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('editAddress')];
	}
	
	public function renderEditAccount(Account $account): void
	{
		unset($account);
		
		$this->template->headerLabel = 'Účet';
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Účet'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('accountForm')];
	}
	
	public function actionNew(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('form');
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			$merchants = Arrays::pick($values, 'merchants');
			
			$customer = $this->customerRepository->syncOne($values, null, true);
			
			foreach ($merchants as $merchant) {
				$this->storm->createRow('eshop_merchant_nxn_eshop_customer', ['fk_merchant' => $merchant, 'fk_customer' => $customer]);
			}
			
			$this->flashMessage('Vytvořeno', 'success');
			$form->processRedirect('edit', 'default', [$customer]);
		};
	}
	
	public function actionEdit(Customer $customer): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('form');
		
		$merchants = $this->merchantRepository->many()
			->setSelect(['this.uuid'])
			->join(['nxn' => 'eshop_merchant_nxn_eshop_customer'], 'this.uuid = nxn.fk_merchant')
			->where('fk_customer', $customer)
			->toArray();
		
		$defaults = $customer->toArray([
				'pricelists',
				'exclusivePaymentTypes',
				'exclusiveDeliveryTypes',
				'accounts',
			]) + ['merchants' => $merchants];
		
		if ($customer->loyaltyProgramDiscountLevel) {
			$defaults['loyaltyProgramDiscountLevel'] = (string)$customer->loyaltyProgramDiscountLevel->discountLevel;
		}
		
		$form->setDefaults($defaults);
		
		$form->onSuccess[] = function (AdminForm $form) use ($customer): void {
			$values = $form->getValues('array');
			
			$this->storm->rows(['eshop_merchant_nxn_eshop_customer'])->where('fk_customer', $customer)->delete();
			
			foreach ($values['merchants'] as $merchant) {
				$this->storm->createRow('eshop_merchant_nxn_eshop_customer', ['fk_merchant' => $merchant, 'fk_customer' => $customer]);
			}
			
			unset($values['merchants']);
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
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('editAddress');
		
		$form->setDefaults($customer->toArray(['billAddress', 'deliveryAddress']));
		
		$form->onSuccess[] = function (AdminForm $form) use ($customer): void {
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
		$callback = function (Form $form): void {
			$form->addGroup('Oprávnění a zákazník');
			$container = $form->addContainer('permission');
			$container->addSelect2('customer', 'Zákazník', $this->customerRepository->getArrayForSelect())->setPrompt('-Zvolte-')->setRequired();
			$catalogInput = $container->addSelect('catalogPermission', 'Zobrazení', Shopper::PERMISSIONS)->setDefaultValue('price');
			
			$catalogInput->addCondition($form::EQUAL, 'price')
				->toggle('frm-accountForm-permission-showPricesWithoutVat-toogle')
				->toggle('frm-accountForm-permission-showPricesWithVat-toogle');
			
			if (isset($this::CONFIGURATIONS['prices']) && $this::CONFIGURATIONS['prices']) {
				if ($this->shopper->getShowWithoutVat()) {
					$withoutVatInput = $container->addCheckbox('showPricesWithoutVat', 'Zobrazit ceny bez daně');
				}
				
				if ($this->shopper->getShowVat()) {
					$withVatInput = $container->addCheckbox('showPricesWithVat', 'Zobrazit ceny s daní');
				}
				
				if ($this->shopper->getShowWithoutVat() && $this->shopper->getShowVat() && isset($withoutVatInput) && isset($withVatInput)) {
					$container->addSelect('priorityPrice', 'Prioritní cena', [
						'withoutVat' => 'Bez daně',
						'withVat' => 'S daní',
					])->addConditionOn($catalogInput, $form::EQUAL, 'price')
						->addConditionOn($withoutVatInput, $form::EQUAL, true)
						->addConditionOn($withVatInput, $form::EQUAL, true)
						->toggle('frm-accountForm-permission-priorityPrice-toogle');
				}
			}
			
			$container->addCheckbox('buyAllowed', 'Povolit nákup')->setDefaultValue(true);
			$container->addCheckbox('viewAllOrders', 'Zobrazit všechny objednávky zákazníka')->setDefaultValue(false);
			
			$container = $form->addContainer('newsletter');
			
			$newsletterInput = $container->addCheckbox('newsletter', 'Přihlášen k newsletteru');
			$newsletterGroupsInput = $container->addMultiSelect2('newsletterGroups', 'Skupiny newsletteru', $this->newsletterUserGroupRepository->getArrayForSelect());
			
			$newsletterInput->addCondition($form::FILLED)->toggle($newsletterGroupsInput->getHtmlId() . '-toogle');
		};
		
		return $this->accountFormFactory->create(false, $callback, true, true);
	}
	
	public function createComponentAccountGrid(): AdminGrid
	{
		$merchantLabels = $this::CONFIGURATIONS['labels']['merchants'];
		
		$collection = $this->accountRepository->many()
			->join(['admin' => 'admin_administrator_nxn_security_account'], 'this.uuid = admin.fk_account')
			->join(['merchant' => 'eshop_merchant_nxn_security_account'], 'this.uuid = merchant.fk_account')
			->where('admin.fk_administrator IS NULL')
			->where('merchant.fk_merchant IS NULL')
			->join(['catalogPermission' => 'eshop_catalogpermission'], 'catalogPermission.fk_account = this.uuid')
			->join(['customer' => 'eshop_customer'], 'customer.uuid = catalogPermission.fk_customer')
			->join(['newsletterUser' => 'eshop_newsletteruser'], 'this.uuid = newsletterUser.fk_customerAccount')
			->select(['company' => 'customer.company', 'customerFullname' => 'customer.fullname'])
			->select([
				'permission' => 'catalogPermission.catalogPermission',
				'buyAllowed' => 'catalogPermission.buyAllowed',
			]);
		
		$grid = $this->gridFactory->create($collection, 20, 'createdTs', 'DESC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Vytvořen', 'tsRegistered|date', '%s', 'tsRegistered', ['class' => 'fit']);
		$grid->addColumnText('Login', 'login', '%s', 'login', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Jméno a příjmení', 'fullname', '%s', 'fullname');
		$grid->addColumn('Zákazník', function (Account $account) {
			return $account->getValue('company') ?: ($account->getValue('customerFullname') ?: '');
		});
		$grid->addColumn('Oprávnění', function (Account $account) {
			if (!$account->getValue('permission')) {
				return '';
			}
			
			$label = Shopper::PERMISSIONS;
			
			return '' . $label[$account->getValue('permission')] . ' + ' . ($account->getValue('buyAllowed') ? 'nákup' : 'bez nákupu');
		});
		
		$grid->addColumnText('Aktivní od', "activeFrom|date:'d.m.Y G:i'", '%s', 'activeFrom', ['class' => 'fit']);
		$grid->addColumnText('Aktivní do', "activeTo|date:'d.m.Y G:i'", '%s', 'activeTo', ['class' => 'fit']);
		$grid->addColumnInputCheckbox('Aktivní', 'active');
		
		if ($this::CONFIGURATIONS['showAuthorized']) {
			$grid->addColumnInputCheckbox('Autorizovaný', 'authorized');
		}
		
		$btnSecondary = 'btn btn-sm btn-outline-primary';
		$grid->addColumn('Login', function (Account $object, Datagrid $grid) use ($btnSecondary) {
			$link = $grid->getPresenter()->link('loginCustomer!', [$object->login]);
			
			return $object->isActive() ?
				"<a class='$btnSecondary' target='_blank' href='$link'><i class='fa fa-sign-in-alt'></i></a>" :
				"<a class='$btnSecondary disabled' href='#'><i class='fa fa-sign-in-alt'></i></a>";
		}, '%s', null, ['class' => 'minimal']);
		$grid->addColumnLinkDetail('editAccount');
		
		$grid->addColumnActionDelete();
		
		$grid->addButtonSaveAll([], [], null, false, null, function ($id, $data): void {
			if ($this::CONFIGURATIONS['sendEmailAccountActivated']) {
				/** @var \Security\DB\Account $account */
				$account = $this->accountRepository->one($id);
				
				if (!$account->active && $data['active'] === true) {
					$mail = $this->templateRepository->createMessage('account.activated', ['email' => $account->login], $account->login, null, null, $account->getPreferredMutation());
					$this->mailer->send($mail);
				}
			}
		});
		
		$grid->addButtonDeleteSelected(null, false, null, 'this.uuid');
		
		$grid->addFilterTextInput('search', ['this.login'], null, 'Login');
		$grid->addFilterTextInput('company', ['customer.company', 'customer.fullname', 'customer.ic', 'customer.email'], null, 'Zákazník, IČ');
		
		if (\count($this->merchantRepository->getArrayForSelect()) > 0) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
				$source->join(['merchantXcustomer' => 'eshop_merchant_nxn_eshop_customer'], 'customer.uuid = merchantXcustomer.fk_customer');
				$source->where('merchantXcustomer.fk_merchant', $value);
			}, '', 'merchant', $merchantLabels, $this->merchantRepository->getArrayForSelect(), ['placeholder' => "- $merchantLabels -"]);
		}
		
		if (\count($this->groupsRepo->getArrayForSelect(true, $this::CONFIGURATIONS['showUnregisteredGroup'])) > 0) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
				$source->where('customer.fk_group', $value);
			}, '', 'group', 'Skupina', $this->groupsRepo->getArrayForSelect(true, $this::CONFIGURATIONS['showUnregisteredGroup']), ['placeholder' => '- Skupina -']);
		}
		
		if (\count($this->pricelistRepo->getArrayForSelect(true)) > 0) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
				$source->join(['pricelistNxN' => 'eshop_customer_nxn_eshop_pricelist'], 'customer.uuid = pricelistNxN.fk_customer');
				$source->where('pricelistNxN.fk_pricelist', $value);
			}, '', 'pricelist', 'Ceník', $this->pricelistRepo->getArrayForSelect(true), ['placeholder' => '- Ceník -']);
		}
		
		$grid->addFilterSelectInput('newsletter', 'IF(:nQ = "1", newsletterUser.uuid IS NOT NULL, newsletterUser.uuid IS NULL)', 'Newsletter', '- Newsletter -', null, [
			'0' => 'Ne',
			'1' => 'Ano',
		], 'nQ');
		
		$submit = $grid->getForm()->addSubmit('permBulkEdit', 'Hromadná úprava')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');
		
		$submit->onClick[] = function () use ($grid): void {
			$grid->getPresenter()->redirect('permBulkEdit', [$grid->getSelectedIds()]);
		};
		
		$submit = $grid->getForm()->addSubmit('downloadEmails', 'Export e-mailů');
		$submit->setHtmlAttribute('class', 'btn btn-sm btn-outline-primary');
		$submit->onClick[] = [$this, 'exportAccounts'];
		
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function renderPermBulkEdit(array $ids): void
	{
		unset($ids);
		
		$this->template->headerLabel = 'Hromadná úprava';
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Účty', 'default'],
			['Hromadná úprava'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('permBulkEditForm')];
	}
	
	public function createComponentPermBulkEditForm(): AdminForm
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $this->getComponent('accountGrid');
		
		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $grid->getFilteredSource()->setGroupBy([])->enum($grid->getFilteredSource()->getPrefix(true) . $grid->getSourceIdName());
		$selectedNo = \count($ids);
		
		$form = $this->formFactory->create();
		unset($form['uuid']);
		
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');
		
		$values = $form->addContainer('values');
		
		$values->addSelect('catalogPermission', 'Zobrazení', Shopper::PERMISSIONS)->setPrompt('Původní');
		$values->addSelect('buyAllowed', 'Povolit nákup', [
			false => 'Ne',
			true => 'Ano',
		])->setPrompt('Původní');
		$values->addSelect('viewAllOrders', 'Zobrazit všechny objednávky zákazníka', [
			false => 'Ne',
			true => 'Ano',
		])->setPrompt('Původní');
		$values->addSelect('newsletter', 'Přihlášen k newsletteru', [
			false => 'Ne',
			true => 'Ano',
		])->setPrompt('Původní');
		$values->addCheckbox('newsletterGroupsCheck', 'Původní')->setDefaultValue(true);
		$values->addMultiSelect2('newsletterGroups', 'Skupiny pro newsletter', $this->newsletterUserGroupRepository->getArrayForSelect());
		
		$form->addSubmits(false, false);
		
		$form->onSuccess[] = function (AdminForm $form) use ($ids, $grid): void {
			$values = $form->getValues('array');
			
			if (\count($values['values']) === 0) {
				return;
			}
			
			if ($values['values']['newsletterGroupsCheck']) {
				unset($values['values']['newsletterGroups']);
			}
			
			unset($values['values']['newsletterGroupsCheck']);
			
			foreach ($values['values'] as $key => $value) {
				if ($value === null) {
					unset($values['values'][$key]);
				}
			}
			
			/** @var null|int $newsletter */
			$newsletter = Arrays::pick($values['values'], 'newsletter', null);
			$newsletterGroups = Arrays::pick($values['values'], 'newsletterGroups', []);
			
			/** @var array<\Eshop\DB\NewsletterUser> $existingNewsletters */
			$existingNewsletters = $this->newsletterUserRepository->many()
				->where('this.fk_customerAccount IS NOT NULL')
				->setIndex('this.fk_customerAccount')
				->toArray();
			
			$ids = $values['bulkType'] === 'selected' ? $ids : $grid->getFilteredSource()->toArrayOf($grid->getSourceIdName());
			
			foreach ($ids as $id) {
				if (\count($values['values']) > 0) {
					$this->catalogPermissionRepo->many()->where('fk_account', $id)->update($values['values']);
				}
				
				$account = $this->accountRepository->one($id);
				$newsletterValues = [];
				
				if (!Validators::isEmail($account->login)) {
					continue;
				}
				
				if ($newsletter === 1) {
					$newsletterValues = [
						'email' => $account->login,
						'customerAccount' => $account->getPK(),
					];
					
					if (isset($newsletterGroups)) {
						$newsletterValues = [
							'groups' => $newsletterGroups,
						];
					}
				} elseif ($newsletter === 0) {
					$this->newsletterUserRepository->many()->where('this.fk_customerAccount', $account->getPK())->delete();
				}
				
				if (isset($existingNewsletters[$account->getPK()]) && isset($newsletterGroups)) {
					$newsletterValues = [
						'email' => $existingNewsletters[$account->getPK()]->email,
						'customerAccount' => $account->getPK(),
						'groups' => $newsletterGroups,
					];
				}
				
				$this->newsletterUserRepository->syncOne($newsletterValues);
			}
			
			$this->getPresenter()->flashMessage('Uloženo', 'success');
			$this->redirect('default');
		};
		
		return $form;
	}
}
