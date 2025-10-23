<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CountryRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\DeliveryType;
use Eshop\DB\DeliveryTypePriceRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\DeliveryTypeThreshold;
use Eshop\DB\DeliveryTypeThresholdRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\PickupPointTypeRepository;
use Eshop\DB\SupplierDeliveryTypeRepository;
use Eshop\DB\SupplierRepository;
use Eshop\ShopperUser;
use Forms\Form;
use Nette\Application\Attributes\Persistent;
use Nette\DI\Attributes\Inject;
use Nette\Http\Request;
use Nette\Utils\Arrays;
use Nette\Utils\Html;
use Nette\Utils\Image;
use StORM\DIConnection;
use StORM\ICollection;

class DeliveryTypePresenter extends BackendPresenter
{
	public const TABS = [
		'deliveries' => 'Typy dopravy',
		'thresholds' => 'Časové prahy',
	];

	#[Persistent]
	public string $tab = 'deliveries';

	#[Inject]
	public DeliveryTypeRepository $deliveryRepo;

	#[Inject]
	public DeliveryTypePriceRepository $deliveryPriceRepo;

	#[Inject]
	public CurrencyRepository $currencyRepo;

	#[Inject]
	public CountryRepository $countryRepository;

	#[Inject]
	public PaymentTypeRepository $paymentTypeRepo;

	#[Inject]
	public CustomerGroupRepository $groupRepo;

	#[Inject]
	public PickupPointTypeRepository $pointTypeRepo;

	#[Inject]
	public ShopperUser $shopperUser;

	#[Inject]
	public Request $request;

	#[Inject]
	public SupplierRepository $supplierRepository;

	#[Inject]
	public SupplierDeliveryTypeRepository $supplierDeliveryTypeRepository;

	#[Inject]
	public DisplayDeliveryRepository $displayDeliveryRepository;

	#[Inject]
	public DeliveryTypeThresholdRepository $deliveryTypeThresholdRepository;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->deliveryRepo->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);
		$grid->addColumnImage('imageFileName', DeliveryType::IMAGE_DIR);
		$grid->addColumnText('Název', 'name', '%s', 'name');

