<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CountryRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\PaymentType;
use Eshop\DB\PaymentTypePriceRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\SupplierPaymentTypeRepository;
use Eshop\DB\SupplierRepository;
use Eshop\ShopperUser;
use Nette\Http\Request;
use Nette\Utils\Arrays;
use Nette\Utils\Image;
use StORM\DIConnection;

class PaymentTypePresenter extends BackendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public PaymentTypeRepository $paymentTypeRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public PaymentTypePriceRepository $paymentPriceRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public CurrencyRepository $currencyRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public CountryRepository $countryRepository;
	
	#[\Nette\DI\Attributes\Inject]
	public CustomerGroupRepository $groupRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public Request $request;
	
	#[\Nette\DI\Attributes\Inject]
	public ShopperUser $shopperUser;

	#[\Nette\DI\Attributes\Inject]
	public SupplierRepository $supplierRepository;

	#[\Nette\DI\Attributes\Inject]
	public SupplierPaymentTypeRepository $supplierPaymentTypeRepository;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->paymentTypeRepository->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();
		
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);
		$grid->addColumnImage('imageFileName', PaymentType::IMAGE_DIR);
		$grid->addColumnText('Název', 'name', '%s', 'name');
		
		$code = $this->currencyRepo->many()->firstValue('uuid');
		$grid->addColumn("Celková cena ($code)", function (PaymentType $paymentType, AdminGrid $dataGrid) use ($code) {
			/** @var \Eshop\DB\PaymentTypePrice|null $price */
			$price = $this->paymentPriceRepo->one(['fk_paymentType' => $paymentType->getPK(), 'fk_currency' => $code]);
			
			return $price ? $this->shopperUser->filterPrice($price->priceVat, $code) : '';
		});
		
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');
		
		$grid->addColumnLink('prices', 'Ceník');
		
		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDeleteSystemic();
		
		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function ($object) {
			if ($object) {
				return !$object->isSystemic();
			}

			return false;
		});
		
		$grid->addFilterTextInput('search', ['name_cs', 'code'], null, 'Kód, název');
		
		$grid->addFilterButtons();

		$grid->onDelete[] = [$this, 'onDelete'];

		return $grid;
	}
	
	public function createComponentPaymentTypeForm(): AdminForm
	{
		$form = $this->formFactory->create(true);
		
		/** @var \Eshop\DB\PaymentType|null $paymentType */
		$paymentType = $this->getParameter('paymentType');
		
		$form->addText('code', 'Kód')->setRequired();
		
		$imagePicker = $form->addImagePicker('imageFileName', 'Obrázek', [
			PaymentType::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			PaymentType::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			PaymentType::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);

		$imagePicker->onDelete[] = function (array $directories, $filename) use ($paymentType): void {
			$this->onDelete($paymentType);
			$this->redirect('this');
		};
		
		$form->addLocaleText('name', 'Název');
		$form->addLocalePerexEdit('perex', 'Popisek');
		$form->addLocalePerexEdit('instructions', 'Instrukce (např. do emailu)');
		$form->addDataSelect('exclusive', 'Exkluzivní pro skupinu zákazníků', $this->groupRepo->getArrayForSelect())->setPrompt('Žádná');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('recommended', 'Doporučeno');
		$form->addCheckbox('hidden', 'Skryto');

		$form->addText('comgateMethod', 'Polovené metody pro Comgate')
			->setNullable()
			->setHtmlAttribute('data-info', 'Zadejte povolené metody plateb.<br>
Např.: "BANK_CZ_CS_P+BANK_CZ_KB-BANK_CZ_RB". Více viz: https://help.comgate.cz/docs/api-protokol');
		$form->addGroup('Externí ID');
		$form->addText('externalId', 'Externí ID: Obecné')->setNullable();
		$suppliersContainer = $form->addContainer('suppliers');

		/** @var \Eshop\DB\Supplier $supplier */
		foreach ($this->supplierRepository->many() as $supplierPK => $supplier) {
			$suppliersContainer->addText($supplierPK, " Externí ID: $supplier->name")->setNullable();
		}

		$this->formFactory->addShopsContainerToAdminForm($form);

		$form->addSubmits(!$paymentType);
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');
			
			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}
			
			$this->createImageDirs(PaymentType::IMAGE_DIR);

			/** @var \Forms\Controls\UploadImage $upload */
			$upload = $form['imageFileName'];

			$values['imageFileName'] = $upload->upload($values['uuid'] . '.%2$s');

			$supplierExternalIDs = Arrays::pick($values, 'suppliers', []);
			
			$paymentType = $this->paymentTypeRepository->syncOne($values, null, true);

			$this->supplierPaymentTypeRepository->many()->where('this.fk_paymentType', $paymentType->getPK())->delete();

			foreach ($supplierExternalIDs as $supplierPK => $externalID) {
				if ($externalID === null) {
					continue;
				}

				$this->supplierPaymentTypeRepository->syncOne([
					'paymentType' => $paymentType->getPK(),
					'supplier' => $supplierPK,
					'externalId' => $externalID,
				]);
			}
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$paymentType]);
		};
		
		return $form;
	}
	
	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Typy plateb';
		$this->template->headerTree = [
			['Typy plateb'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Typy plateb', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('paymentTypeForm')];
	}
	
	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Typy plateb', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('paymentTypeForm')];
	}
	
	public function actionDetail(PaymentType $paymentType): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('paymentTypeForm');

		$defaults = $paymentType->toArray();
		$defaults['suppliers'] = $this->supplierPaymentTypeRepository->many()
			->where('this.fk_paymentType', $paymentType->getPK())
			->setIndex('this.fk_supplier')
			->toArrayOf('externalId');
		
		$form->setDefaults($defaults);
	}
	
	public function createComponentPricesGrid(): AdminGrid
	{
		$collection = $this->paymentPriceRepo->many()->where('fk_paymentType', $this->getParameter('paymentType')->getPK())
			->select(['rate' => 'rates.rate'])
			->join(['country' => 'eshop_country'], 'country.uuid = this.fk_country')
			->join(['rates' => 'eshop_vatrate'], 'rates.uuid = country.paymentVatRate AND rates.fk_country=this.fk_country');
		
		$grid = $this->gridFactory->create($collection, 20, 'price', 'ASC');
		$grid->addColumnSelector();
		
		$grid->addColumnText('Měna', 'currency.code', '%s');

		$grid->addColumnInputPrice('Cena', 'price');

		$saveAllTypes = [
			'price' => 'float',
		];

		if ($this->shopperUser->getShowVat()) {
			$grid->addColumnInputPrice('Cena s DPH', 'priceVat');

			$saveAllTypes += ['priceVat' => 'float'];
		}
		
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll([], $saveAllTypes, 'this.uuid', false, null, null, false);
		$grid->addButtonDeleteSelected();
		
		return $grid;
	}
	
	public function createComponentPricesForm(): AdminForm
	{
		$availableCurrencies = $this->getAvailableCurrencies();
		
		if (!$availableCurrencies) {
			$this->redirect('prices', $this->getParameter('paymentType'));
		}
		
		$form = $this->formFactory->create();
		$form->addSelect('currency', 'Měna', $availableCurrencies);
		$form->addSelect('country', 'Země DPH', $this->countryRepository->getArrayForSelect());
		$form->addText('price', 'Cena')->addRule($form::FLOAT)->setRequired();
		$form->addText('priceVat', 'Cena s DPH')->addRule($form::FLOAT)->setRequired();
		$form->addHidden('paymentType', (string) $this->getParameter('paymentType'));

		$form->addSubmits();
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$this->paymentPriceRepo->syncOne($values, null, true);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('this', 'prices', [$this->getParameter('paymentType')], [$this->getParameter('paymentType')]);
		};
		
		return $form;
	}
	
	public function renderPrices(PaymentType $paymentType): void
	{
		$this->template->headerLabel = 'Ceník typu platby - ' . $paymentType->name;
		$this->template->headerTree = [
			['Typy platby', 'default'],
			['Ceník typu platby'],
		];
		
		$this->template->displayButtons = [
			$this->createBackButton('default'),
			\count($this->getAvailableCurrencies()) > 0 ?
				$this->createNewItemButton('pricesNew', [$paymentType]) :
				"<button class='btn btn-success btn-sm' disabled><i class='fa fa-sm fa-plus m-1'></i>Nová položka</button>",
		];
		
		$this->template->displayControls = [$this->getComponent('pricesGrid')];
	}

	public function renderPricesNew(PaymentType $paymentType): void
	{
		$this->template->headerLabel = 'Ceník typu platby';
		$this->template->headerTree = [
			['Typy platby', 'default'],
			['Ceník typu platby', ':Eshop:Admin:PaymentType:prices', $paymentType],
			['Nová cena'],
		];
		$this->template->displayButtons = [$this->createBackButton(':Eshop:Admin:PaymentType:prices', $paymentType)];
		$this->template->displayControls = [$this->getComponent('pricesForm')];
	}

	/**
	 * @return array<\Eshop\DB\Currency>
	 */
	private function getAvailableCurrencies(): array
	{
		$usedCurrencies = \array_keys($this->currencyRepo->many()
			->join(['nxn' => 'eshop_paymenttypeprice'], 'this.uuid=nxn.fk_currency')
			->where('nxn.fk_paymentType', $this->getParameter('paymentType')->getPK())
			->toArray());
		
		return $this->currencyRepo->many()
			->whereNot('uuid', $usedCurrencies)
			->toArray();
	}
}
