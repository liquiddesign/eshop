<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Admin\Controls\AccountFormFactory;
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
use Eshop\Integration\MailerLite;
use Forms\Form;
use Grid\Datagrid;
use League\Csv\Writer;
use Messages\DB\TemplateRepository;
use Nette\Application\Responses\FileResponse;
use Nette\Forms\Controls\Button;
use Nette\Mail\Mailer;
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

	public function createComponentCustomers()
	{
		$grid = $this->gridFactory->create($this->customerRepository->many(), 20, 'createdTs', 'DESC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Registrace', "createdTs|date", '%s', 'createdTs', ['class' => 'fit']);
		$grid->addColumnText('Login', 'account.login', '%s', 'account.login', ['class' => 'fit']);
		$grid->addColumnText('Jméno a příjmení', 'fullname', '%s', 'fullname');
		$grid->addColumnText('Obchodník', 'merchant.fullname', '%s', 'merchant.fullname');
		$grid->addColumnText('Skupina', 'group.name', '%s', 'group.name');
		$grid->addColumnText('Telefon', 'phone', '<a href="tel:%1$s"><i class="fa fa-phone-alt"></i> %1$s</a>')->onRenderCell[] = [$grid, 'decoratorEmpty'];
		$grid->addColumnText('Email', 'email', '<a href="mailto:%1$s"><i class="far fa-envelope"></i> %1$s</a>')->onRenderCell[] = [$grid, 'decoratorEmpty'];

		$btnSecondary = 'btn btn-sm btn-outline-primary';
		$grid->addColumn('Feed', function (Customer $customer) use ($btnSecondary) {
			return "<a class='$btnSecondary' target='_blank' href='" . $this->link('//:Eshop:Export:supplier', $customer->getPK()) . "'><i class='fa fa-sm fa-rss'></i></a>";
		}, '%s', null, ['class' => 'minimal']);

		$grid->addColumn('Login', function (Customer $object, Datagrid $grid) use ($btnSecondary) {
			$link = $object->getAccount() ? $grid->getPresenter()->link('loginCustomer!', [$object->getAccount()->login]) : '#';

			return "<a class='" . ($object->getAccount() ? '' : 'disabled') . " $btnSecondary' target='_blank' href='$link'><i class='fa fa-sign-in-alt'></i></a>";
		}, '%s', null, ['class' => 'minimal']);

		$grid->addColumn('', function (Customer $object, Datagrid $datagrid) use ($btnSecondary) {
			return $object->getAccount() != null ?
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('editAccount', $object) . "'>Detail&nbsp;účtu</a>" :
				"<a class='$btnSecondary' href='" . $datagrid->getPresenter()->link('newAccount', $object) . "'>Vytvořit&nbsp;účet</a>";
		}, '%s', null, ['class' => 'minimal']);

		$grid->addColumnLink('editAddress', 'Adresy');
		$grid->addColumnLinkDetail('edit');

		$grid->addColumnActionDelete([$this->accountFormFactory, 'deleteAccountHolder']);

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected([$this->accountFormFactory, 'deleteAccountHolder']);

		$grid->addButtonBulkEdit('form', ['pricelists', 'merchant', 'group'], 'customers');

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

	public function actionEditAccount(Customer $customer): void
	{
		/** @var Form $form */
		$form = $this->getComponent('accountForm');
		$form['account']['email']->setDefaultValue($customer->email);
		$form['account']->setDefaults($customer->account->toArray());

		$this->accountFormFactory->onDeleteAccount[] = function () {
			$this->flashMessage('Účet byl smazán', 'success');
			$this->redirect('default');
		};
	}

	public function actionNewAccount(Customer $customer)
	{
		$form = $this->getComponent('accountForm');
		$form['account']['password']->setRequired();
		unset($form['delete']);

		$this->accountFormFactory->onCreateAccount[] = function (Account $account) use ($customer) {
			$customer->update(['account' => $account]);

			$this->flashMessage('Účet byl vytvořen', 'success');
			$this->redirect('default');
		};
	}

	public function renderNewAccount(Customer $customer): void
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
		$form->addText('phone', 'Telefon');
		$form->addText('company', 'Firma');
		$form->addText('ic', 'IČ');
		$form->addText('dic', 'DIČ');
		$form->addText('email', 'E-mail')->addRule($form::EMAIL)->setRequired();
		$form->addText('ccEmails', 'Kopie emailů')->setHtmlAttribute('data-info', 'Zadejte emailové adresy oddělené středníkem (;).');
		$form->addCheckbox('newsletter', 'Přihlášen k newsletteru');

		$form->addDataMultiSelect('pricelists', 'Ceníky', $this->pricelistRepo->many()->toArrayOf('name'))
			->setHtmlAttribute('placeholder', 'Vyberte položky...');
		$form->addDataSelect('merchant', 'Obchodník', $this->merchantRepository->getListForSelect())->setPrompt('Žádná');
		$form->addDataSelect('group', 'Skupina', $this->groupsRepo->getRegisteredGroupsArray())->setPrompt('Žádná');
		$form->addGroup('Nákup a preference');
		$form->addSelect('catalogPermission', 'Oprávnění: katalog', [
			'none' => 'Žádné',
			'catalog' => 'Katalogy',
			'price' => 'Ceny',
			'full' => 'Plné',
		]);
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

		if (!$this->getParameter('customer')) {
			$form->addGroup('Účet');
			$this->accountFormFactory->addContainer($form);
		}

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

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Zákazníci';
		$this->template->headerTree = [
			['Zákazníci', 'default'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('customers')];
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

	public function renderEditAccount(Customer $customer): void
	{
		$this->template->headerLabel = 'Detail účtu zákazníka - ' . $customer->fullname;
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Detail účtu'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('accountForm')];
	}

	public function actionNew()
	{
		/** @var Form $form */
		$form = $this->getComponent('form');
		$form['account']['password']->setRequired();
		$form['account']['passwordCheck']->setRequired();

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			$form['account']['email']->setValue($values['email']);

			unset($values['account']);

			$customer = $this->customerRepository->syncOne($values, null, true);
			$this->accountFormFactory->onCreateAccount[] = function ($account) use ($customer) {
				$customer->update(['account' => $account]);
			};
			$this->accountFormFactory->success($form, 'register.successAdmin');


			$this->flashMessage('Vytvořeno', 'success');
			$form->processRedirect('edit', 'default', [$customer]);
		};
	}

	public function actionEdit(Customer $customer)
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');

		$form->setDefaults($customer->toArray(['pricelists', 'exclusivePaymentTypes', 'exclusiveDeliveryTypes']));

		$form->onSuccess[] = function (AdminForm $form) use ($customer) {
			$values = $form->getValues('array');

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
		return $this->accountFormFactory->create();
	}
}