//		$code = $this->currencyRepo->many()->firstValue('uuid');
//		$grid->addColumn('Celková cena', function (DeliveryType $deliveryType, AdminGrid $dataGrid) use ($code) {
//			/** @var \Eshop\DB\DeliveryTypePrice|null $price */
//			$price = $this->deliveryPriceRepo->many()
//				->where('fk_deliveryType', $deliveryType->getPK())
//				->where('fk_currency', $code)
//				->where('weightTo IS NOT NULL')
//				->orderBy(['weightTo'])
//				->setTake(1)
//				->first();
//
//			return $price ? $this->shopperUser->filterPrice($price->priceVat, $code) : '';
//		});

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLink('prices', 'Ceník');
		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function (DeliveryType $deliveryType): bool {
			return !$deliveryType->isSystemic();
		}, 'this.uuid');

		$grid->addFilterTextInput('search', ['name_cs', 'code'], null, 'Kód, název');

		$grid->addFilterButtons();

		$grid->onDelete[] = [$this, 'onDelete'];

		return $grid;
	}

	public function createComponentThresholdGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->deliveryTypeThresholdRepository->many(), 20, 'time', 'ASC', true, useShops: false);

		$grid->addColumnSelector();

		$grid->addColumnText('Typ dopravy', 'deliveryType.name', '%s', 'deliveryType.name_cs',);
		$grid->addColumnInputTime('Časový práh', 'time', '', '', 'time');
		$grid->addColumnInputCheckbox('Pondělí', 'monday', 'monday', 'monday');
		$grid->addColumnInputCheckbox('Úterý', 'tuesday', 'tuesday', 'tuesday');
		$grid->addColumnInputCheckbox('Středa', 'wednesday', 'wednesday', 'wednesday');
		$grid->addColumnInputCheckbox('Čtvrtek', 'thursday', 'thursday', 'thursday');
		$grid->addColumnInputCheckbox('Pátek', 'friday', 'friday', 'friday');
		$grid->addColumnInputCheckbox('Sobota', 'saturday', 'saturday', 'saturday');
		$grid->addColumnInputCheckbox('Neděle', 'sunday', 'sunday', 'sunday');

		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(sourceIdName: 'this.uuid');

		$grid->addFilterDataSelect(function (ICollection $source, $value): void {
			$source->where('this.fk_deliveryType', $value);
		}, '', 'deliveryType', null, $this->deliveryRepo->getArrayForSelect())->setPrompt('- Typ dopravy -');

		$grid->addFilterButtons();

		$grid->onDelete[] = [$this, 'onDelete'];

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create(true, useShops: true);

		$form->addText('code', 'Kód')->setRequired();

		/** @var \Eshop\DB\DeliveryType|null $deliveryType */
		$deliveryType = $this->getParameter('deliveryType');

		$imagePicker = $form->addImagePicker('imageFileName', 'Obrázek', [
			DeliveryType::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			DeliveryType::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			DeliveryType::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);

		$imagePicker->onDelete[] = function (array $directories, $filename) use ($deliveryType): void {
			$this->onDelete($deliveryType);
			$this->redirect('this');
		};

		$form->addLocaleText('name', 'Název');
		$form->addLocalePerexEdit('perex', 'Perex');
		$form->addLocalePerexEdit('instructions', 'Instrukce (např. do emailu)');
		$form->addText('trackingLink', 'Odkaz pro sledování zásilky');
		$form->addDataSelect('defaultDisplayDelivery', 'Výchozí zobrazované doručení', $this->displayDeliveryRepository->getArrayForSelect())->setPrompt('-- Žádné --');
		$form->addDataSelect('exclusive', 'Exkluzivní pro skupinu uživatelů', $this->groupRepo->getArrayForSelect())->setPrompt('-- Žádná --');
		$form->addDataSelect('pickupPointType', 'Typ výdejních míst', $this->pointTypeRepo->getArrayForSelect())->setPrompt('-- Žádný --');
		$form->addDataMultiSelect('allowedPaymentTypes', 'Povolené typy plateb', $this->paymentTypeRepo->getArrayForSelect())
			->setHtmlAttribute('placeholder', 'Vyberte položky...');


		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('externalCarrier', 'Externí dopravce');
		$form->addCheckbox('recommended', 'Doporučeno');
		$form->addCheckbox('hidden', 'Skryto');
		$form->addIntegerNullable('daysToDelivery', 'Počet dní doručení')->setHtmlAttribute('data-info', 'Pouze pracovní dny');
		$form->addIntegerNullable('daysFromThresholdToExpedition', 'Počet dní od prahu k expedici')->setHtmlAttribute('data-info', 'Pouze pracovní dny');
		$form->addIntegerNullable('totalMaxWeight', 'Maximální celková váha objednávky');

		$form->addGroup('Maximální přepravní jednotka (na 1 balík)');
		$form->addText('maxWeight', 'Váha')->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addIntegerNullable('maxWidth', 'Šírka');
		$form->addIntegerNullable('maxLength', 'Délka');
		$form->addIntegerNullable('maxDepth', 'Hloubka');

		$form->addGroup('Ostatní');
		$form->addCheckbox('exportToFeed', 'Poskytovat v XML feedech');
		$form->addText('externalId', 'Externí ID: Obecné')->setNullable();
		$form->addText('externalIdHeureka', 'Externí ID: Heuréka.cz')->setNullable();
		$form->addText('externalIdZbozi', 'Externí ID: Zboží.cz')->setNullable();

		$suppliersContainer = $form->addContainer('suppliers');

		$shops = $this->shopsConfig->getAvailableShops();

		foreach ($shops as $shop) {
			$shopContainer = $suppliersContainer->addContainer($shop->getPK());

			/** @var \Eshop\DB\Supplier $supplier */
			foreach ($this->supplierRepository->many() as $supplierPK => $supplier) {
				$shopName = $shop->getIconImageFormAdmin();
				$shopContainer->addText((string) $supplierPK, Html::fromHtml("$shopName Externí ID: $supplier->name"))->setNullable();
			}
		}

		if (!$shops) {
			$shopContainer = $suppliersContainer->addContainer('default');

			/** @var \Eshop\DB\Supplier $supplier */
			foreach ($this->supplierRepository->many() as $supplierPK => $supplier) {
				$shopContainer->addText((string) $supplierPK, Html::fromHtml("Externí ID: $supplier->name"))->setNullable();
			}
		}

		$form->addSubmits(!$deliveryType);

		$form->onSuccess[] = function (AdminForm $form): void {
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			$this->createImageDirs(DeliveryType::IMAGE_DIR);

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			/** @var \Forms\Controls\UploadImage $upload */
			$upload = $form['imageFileName'];

			$values['imageFileName'] = $upload->upload($values['uuid'] . '.%2$s');

			$supplierExternalIDs = Arrays::pick($values, 'suppliers', []);

			$deliveryType = $this->deliveryRepo->syncOne($values, null, true);

			$this->supplierDeliveryTypeRepository->many()->where('this.fk_deliveryType', $deliveryType->getPK())->delete();

			foreach ($supplierExternalIDs as $shop => $suppliers) {
				foreach ($suppliers as $supplierPK => $externalID) {
					if ($externalID === null) {
						continue;
					}

					$this->supplierDeliveryTypeRepository->syncOne([
						'deliveryType' => $deliveryType->getPK(),
						'supplier' => $supplierPK,
						'externalId' => $externalID,
						'shop' => $shop === 'default' ? null : $shop,
					]);
				}
			}

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$deliveryType]);
		};

		return $form;
	}

	public function createComponentThresholdForm(): Form
	{
		$form = $this->formFactory->create(true);

		/** @var \Eshop\DB\DeliveryTypeThreshold|null $deliveryTypeThreshold */
		$deliveryTypeThreshold = $this->getParameter('deliveryTypeThreshold');

		$form->addText('time', 'Časový práh')->setHtmlType('time')->setRequired();

		$form->addCheckbox('monday', 'Pondělí')->setDefaultValue(true);
		$form->addCheckbox('tuesday', 'Úterý')->setDefaultValue(true);
		$form->addCheckbox('wednesday', 'Středa')->setDefaultValue(true);
		$form->addCheckbox('thursday', 'Čtvrtek')->setDefaultValue(true);
		$form->addCheckbox('friday', 'Pátek')->setDefaultValue(true);
		$form->addCheckbox('saturday', 'Sobota');
		$form->addCheckbox('sunday', 'Neděle');

		$form->addDataSelect('deliveryType', 'Typ dopravy', $this->deliveryRepo->getArrayForSelect())
			->setPrompt('')
			->setRequired();

		$form->addSubmits(!$deliveryTypeThreshold);

		$form->onSuccess[] = function (AdminForm $form): void {
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			$deliveryTypeThreshold = $this->deliveryTypeThresholdRepository->syncOne($values, ignore: false);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('thresholdDetail', 'default', [$deliveryTypeThreshold]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->tabs = self::TABS;
		$this->template->headerLabel = 'Typy dopravy';
		$this->template->headerTree = [
			['Typy dopravy', 'default'],
		];

		if ($this->tab === 'deliveries') {
			$this->template->displayButtons = [$this->createNewItemButton('new')];
			$this->template->displayControls = [$this->getComponent('grid')];
		} elseif ($this->tab === 'thresholds') {
			$this->template->displayButtons = [$this->createNewItemButton('thresholdNew')];
			$this->template->displayControls = [$this->getComponent('thresholdGrid')];
		}
	}

	public function renderThresholdNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Typy dopravy', 'default'],
			['Časové prahy', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('thresholdForm')];
	}

	public function renderThresholdDetail(DeliveryTypeThreshold $deliveryTypeThreshold): void
	{
		unset($deliveryTypeThreshold);

		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Typy dopravy', 'default'],
			['Časové prahy', 'default'],
			['detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('thresholdForm')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Typy dopravy', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(DeliveryType $deliveryType): void
	{
		unset($deliveryType);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Typy dopravy', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(DeliveryType $deliveryType): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');

		$defaults = $deliveryType->toArray(['allowedPaymentTypes']);
		$suppliersDefaultsCollection = $this->supplierDeliveryTypeRepository->many()
			->where('this.fk_deliveryType', $deliveryType->getPK());

		foreach ($suppliersDefaultsCollection as $supplierDeliveryType) {
			$shop = $supplierDeliveryType->shop?->getPK() ?? 'default';

			$defaults['suppliers'][$shop][$supplierDeliveryType->supplier->getPK()] = $supplierDeliveryType->externalId;
		}

		$form->setDefaults($defaults);
	}

	public function createComponentPricesGrid(): AdminGrid
	{
		$collection = $this->deliveryPriceRepo->many()->where('fk_deliveryType', $this->getParameter('deliveryType')->getPK())
			->select(['rate' => 'rates.rate'])
			->join(['country' => 'eshop_country'], 'country.uuid = this.fk_country')
			->join(['rates' => 'eshop_vatrate'], 'rates.uuid = country.deliveryVatRate AND rates.fk_country=this.fk_country');

		$grid = $this->gridFactory->create($collection, 20, 'weightTo', 'ASC');
		$grid->addColumnSelector();

		$grid->addColumnInputPrice('Cena', 'price');

		$saveAllTypes = [
			'price' => 'float',
		];

		if ($this->shopperUser->getShowVat()) {
			$grid->addColumnInputPrice('Cena s DPH', 'priceVat');

			$saveAllTypes += ['priceVat' => 'float'];
		}

		$grid->addColumnInputFloat('Dostupné do váhy kg (včetně)', 'weightTo', '', '', 'weightTo');
		$grid->addColumnInputFloat('Dostupné do rozměru (včetně)', 'dimensionTo', '', '', 'dimensionTo');

		$grid->addColumnText('Měna', 'currency.code', '%s');

		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll(['weightTo', 'dimensionTo'], $saveAllTypes, 'this.uuid', false, null, null, false);
		$grid->addButtonDeleteSelected(null, false, null, 'this.uuid');

		$grid->addFilterSelectInput('search', 'fk_currency = :q', 'Měna', '- Měna -', null, $this->currencyRepo->getArrayForSelect());

		$grid->addFilterButtons(['prices', $this->getParameter('deliveryType')]);

		return $grid;
	}

	public function createComponentPricesForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addSelect('currency', 'Měna', $this->currencyRepo->getArrayForSelect());
		$form->addSelect('country', 'Země DPH', $this->countryRepository->getArrayForSelect());

		$form->addText('price', 'Cena')->addRule($form::FLOAT)->setRequired();
		$form->addText('priceVat', 'Cena s DPH')->addRule($form::FLOAT)->setRequired();
		$form->addText('weightTo', 'Dostupné do váhy kg (včetně)')->setNullable(true)->addCondition(Form::FILLED)->addRule($form::FLOAT);
		$form->addText('dimensionTo', 'Dostupné do rozměru (včetně)')->setNullable(true)->addCondition(Form::FILLED)->addRule($form::FLOAT);
		$form->addHidden('deliveryType', (string) $this->getParameter('deliveryType'));

		$form->addSubmits();

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$this->deliveryPriceRepo->syncOne($values, null, true);

			$this->flashMessage('Vytvořeno', 'success');
			$form->processRedirect('this', 'prices', [$this->getParameter('deliveryType')], [$this->getParameter('deliveryType')]);
		};

		return $form;
	}

	public function renderPrices(DeliveryType $deliveryType): void
	{
		$this->template->headerLabel = 'Ceník typu dopravy - ' . $deliveryType->name;
		$this->template->headerTree = [
			['Typy dopravy', 'default'],
			['Ceník typu dopravy'],
		];
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('pricesNew', [$deliveryType])];
		$this->template->displayControls = [$this->getComponent('pricesGrid')];
	}

	public function renderPricesNew(DeliveryType $deliveryType): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Typy dopravy', 'default'],
			['Ceník typu dopravy', ':Eshop:Admin:DeliveryType:prices', $deliveryType],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton(':Eshop:Admin:DeliveryType:prices', $deliveryType)];
		$this->template->displayControls = [$this->getComponent('pricesForm')];
	}
}
