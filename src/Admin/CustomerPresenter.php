<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Admin\Controls\AccountFormFactory;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Carbon\Carbon;
use Eshop\Admin\Controls\Customer\FavouriteProductsTrait;
use Eshop\Common\Helpers;
use Eshop\DB\AddressRepository;
use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\CustomerRoleRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\InternalRibbon;
use Eshop\DB\InternalRibbonRepository;
use Eshop\DB\LoyaltyProgramRepository;
use Eshop\DB\MerchantRepository;
use Eshop\DB\NewsletterUserGroupRepository;
use Eshop\DB\NewsletterUserRepository;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\Pricelist;
use Eshop\DB\PricelistRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\VisibilityListRepository;
use Eshop\Services\LostPasswordService;
use Eshop\Services\ProductsCache\GeneralProductsCacheProvider;
use Eshop\Services\SettingsService;
use Eshop\ShopperUser;
use Forms\Form;
use Grid\Datagrid;
use GuzzleHttp\Exception\GuzzleException;
use League\Csv\Reader;
use League\Csv\Writer;
use LiquidMonitorConnector\Exceptions\LiquidMonitorDisabledException;
use Messages\DB\TemplateRepository;
use Nette\Application\Responses\FileResponse;
use Nette\Application\UI\Presenter;
use Nette\DI\Attributes\Inject;
use Nette\Forms\Controls\Button;
use Nette\Forms\Controls\SelectBox;
use Nette\Mail\Mailer;
use Nette\NotImplementedException;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use Nette\Utils\Validators;
use Security\DB\Account;
use Security\DB\AccountRepository;
use StORM\Collection;
use StORM\Connection;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;

class CustomerPresenter extends \Eshop\BackendPresenter
{
	use FavouriteProductsTrait;

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
		'customerRoles' => false,
	];

	protected const SHOW_ACCOUNTS_BULK_REGISTER_EMAIL = false;

	/** @persistent */
	public string $tab = 'customers';

	/**
	 * @var array<callable(\Admin\Controls\AdminGrid $grid): void>
	 */
	public array $onBeforeAddButtonsCustomersGrid = [];

	/**
	 * @var array<callable(\Admin\Controls\AdminGrid $grid): void>
	 */
	public array $onBeforeAddButtonsAccountsGrid = [];

	/**
	 * @var array<callable(\Admin\Controls\AdminForm $form): void>
	 */
	public array $onBeforeSubmitEditAddress = [];

	#[Inject]
	public AccountFormFactory $accountFormFactory;
	
	#[Inject]
	public CustomerRepository $customerRepository;
	
	#[Inject]
	public AccountRepository $accountRepository;
	
	#[Inject]
	public MerchantRepository $merchantRepository;
	
	#[Inject]
	public TemplateRepository $templateRepository;
	
	#[Inject]
	public ProductRepository $productRepo;
	
	#[Inject]
	public PaymentTypeRepository $paymentTypeRepo;
	
	#[Inject]
	public DeliveryTypeRepository $deliveryTypeRepo;
	
	#[Inject]
	public CurrencyRepository $currencyRepo;
	
	#[Inject]
	public CustomerGroupRepository $groupsRepo;

	#[Inject]
	public CustomerRoleRepository $customerRoleRepo;

	#[Inject]
	public OrderRepository $orderRepository;
	
	#[Inject]
	public AddressRepository $addressRepo;
	
	#[Inject]
	public Mailer $mailer;
	
	#[Inject]
	public PricelistRepository $pricelistRepo;
	
	#[Inject]
	public CatalogPermissionRepository $catalogPermissionRepo;
	
	#[Inject]
	public Connection $storm;
	
	#[Inject]
	public ShopperUser $shopperUser;
	
	#[Inject]
	public LoyaltyProgramRepository $loyaltyProgramRepository;
	
	#[Inject]
	public NewsletterUserRepository $newsletterUserRepository;
	
	#[Inject]
	public NewsletterUserGroupRepository $newsletterUserGroupRepository;

	#[Inject]
	public VisibilityListRepository $visibilityListRepository;

	#[Inject]
	public LostPasswordService $lostPasswordService;

	#[Inject]
	public GeneralProductsCacheProvider $productsCacheGetterService;

	#[Inject]
	public InternalRibbonRepository $internalRibbonRepository;

	#[Inject]
	public SettingsService $settingsService;

	#[Inject]
	public \LiquidMonitorConnector\Actions\GetCronService $getCronService;

	/**
	 * @var null|callable(array<mixed> $values, \Admin\Controls\AdminForm $form): bool
	 */
	protected mixed $onCustomerFormUniqueValidation = null;

	public function addFiltersToCustomersGrid(AdminGrid $grid): void
	{
		$lableMerchants = $this::CONFIGURATIONS['labels']['merchants'];

		$grid->addFilterTextInput('search', ['this.fullname', 'this.email', 'this.phone', 'this.company'], null, 'Jméno a příjmení, e-mail, telefon, firma');

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

		if (isset($this::CONFIGURATIONS['customerRoles']) && $this::CONFIGURATIONS['customerRoles']) {
			if (\count($this->customerRoleRepo->getArrayForSelect(true)) > 0) {
				$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
					$source->where('fk_customerRole', $value);
				}, '', 'customerRole', 'Role', $this->customerRoleRepo->getArrayForSelect(true), ['placeholder' => '- Role -']);
			}
		}

		if (\count($this->pricelistRepo->getArrayForSelect(true)) > 0) {
			$grid->addFilterText(function (ICollection $source, $value): void {
				if (!$value) {
					return;
				}

				$value = \explode(';', Strings::trim($value));
				$uuids = $this->pricelistRepo->many()
					->where('this.code', $value)
					->setSelect(['this.uuid'])
					->toArrayOf('uuid', toArrayValues: true);

				$source->join(['pricelistNxN' => 'eshop_customer_nxn_eshop_pricelist'], 'this.uuid = pricelistNxN.fk_customer');
				$source->where('pricelistNxN.fk_pricelist', $uuids);
			}, '', 'pricelist')
				->setHtmlAttribute('placeholder', 'Ceníky (kódy oddělené středníkem)')
				->setHtmlAttribute('class', 'form-control form-control-sm');
		}

		if ($loyaltyPrograms = $this->loyaltyProgramRepository->getArrayForSelect()) {
			$grid->addFilterDataSelect(function (ICollection $source, $value): void {
				$source->where('this.fk_loyaltyProgram', $value);
			}, '', 'loyaltyPrograms', 'Věrnostní program', $loyaltyPrograms)->setPrompt('- Věrnostní program -');
		}

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('accounts.uuid ' . ($value === '1' ? 'IS NOT NULL' : 'IS NULL'));
		}, '', 'accountsAssigned', 'Účet', ['0' => 'Bez účtu', '1' => 'S účtem'])->setPrompt('- Účet -');

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('this.fk_parentCustomer ' . ($value === '1' ? 'IS NOT NULL' : 'IS NULL'));
		}, '', 'parentCustomer', null, ['0' => 'Ne', '1' => 'Ano'])->setPrompt('- Nadřazený zák. -');

		$grid->addFilterPolyfillDatetime(function (ICollection $source, $value): void {
			$source->where('this.createdTs >= :createdTs_from', ['createdTs_from' => $value]);
		}, '', 'createdTs_from', null, ['defaultHour' => '00', 'defaultMinute' => '00'])
			->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')
			->setHtmlAttribute('placeholder', 'Registrace od');

		$grid->addFilterPolyfillDatetime(function (ICollection $source, $value): void {
			$source->where('this.createdTs <= :createdTs_to', ['createdTs_to' => $value]);
		}, '', 'createdTs_to', null, ['defaultHour' => '23', 'defaultMinute' => '59'])
			->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')
			->setHtmlAttribute('placeholder', 'Registrace do');

		$grid->addFilterPolyfillDatetime(function (ICollection $source, $value): void {
			$source->where('lastOrder.createdTs >= :lastOrder_createdTs_from', ['lastOrder_createdTs_from' => $value]);
		}, '', 'lastOrder_createdTs_from', null, ['defaultHour' => '00', 'defaultMinute' => '00'])
			->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')
			->setHtmlAttribute('placeholder', 'Poslední obj. od');

		$grid->addFilterPolyfillDatetime(function (ICollection $source, $value): void {
			$source->where('lastOrder.createdTs <= :lastOrder_createdTs_to', ['lastOrder_createdTs_to' => $value]);
		}, '', 'lastOrder_createdTs_to', null, ['defaultHour' => '23', 'defaultMinute' => '59'])
			->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')
			->setHtmlAttribute('placeholder', 'Poslední obj. do');

		$grid->addFilterDataSelect(function (ICollection $source, $customerType): void {
			if (!$customerType) {
				return;
			}

			$filter = match ($customerType) {
				'one' => '=1',
				'more' => '>1',
				default => '=0',
			};

			$source->where("this.ordersCount $filter");
		}, '', 'customerType', null, [
			'no' => 'Bez objednávky (=0)',
			'one' => 'Jedna objednávka (=1)',
			'more' => 'Více objednávek (>1)',
		])->setPrompt('- Počet obj. -');

		if (!$ribbons = $this->internalRibbonRepository->getArrayForSelect(type: InternalRibbon::TYPE_CUSTOMER)) {
			return;
		}

		$ribbons += ['0' => 'X - bez štítků'];
		$grid->addFilterDataMultiSelect(function (Collection $source, $value): void {
			$source->filter(['internalRibbon' => Helpers::replaceArrayValue($value, '0', null)]);
		}, '', 'internalRibbon', null, $ribbons, ['placeholder' => '- Int. štítky -']);
	}

	public function addFiltersToAccountsGrid(AdminGrid $grid): void
	{
		$grid->addFilterTextInput('search', ['this.login'], null, 'Login');
		$grid->addFilterTextInput('fullname', ['this.fullname'], null, 'Jméno účtu');
		$grid->addFilterTextInput('customer', ['customer.fullname'], null, 'Jméno zákazníka');
		$grid->addFilterTextInput('externalCode', ['customer.externalCode'], null, 'Kód zákazníka');
		$grid->addFilterTextInput('company', ['customer.company', 'customer.ic', 'customer.email'], null, 'Firma, IČ, email zákazníka');

		$merchantLabels = $this::CONFIGURATIONS['labels']['merchants'];

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

		if (isset($this::CONFIGURATIONS['customerRoles']) && $this::CONFIGURATIONS['customerRoles']) {
			if (\count($this->customerRoleRepo->getArrayForSelect(true)) > 0) {
				$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
					$source->where('customer.fk_customerRole', $value);
				}, '', 'customerRole', 'Skupina', $this->customerRoleRepo->getArrayForSelect(true), ['placeholder' => '- Role -']);
			}
		}

		if (\count($this->pricelistRepo->getArrayForSelect(true)) > 0) {
			$grid->addFilterText(function (ICollection $source, $value): void {
				if (!$value) {
					return;
				}

				$value = \explode(';', Strings::trim($value));
				$uuids = $this->pricelistRepo->many()
					->where('this.code', $value)
					->setSelect(['this.uuid'])
					->toArrayOf('uuid', toArrayValues: true);

				$source->join(['pricelistNxN' => 'eshop_customer_nxn_eshop_pricelist'], 'customer.uuid = pricelistNxN.fk_customer');
				$source->where('pricelistNxN.fk_pricelist', $uuids);
			}, '', 'pricelist')
				->setHtmlAttribute('placeholder', 'Ceníky (kódy oddělené středníkem)')
				->setHtmlAttribute('class', 'form-control form-control-sm');
		}

		$grid->addFilterSelectInput('newsletter', 'IF(:nQ = "1", newsletterUser.uuid IS NOT NULL, newsletterUser.uuid IS NULL)', 'Newsletter', '- Newsletter -', null, [
			'0' => 'Ne',
			'1' => 'Ano',
		], 'nQ');
	}
	
	public function createComponentCustomers(): AdminGrid
	{
		$lableMerchants = $this::CONFIGURATIONS['labels']['merchants'];

		$grid = $this->gridFactory->create($this->customerRepository->many()
			->select([
				'pricelists_names' => "GROUP_CONCAT(DISTINCT pricelists.name ORDER BY pricelists.priority, pricelists.uuid SEPARATOR ', ')",
				'visibilityLists_names' => "GROUP_CONCAT(DISTINCT visibilityLists.name ORDER BY visibilityLists.priority, visibilityLists.uuid SEPARATOR ', ')",
				'merchants_names' => "GROUP_CONCAT(DISTINCT merchants.fullname SEPARATOR ', ')",
			])
			->setGroupBy(['this.uuid']), 20, 'createdTs', 'DESC', true, filterShops: false);
		$grid->addColumnSelector();
		$grid->addColumnText('Registrace', 'createdTs|date', '%s', 'createdTs', ['class' => 'fit']);
		$grid->addColumn('Jméno / IČO<hr style=\"margin: 0\">Adresa (Fakt. / Doruč.)', function (Customer $customer) {
			$hr = '<hr style="margin: 0">';
			$billAddress = $customer->billAddress?->getFullAddress();
			$deliveryAddress = $customer->deliveryAddress?->getFullAddress();
			$ribbons = null;

			foreach ($customer->internalRibbons as $ribbon) {
				$ribbons .= "<div class=\"badge\" style=\"font-weight: normal; font-style: italic; background-color: $ribbon->backgroundColor; color: $ribbon->color\">$ribbon->name</div> ";
			}

			$customerCode = $customer->externalCode !== null ? " <span style='white-space: nowrap'>({$customer->externalCode})</span>" : '';
			$firstRow = "<div class='row'><div class='col-6'>{$customer->getName()} " . $customerCode . "</div><div class='col-6'>$customer->ic</div></div>";
			$secondRow = "<div class='row'><div class='col-6'>$billAddress</div><div class='col-6'>$deliveryAddress</div></div>";

			return $firstRow . $hr . $secondRow . $ribbons;
		});
		$td = '<a href="mailto:%1$s"><i class="far fa-envelope"></i> %1$s</a><br><a href="tel:%2$s"><i class="fa fa-phone-alt"></i> %2$s</a>';
		$grid->addColumnTextFit('E-mail / Telefon', ['email', 'phone'], $td)->onRenderCell[] = [$grid, 'decoratorEmpty'];

		$grid->addColumn("$lableMerchants<hr style=\"margin: 0\">Nadřazený zák.", function (Customer $customer) {
			return [
				$customer->getValue('merchants_names'),
				$customer->parentCustomer?->getName(),
				$customer->parentCustomer?->externalCode !== null ? " <span style='white-space: nowrap'>({$customer->parentCustomer->externalCode})</span>" : '',
			];
		}, '%s<hr style="margin: 0">%s%s');
		$grid->addColumnTextFit('Skupina', 'group.name', '%s', 'group.name');

		if (isset($this::CONFIGURATIONS['customerRoles']) && $this::CONFIGURATIONS['customerRoles']) {
			$grid->addColumnTextFit('Role', 'customerRole.name', '%s', 'customerRole.name');
		}

		$grid->addColumnText('Ceníky / Viditelníky', ['pricelists_names', 'visibilityLists_names'], '%s<hr style="margin: 0">%s');
		
		if (isset($this::CONFIGURATIONS['loyaltyProgram']) && $this::CONFIGURATIONS['loyaltyProgram']) {
			$grid->addColumn('Věrnostní prog.', function (Customer $object) {
				$link = $this->admin->isAllowed(':Eshop:Admin:LoyaltyProgram:programDetail') && $object->getValue('loyaltyProgram') ? $this->link(
					':Eshop:Admin:LoyaltyProgram:programDetail',
					[$object->loyaltyProgram],
				) : '#';
				
				return $object->getValue('loyaltyProgram') ?
					"<a href='" . $link . "'>" . $object->loyaltyProgram->name . '</a><small><br>Bodů: ' . $object->getLoyaltyProgramPoints() .
					' | Sleva: ' . ($object->loyaltyProgramDiscountLevel ? $object->loyaltyProgramDiscountLevel->discountLevel : 0) . '%</small>' :
					'';
			}, '%s');
		}

		$grid->addColumnText('Poslední obj.', ['lastOrder.code', "lastOrder.createdTs|date:'d.m.Y G:i'"], '%s<br><small>%s</small>', 'lastOrder.createdTs');
		$grid->addColumnText('Počet obj.', 'ordersCount', '%s', 'ordersCount', ['class' => 'fit']);

		Arrays::invoke($this->onBeforeAddButtonsCustomersGrid, $grid);
		$this->addCustomFieldsToCustomerGrid($grid);

		$btnSecondary = 'btn btn-sm btn-outline-primary';
		$grid->addColumn('Feed', function (Customer $customer) use ($btnSecondary) {
			return "<a class='$btnSecondary' target='_blank' href='" . $this->link('//:Eshop:Export:customer', $customer->getPK()) . "'><i class='fa fa-sm fa-rss'></i></a>";
		}, '%s', null, ['class' => 'minimal']);
		
		$grid->addColumn('', function (Customer $object, Datagrid $datagrid) use ($btnSecondary) {
			return \count($object->accounts) > 0 ?
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('this', [
					'tab' => 'accounts',
					'accountGrid-company' => $object->email,
					'accountGrid-customer' => $object->fullname,
					'accountGrid-externalCode' => $object->externalCode,
					'accountGrid-sessionIgnoreLoad' => true,
				]) . "'>Účty</a>" :
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('newAccount', $object) . "'>Vytvořit&nbsp;účet</a>";
		}, '%s', null, ['class' => 'minimal']);
		
		$grid->addColumnLink('editAddress', 'Adresy');
		$grid->addColumnLinkDetail('edit');

		$grid->addColumnActionDelete([$this->accountFormFactory, 'deleteAccountHolder']);
		
		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected([$this->accountFormFactory, 'deleteAccountHolder'], false, null, 'this.uuid');
		
		$grid->addButtonBulkEdit('form', $this->getBulkEdits(), 'customers', copyRawValues: [
			'pricelists' => 'pricelists',
			'favouritePriceLists' => 'favouritePriceLists',
			'visibilityLists' => 'visibilityLists',
			'favouriteProducts' => 'favouriteProducts',
			'parentCustomer' => 'parentCustomer',
		]);
//		$grid->addButtonBulkEdit(
//			'editFavouriteProducts',
//			['favouriteProducts'],
//			'customers',
//			'favouriteProducts',
//			'Upravit oblíbené produkty',
//			'bulkEdit',
//			copyRawValues: ['favouriteProducts' => 'favouriteProducts'],
//		);

		$submit = $grid->getForm()->addSubmit('downloadEmails', 'Export e-mailů')
			->setHtmlAttribute('class', 'btn btn-sm btn-outline-primary');
		$submit->onClick[] = [$this, 'exportCustomers'];
		
		if (isset($this::CONFIGURATIONS['targito']) && $this::CONFIGURATIONS['targito']) {
			$submit = $grid->getForm()->addSubmit('downloadContactsTargito', 'Export Targito (CSV)')
				->setHtmlAttribute('class', 'btn btn-sm btn-outline-primary');
			$submit->onClick[] = [$this, 'exportTargito'];
		}

		$this->addFiltersToCustomersGrid($grid);

		$grid->addFilterButtons();
		
		return $grid;
	}

	public function exportCustomers(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);
		
		$tempFilename = \tempnam($this->tempDir, 'csv');
		$collection = $grid->getFilteredSource();
		$this->customerRepository->csvExport($collection, Writer::createFromPath($tempFilename, 'w+'));
		
		$response = new FileResponse($tempFilename, 'customers.csv', 'text/csv');
		$this->sendResponse($response);
	}
	
	public function exportTargito(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);
		unset($button);
		
		$tempFilename = \tempnam($this->tempDir, 'csv');
		
		$origin = $this::CONFIGURATIONS['targitoOrigin'] ?? null;
		
		$this->customerRepository->csvExportTargito($grid->getFilteredSource(), Writer::createFromPath($tempFilename, 'w+'), $origin);
		
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
		$customer = $this->customerRepository->getByAccountLogin($login);

		if (!$customer) {
			$this->flashMessage('Nelze se přihlásit! Daný účet nemá přiřazeného zákazníka!', 'error');
			$this->redirect('this');
		}

		$this->user->login($customer, null, [Customer::class]);
		
		$this->redirect(':Web:Index:default');
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

		/** @var \Eshop\DB\Customer|null $customer */
		$customer = $this->getParameter('customer');

		$form->monitor(Presenter::class, function (Presenter $presenter) use ($form, $customer, $lableMerchants): void {
			$this->formFactory->addShopsContainerToAdminForm($form);

			$form->addText('fullname', 'Jméno a příjmení');
			$form->addText('company', 'Firma');
			$form->addText('ic', 'IČ')->setNullable();
			$form->addText('dic', 'DIČ')->setNullable();
			$form->addText('phone', 'Telefon');

			$form->addText('email', 'E-mail')->addRule($form::Email)->setRequired()->setDisabled((bool) $customer);
			$form->addText('ccEmails', 'Kopie e-mailů')->setHtmlAttribute('data-info', 'Zadejte e-mailové adresy oddělené středníkem (;).');

			$pricelistsInput = $form->addMultiSelectAjax('pricelists', 'Ceníky', 'Vyberte položky...', Pricelist::class)
				->setDisabled(!$this->isManager);

			$favouritePricelistsInput = $form->addMultiSelectAjax('favouritePriceLists', 'Oblíbené ceníky', 'Vyberte položky...', Pricelist::class)
				->setHtmlAttribute('data-info', 'Pokud zvolený ceník není přiřazen jako "Ceníky", bude dodatečně spárován.')
				->setDisabled(!$this->isManager);

			if ($customer) {
				$this->template->select2AjaxDefaults[$pricelistsInput->getHtmlId()] = $customer->getPricelists()->toArrayOf('name');
				$this->template->select2AjaxDefaults[$favouritePricelistsInput->getHtmlId()] = $customer->getFavouritePriceLists()->toArrayOf('name');
			}

			$form->addMultiSelect2('visibilityLists', 'Seznamy viditelnosti', $this->visibilityListRepository->getArrayForSelect())
				->setDisabled(!$this->isManager);

			$form->addMultiSelect2('internalRibbons', 'Interní štítky', $this->internalRibbonRepository->getArrayForSelect(type: InternalRibbon::TYPE_CUSTOMER))
				->setDefaultValue($customer?->internalRibbons->toArrayOf('uuid', toArrayValues: true));

			$customersForSelect = $this->customerRepository->getArrayForSelect();

			if ($customer) {
				unset($customersForSelect[$customer->getPK()]);
			}

			$form->addDataMultiSelect('merchants', $lableMerchants, $this->merchantRepository->getArrayForSelect());
			$form->addDataSelect('group', 'Skupina', $this->groupsRepo->getArrayForSelect(true, $this::CONFIGURATIONS['showUnregisteredGroup']))
				->setPrompt('Žádná');

			$productInput = $form->addMultiSelectAjax('favouriteProducts', 'Oblíbené produkty', 'Zvolte produkt', Product::class, ['maximumSelectionLength' => 500])
				->setHtmlAttribute('class', 'w-100');

			if ($customer) {
				$this->template->select2AjaxDefaults[$productInput->getHtmlId()] = $customer->getFavouriteProducts()->toArrayOf('name');
			}

			if (isset($this::CONFIGURATIONS['customerRoles']) && $this::CONFIGURATIONS['customerRoles']) {
				$form->addDataSelect('customerRole', 'Role', $this->customerRoleRepo->getArrayForSelect(true))->setPrompt('Žádná');
			}

			$form->addGroup('Nákup a preference');

			if (isset($this::CONFIGURATIONS['branches']) && $this::CONFIGURATIONS['branches']) {
				$parentCustomerInput = $form->addSelectAjax('parentCustomer', 'Nadřazený zákazník', 'Žádný', Customer::class);

				if ($customer && $parentCustomer = $customer->parentCustomer) {
					$this->template->select2AjaxDefaults[$parentCustomerInput->getHtmlId()] = [
						$parentCustomer->getPK() => $parentCustomer->getName() . ($parentCustomer->externalCode ? " ({$parentCustomer->externalCode})" : ''),
					];
				}

				$form->addSelect('orderPermission', 'Objednání', [
					'fullWithApproval' => 'Pouze se schválením',
					'full' => 'Povoleno',
				])->setDefaultValue('full');
			}

			$form->addText('lastOrder', 'Poslední objednávka')->setDisabled();

			$form->addDataSelect(
				'preferredMutation',
				'Preferovaný jazyk',
				\array_combine($this->formFactory->formFactory->getDefaultMutations(), $this->formFactory->formFactory->getDefaultMutations())
			)
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

			if (isset($this::CONFIGURATIONS['loyaltyProgram']) && $this::CONFIGURATIONS['loyaltyProgram'] && $this->isManager) {
				$form->addSelect2('loyaltyProgram', 'Věrnostní program', $this->loyaltyProgramRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
				//->setHtmlAttribute('data-info', 'Zadejte e-mailové adresy oddělené středníkem (;).');

				if ($customer && $customer->getValue('loyaltyProgram')) {
					$loyaltyProgram = $this->loyaltyProgramRepository->one($customer->getValue('loyaltyProgram'), true);
					$customerTurnover = $this->orderRepository->getCustomerTotalTurnover(
						$customer,
						$loyaltyProgram->turnoverFrom ?
							new \Carbon\Carbon($loyaltyProgram->turnoverFrom) : null,
						new \Carbon\Carbon(),
					);

					$form->addText('loyaltyProgramTurnover', 'Objem objednávek (Kč)')->setDisabled()->setDefaultValue((string) $customerTurnover);
					$form->addText('loyaltyProgramPoints', 'Stav věrnostního konta')->setDisabled()->setDefaultValue((string) $customer->getLoyaltyProgramPoints());
					$form->addText('loyaltyProgramDiscountLevel', 'Procentuální sleva věrnostního programu (%)')
						->setDisabled();
				}
			}

			if (isset($this::CONFIGURATIONS['discountLevel']) && $this::CONFIGURATIONS['discountLevel'] && $this->isManager) {
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

				$form->addInteger('surchargeLevelPct', 'Marže (%)')
					->setHtmlAttribute(
						'data-info',
						'Aplikuje se na všechny ceny zákazníka z ceníků, které mají povoleno "Povolit marži".',
					)
					->setNullable()
					->addCondition($form::Filled)
					->addRule($form::Float);
			}

			if (isset($this::CONFIGURATIONS['rounding']) && $this::CONFIGURATIONS['rounding']) {
				$form->addText('productRoundingPct', 'Zaokrouhlení od procent (%)')->setNullable()->setHtmlType('number')->addCondition($form::FILLED)->addRule(Form::INTEGER);
			}

			if ($this->shopperUser->getShowMaxCustomerOrderPrice()) {
				$form->addFloat('maximumOrderPriceWithoutVat', 'Maximální cena objednávky bez DPH')->setNullable();
				$form->addFloat('maximumOrderPriceWithVat', 'Maximální cena objednávky s DPH')->setNullable();
			}

			$form->addGroup('Exporty');
			$form->addCheckbox('allowExport', 'Feed povolen');

			if ($this::CONFIGURATIONS['edi']) {
				$form->addText('ediCompany', 'EDI: Identifikátor firmy')
					->setHtmlAttribute('Bude použito při exportu objednávky do formátu EDI.');
				$form->addText('ediBranch', 'EDI: Identifikátor pobočky')
					->setHtmlAttribute('Bude použito při exportu objednávky do formátu EDI.');
			}

			$form->addGroup('Cache');
			$form->addText('cacheIndex', 'Index')
				->setDisabled()
				->setDefaultValue($customer ? $this->productsCacheGetterService->getIndexByCustomer($customer) : null);


			$this->addCustomFieldsToCustomerForm($form, $customer);

			if ($customer && isset($form['shop']) && $form['shop'] instanceof SelectBox) {
				$form['shop']->setDisabled();
			}

			$form->addSubmits(!$this->getParameter('customer'));
		});

		$form->onValidate[] = function (AdminForm $form) use ($customer): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues('array');

			if (!isset($values['email'])) {
				return;
			}

			$uniqueValid = true;

			if ($this->onCustomerFormUniqueValidation) {
				$uniqueValid = \call_user_func($this->onCustomerFormUniqueValidation, $values, $form);
			} else {
				$customerQuery = $this->customerRepository->many()->where('email', $values['email']);

				if (isset($values['shop'])) {
					$customerQuery->where('this.fk_shop', $values['shop']);
				}

				$duplicateCustomer = $customerQuery->first();

				if ($duplicateCustomer) {
					if (!$customer || ($customer->getPK() !== $duplicateCustomer->getPK())) {
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

			$merchants = Arrays::pick($values, 'merchants');

			unset($values['merchants']);
			unset($values['accounts']);

			foreach ($values['favouritePriceLists'] as $favouritePriceList) {
				if (Arrays::contains($values['pricelists'], $favouritePriceList)) {
					continue;
				}

				$values['pricelists'][] = $favouritePriceList;
			}

			/** @var \Eshop\DB\Customer $customer */
			$customer = $this->customerRepository->syncOne($values, null, true, false);

			$this->storm->rows(['eshop_merchant_nxn_eshop_customer'])->where('fk_customer', $customer)->delete();

			foreach ($merchants as $merchant) {
				$this->storm->createRow('eshop_merchant_nxn_eshop_customer', ['fk_merchant' => $merchant, 'fk_customer' => $customer->getPK()]);
			}

			$this->flashMessage('Vytvořeno', 'success');
			$form->processRedirect('edit', 'default', [$customer]);
		};
		
		return $form;
	}

	public function createComponentEditAddress(): AdminForm
	{
		$form = $this->formFactory->create();
		
		$form->addGroup('Fakturační adresa');
		$billAddress = $form->addContainer('billAddress');
		$billAddress->addText('name', ' Jméno a příjmení / název firmy');
		$billAddress->addText('companyName', ' Název firmy');
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

		Arrays::invoke($this->onBeforeSubmitEditAddress, $form);
		
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

		$this->template->displayButtons[] = $this->createButton2('importInternalRibbonsCsv', '<i class="fas fa-file-upload mr-1"></i>Importovat interní štítky');
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
		$this->template->displayButtons = [
			$this->createBackButton('default'),
			$this->createButton2('editAddress', 'Adresy', linkArgs: [$this->getParameter('customer')]),
			$this->createButton2('editFavouriteProducts', 'Oblíbené produkty', linkArgs: [$this->getParameter('customer')]),
		];

		if ($this->settingsService->isUsingProductsCache()) {
			$this->template->displayButtons[] = $this->createButton2('refreshCache!', 'Přepočítat cache zákazníka', linkArgs: [$this->getParameter('customer')]);
		}

		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function handleRefreshCache(Customer $customer): void
	{
		$cronService = $this->getCronService->execute();

		if (!$cronService) {
			$this->flashMessage('Nelze spustit cron. Zkontrolujte nastavení.', 'error');
			$this->redirect('this');
		}

		try {
			$cronService->scheduleJob('cache', 'Cache', arguments: [$customer->getPK()]);

			$this->flashMessage('Naplánováno');
		} catch (GuzzleException $e) {
			Debugger::log($e, ILogger::EXCEPTION);
			Debugger::barDump($e);

			$this->flashMessage('Nelze spustit cron. Zkontrolujte nastavení.', 'error');
		} catch (LiquidMonitorDisabledException $e) {
			$this->flashMessage('Nelze spustit cron. Zkontrolujte nastavení.', 'error');

			Debugger::barDump($e);
		}

		$this->redirect('this');
	}

	public function renderEditAddress(): void
	{
		/** @var \Eshop\DB\Customer $customer */
		$customer = $this->getParameter('customer');

		$this->template->headerLabel = 'Adresy - ' . ($customer->company ?: $customer->fullname);
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Adresy'],
		];
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createButton2('edit', 'Zákazník', linkArgs: [$customer])];
		$this->template->displayControls = [$this->getComponent('editAddress')];
	}
	
	public function renderEditAccount(Account $account): void
	{
		$this->template->headerLabel = 'Účet';
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Účet'],
		];
		$this->template->displayButtons = [
			$this->createBackButton('default'),
			$this->createButton('sendResetPasswordLink!', 'Poslat link na změnu hesla', $account),
		];
		$this->template->displayControls = [$this->getComponent('accountForm')];
	}

	public function handleSendResetPasswordLink(Account $account): void
	{
		try {
			$this->lostPasswordService->sendResetLink($account);

			$this->flashMessage('E-mail odeslán', 'success');
		} catch (\Throwable $e) {
			$this->flashMessage($e->getMessage(), 'error');
		}

		$this->redirect('this');
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
				'favouritePriceLists',
				'visibilityLists',
				'exclusivePaymentTypes',
				'exclusiveDeliveryTypes',
				'accounts',
			]) + ['merchants' => $merchants];
		
		if ($customer->loyaltyProgramDiscountLevel) {
			$defaults['loyaltyProgramDiscountLevel'] = (string) $customer->loyaltyProgramDiscountLevel->discountLevel;
		}

		$defaults['lastOrder'] = $customer->lastOrder ? $customer->lastOrder->code : null;
		
		$form->setDefaults($defaults);
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
	}
	
	public function createComponentAccountForm(): AdminForm
	{
		$callback = function (AdminForm $form): void {
			/** @var \Security\DB\Account|null $account */
			$account = $this->getParameter('account');

			$form->addGroup('Oprávnění a zákazník');
			$container = $form->addContainer('permission');
			$customerInput = $container->addSelectAjax('customer', 'Zákazník', '- Vyberte -', Customer::class);

			if ($account) {
				/** @var \Eshop\DB\CatalogPermission|null $permission */
				$permission = $this->catalogPermissionRepo->many()->where('fk_account', $account->getPK())->first();

				if ($permission) {
					$this->template->select2AjaxDefaults[$customerInput->getHtmlId()] = [
						$permission->customer->getPK() => $permission->customer->getName() . ($permission->customer->externalCode ? " ({$permission->customer->externalCode})" : ''),
					];
				}
			}

			$catalogInput = $container->addSelect('catalogPermission', 'Zobrazení', ShopperUser::PERMISSIONS)->setDefaultValue('price');
			
			$catalogInput->addCondition($form::Equal, 'price')
				->toggle('frm-accountForm-permission-showPricesWithoutVat-toogle')
				->toggle('frm-accountForm-permission-showPricesWithVat-toogle');
			
			if (isset($this::CONFIGURATIONS['prices']) && $this::CONFIGURATIONS['prices']) {
				if ($this->shopperUser->getShowWithoutVat()) {
					$withoutVatInput = $container->addCheckbox('showPricesWithoutVat', 'Zobrazit ceny bez daně');
				}
				
				if ($this->shopperUser->getShowVat()) {
					$withVatInput = $container->addCheckbox('showPricesWithVat', 'Zobrazit ceny s daní');
				}
				
				if ($this->shopperUser->getShowWithoutVat() && $this->shopperUser->getShowVat()) {
					$container->addSelect('priorityPrice', 'Prioritní cena', [
						'withoutVat' => 'Bez daně',
						'withVat' => 'S daní',
					])->addConditionOn($catalogInput, $form::Equal, 'price')
						->addConditionOn($withoutVatInput, $form::Equal, true)
						->addConditionOn($withVatInput, $form::Equal, true)
						->toggle('frm-accountForm-permission-priorityPrice-toogle');
				}
			}
			
			$container->addCheckbox('buyAllowed', 'Povolit nákup')->setDefaultValue(true);
			$container->addCheckbox('viewAllOrders', 'Zobrazit všechny objednávky zákazníka')->setDefaultValue(false);
			
			$container = $form->addContainer('newsletter');
			
			$newsletterInput = $container->addCheckbox('newsletter', 'Přihlášen k newsletteru');
			$newsletterGroupsInput = $container->addMultiSelect2('newsletterGroups', 'Skupiny newsletteru', $this->newsletterUserGroupRepository->getArrayForSelect());
			
			$newsletterInput->addCondition($form::Filled)->toggle($newsletterGroupsInput->getHtmlId() . '-toogle');

			$this->addCustomFieldsToAccountForm($form, $account);

			$accountContactInfos = $account?->getAccountContactInfos()->toArray();

			if (!$accountContactInfos) {
				return;
			}

			$form->addGroup('Kontaktní informace');
			$contactInfosContainer = $form->addContainer('contactInfos');

			$i = 0;

			foreach ($accountContactInfos as $accountContactInfo) {
				$contactInfosContainer->addText("info_$i", "Kontakt ($accountContactInfo->type)")->setDisabled()->setDefaultValue($accountContactInfo->value);

				$i++;
			}
		};
		
		return $this->accountFormFactory->create(false, $callback, true, true, $this->getParameter('account'));
	}

	public function renderSendNewPasswordToAccountMultiple(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Odeslat e-mail s novým heslem';
		$this->template->headerTree = [
			['Zákazníci', 'default'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('sendNewPasswordToAccountMultipleForm')];
	}

	public function createComponentSendNewPasswordToAccountMultipleForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('accountGrid'), function (array $values, Collection $collection, AdminForm $form): never {
			$this->sendNewPasswordToAccount($values, $collection);
		}, $this->getBulkFormActionLink(), $this->accountRepository->many(), $this->getBulkFormIds());
	}
	
	public function createComponentAccountGrid(): AdminGrid
	{
		$collection = $this->accountRepository->many()
			->join(['admin' => 'admin_administrator_nxn_security_account'], 'this.uuid = admin.fk_account')
			->join(['merchant' => 'eshop_merchant_nxn_security_account'], 'this.uuid = merchant.fk_account')
			->where('admin.fk_administrator IS NULL')
			->where('merchant.fk_merchant IS NULL')
			->join(['catalogPermission' => 'eshop_catalogpermission'], 'catalogPermission.fk_account = this.uuid')
			->join(['customer' => 'eshop_customer'], 'customer.uuid = catalogPermission.fk_customer')
			->join(['newsletterUser' => 'eshop_newsletteruser'], 'this.uuid = newsletterUser.fk_customerAccount')
			->select([
				'company' => 'customer.company',
				'customerFullname' => 'customer.fullname',
				'customerPK' => 'customer.uuid',
			])
			->select([
				'permission' => 'catalogPermission.catalogPermission',
				'buyAllowed' => 'catalogPermission.buyAllowed',
			]);
		
		$grid = $this->gridFactory->create($collection, 20, 'createdTs', 'DESC', true, filterShops: false);
		$grid->addColumnSelector();
		$grid->addColumnText('Vytvořen', 'tsRegistered|date', '%s', 'tsRegistered', ['class' => 'fit']);
		$grid->addColumnText('Login', 'login', '%s', 'login', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Jméno a příjmení', 'fullname', '%s', 'fullname');
		$grid->addColumn('Zákazník', function (Account $account) {
			if (!$customerPK = $account->getValue('customerPK')) {
				return null;
			}

			/** @var ?\Eshop\DB\Customer $customer */
			$customer = $this->customerRepository->one($customerPK);

			if ($customer === null) {
				return null;
			}

			$hr = '<hr style="margin: 0">';
			$billAddress = $customer->billAddress?->getFullAddress();
			$deliveryAddress = $customer->deliveryAddress?->getFullAddress();
			$externalCode = $customer->externalCode !== null ? " <span style='white-space: nowrap'>({$customer->externalCode})</span>" : '';

			return ($customer->company ?: $customer->fullname) . $externalCode . "$hr<div class='row'><div class='col-6'>$billAddress</div><div class='col-6'>$deliveryAddress</div></div>";
		});
		$grid->addColumn('Oprávnění', function (Account $account) {
			if (!$account->getValue('permission')) {
				return '';
			}
			
			$label = ShopperUser::PERMISSIONS;
			
			return $label[$account->getValue('permission')] . ' + ' . ($account->getValue('buyAllowed') ? 'nákup' : 'bez nákupu');
		});
		
		$grid->addColumnText('Aktivní od', "activeFrom|date:'d.m.Y G:i'", '%s', 'activeFrom', ['class' => 'fit']);
		$grid->addColumnText('Aktivní do', "activeTo|date:'d.m.Y G:i'", '%s', 'activeTo', ['class' => 'fit']);
		$grid->addColumnInputCheckbox('Aktivní', 'active');
		
		if ($this::CONFIGURATIONS['showAuthorized']) {
			$grid->addColumnInputCheckbox('Autorizovaný', 'authorized');
		}

		Arrays::invoke($this->onBeforeAddButtonsAccountsGrid, $grid);
		$this->addCustomFieldsToCustomerGrid($grid);
		
		$btnSecondary = 'btn btn-sm btn-outline-primary';
		$grid->addColumn('Login', function (Account $object, Datagrid $grid) use ($btnSecondary) {
			$link = $grid->getPresenter()->link('loginCustomer!', [$object->login]);
			
			return $object->isActive() ?
				"<a class='$btnSecondary' target='_blank' href='$link'><i class='fa fa-sign-in-alt'></i></a>" :
				"<a class='$btnSecondary disabled' href='#'><i class='fa fa-sign-in-alt'></i></a>";
		}, '%s', null, ['class' => 'minimal']);

		$this->addCustomFieldsToAccountGrid($grid);

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
		
		$submit = $grid->getForm()->addSubmit('permBulkEdit', 'Hromadná úprava')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');
		
		$submit->onClick[] = function () use ($grid): void {
			$grid->getPresenter()->redirect('permBulkEdit', [$grid->getSelectedIds()]);
		};
		
		$submit = $grid->getForm()->addSubmit('downloadEmails', 'Export e-mailů');
		$submit->setHtmlAttribute('class', 'btn btn-sm btn-outline-primary');
		$submit->onClick[] = [$this, 'exportAccounts'];

		$this->addFiltersToAccountsGrid($grid);

		$grid->addFilterButtons();

		if ($this::SHOW_ACCOUNTS_BULK_REGISTER_EMAIL) {
			$grid->addBulkAction('sendNewPasswordToAccountMultiple', 'sendNewPasswordToAccountMultiple', 'Poslat registrační e-mail (nové heslo)');
		}

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
		
		$values->addSelect('catalogPermission', 'Zobrazení', ShopperUser::PERMISSIONS)->setPrompt('Původní');

		if (isset($this::CONFIGURATIONS['prices']) && $this::CONFIGURATIONS['prices']) {
			if ($this->shopperUser->getShowWithoutVat()) {
				$values->addSelect('showPricesWithoutVat', 'Zobrazit ceny bez daně', [
					false => 'Ne',
					true => 'Ano',
				])->setPrompt('Původní');
			}

			if ($this->shopperUser->getShowVat()) {
				$values->addSelect('showPricesWithVat', 'Zobrazit ceny s daní', [
					false => 'Ne',
					true => 'Ano',
				])->setPrompt('Původní');
			}

			if ($this->shopperUser->getShowWithoutVat() && $this->shopperUser->getShowVat()) {
				$values->addSelect('priorityPrice', 'Prioritní cena', [
					'withoutVat' => 'Bez daně',
					'withVat' => 'S daní',
				])->setPrompt('Původní');
			}
		}

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

	public function actionImportInternalRibbonsCsv(): void
	{
		$this->connection->setDebug(false);
	}

	public function renderImportInternalRibbonsCsv(): void
	{
		$this->template->headerLabel = 'Import interních štítkú';
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Import interních štítkú'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('importInternalRibbonsCsvForm')];
	}

	public function createComponentImportInternalRibbonsCsvForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$lastUpdate = null;
		$path = \dirname(__DIR__, 5) . '/userfiles/customerInternalRibbons.csv';

		if (\file_exists($path)) {
			$lastUpdate = \filemtime($path);
		}

		$form->addGroup('CSV soubor');
		$form->addText('lastProductFileUpload', 'Poslední aktualizace souboru')->setDisabled()->setDefaultValue($lastUpdate ? Carbon::createFromTimestamp($lastUpdate)->format('d.m.Y G:i') : null);

		$filePicker = $form->addFilePicker('file', 'Soubor (CSV)')
			->setRequired()
			->addRule($form::MimeType, 'Neplatný soubor!', 'text/csv');

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			'   ' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		]);

		$form->addSubmit('submit', 'Importovat');

		$form->onValidate[] = function (AdminForm $form) use ($filePicker): void {
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $values['file'];

			if ($file->hasFile()) {
				return;
			}

			$filePicker->addError('Neplatný soubor!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $values['file'];

			$dir = \dirname(__DIR__, 5);
			$productsFileName = $dir . '/userfiles/customerInternalRibbons.csv';
			$tempFileName = \tempnam($this->container->getParameter('tempDir'), 'products');

			if (!$tempFileName) {
				throw new \Exception('Cant create temp file');
			}

			$file->move($tempFileName);

			$connection = $this->productRepository->getConnection();
			$connection->getLink()->beginTransaction();

			try {
				$reader = Reader::createFromPath($tempFileName);

				$reader->setDelimiter($values['delimiter']);
				$reader->setHeaderOffset(0);

				foreach ($reader->getRecords() as $record) {
					$record = \array_change_key_case($record);

					$code = $record['kod'];
					$types = $record['typ'];

					/** @var ?\Eshop\DB\Customer $customer */
					$customer = $this->customerRepository->one(['externalCode' => $code]);

					if ($customer === null) {
						Debugger::log(\sprintf('Wasn\'t able to import customer with code %s', $code), ILogger::WARNING);

						continue;
					}

					$typesForCustomer = \array_map(function (string $type) {
						return 'internal_qi_type_' . $type;
					}, \explode(',', $types));

					$customer->internalRibbons->unrelateAll();
					$customer->internalRibbons->relate($typesForCustomer);
				}

				FileSystem::copy($tempFileName, $productsFileName);

				$connection->getLink()->commit();
				$this->flashMessage('Import zákazníků: úspěšný', 'success');
			} catch (\Exception $e) {
				Debugger::barDump($e);

				$connection->getLink()->rollBack();

				$this->flashMessage('Import zákazníků: ' . ($e->getMessage() !== '' ? $e->getMessage() : 'chyba'), 'error');
			}

			$connection->getLink()->beginTransaction();

			try {
				FileSystem::delete($tempFileName);
			} catch (\Exception $e) {
				Debugger::log($e, ILogger::WARNING);
			}

			$this->redirect('this');
		};

		return $form;
	}

	/**
	 * @param array<mixed> $values
	 * @param \StORM\Collection<\Security\DB\Account> $collection
	 */
	protected function sendNewPasswordToAccount(array $values, Collection $collection): never
	{
		unset($values, $collection);

		throw new NotImplementedException();
	}

	/**
	 * @return array<string>
	 */
	protected function getBulkEdits(): array
	{
		 $bulkEdits = ['merchant', 'group'];

		if ($this->isManager) {
			$bulkEdits[] = 'pricelists';
			$bulkEdits[] = 'favouritePriceLists';
			$bulkEdits[] = 'visibilityLists';
			$bulkEdits[] = 'favouriteProducts';
			$bulkEdits[] = 'preferredDeliveryType';
			$bulkEdits[] = 'preferredPaymentType';
			$bulkEdits[] = 'exclusiveDeliveryTypes';
			$bulkEdits[] = 'exclusivePaymentTypes';

			if (isset($this::CONFIGURATIONS['discountLevel']) && $this::CONFIGURATIONS['discountLevel']) {
				$bulkEdits[] = 'discountLevelPct';
				$bulkEdits[] = 'maxDiscountProductPct';
				$bulkEdits[] = 'surchargeLevelPct';
			}
		}

		if ($this->isManager && isset($this::CONFIGURATIONS['loyaltyProgram']) && $this::CONFIGURATIONS['loyaltyProgram']) {
			$bulkEdits[] = 'loyaltyProgram';
		}

		if ($this->shopperUser->getShowMaxCustomerOrderPrice()) {
			$bulkEdits[] = 'maximumOrderPriceWithoutVat';
			$bulkEdits[] = 'maximumOrderPriceWithVat';
		}

		return $bulkEdits;
	}

	protected function addCustomFieldsToCustomerForm(AdminForm $form, Customer|null $customer): void
	{
		unset($form, $customer);
	}

	protected function addCustomFieldsToAccountForm(AdminForm $form, Account|null $account): void
	{
		unset($form, $account);
	}

	protected function addCustomFieldsToCustomerGrid(AdminGrid $grid): void
	{
		unset($grid);
	}

	protected function addCustomFieldsToAccountGrid(AdminGrid $grid): void
	{
		unset($grid);
	}
}
