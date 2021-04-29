<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CountryRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\DeliveryType;
use Eshop\DB\DeliveryTypePrice;
use Eshop\DB\DeliveryTypePriceRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\Shopper;
use Eshop\DB\CustomerGroupRepository;
use Forms\Form;
use Nette\DI\Container;
use Nette\Http\Request;
use Nette\Utils\Image;
use StORM\DIConnection;

class DeliveryTypePresenter extends BackendPresenter
{
	/** @inject */
	public DeliveryTypeRepository $deliveryRepo;
	
	/** @inject */
	public DeliveryTypePriceRepository $deliveryPriceRepo;
	
	/** @inject */
	public CurrencyRepository $currencyRepo;
	
	/** @inject */
	public CountryRepository $countryRepository;
	
	/** @inject */
	public PaymentTypeRepository $paymentTypeRepo;
	
	/** @inject */
	public CustomerGroupRepository $groupRepo;
	
	/** @inject */
	public Shopper $shopper;
	
	/** @inject */
	public Request $request;
	
	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->deliveryRepo->many(), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();
		
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);
		$grid->addColumnImage('imageFileName', DeliveryType::IMAGE_DIR);
		$grid->addColumnText('Název', 'name', '%s', 'name');
		
		$code = $this->currencyRepo->many()->firstValue('uuid');
		$grid->addColumn('Celková cena', function (DeliveryType $deliveryType, AdminGrid $dataGrid) use ($code) {
			/** @var DeliveryTypePrice $price */
			$price = $this->deliveryPriceRepo->many()
				->where('fk_deliveryType', $deliveryType->getPK())
				->where('fk_currency', $code)
				->where('weightTo IS NOT NULL')
				->orderBy(['weightTo'])
				->setTake(1)
				->fetch();
			
			return $price ? $this->shopper->filterPrice($price->priceVat, $code) : '';
		});
		
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');
		
		$grid->addColumnLink('prices', 'Ceník');
		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete();
		
		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();
		
		$grid->addFilterTextInput('search', ['name_cs', 'code'], null, 'Kód, název');
		
		$grid->addFilterButtons();

		$grid->onDelete[] = [$this, 'onDelete'];
		
		return $grid;
	}
	
	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create(true);
		
		$form->addText('code', 'Kód')->setRequired();
		
		/** @var DeliveryType $deliveryType */
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

		$imagePicker->onDelete[] = function (array $directories, $filename) use($deliveryType) {
			$this->onDelete($deliveryType);
			$this->redirect('this');
		};
		
		$form->addLocaleText('name', 'Název');
		$form->addLocalePerexEdit('perex', 'Perex');
		$form->addText('trackingLink', 'Odkaz pro sledování zásilky');
		$form->addDataSelect('exclusive', 'Exkluzivní pro skupinu uživatelů', $this->groupRepo->getListForSelect())->setPrompt('Žádná');
		$form->addDataMultiSelect('allowedPaymentTypes', 'Povolené typy plateb', $this->paymentTypeRepo->many()->toArrayOf('code'))
			->setHtmlAttribute('placeholder', 'Vyberte položky...');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10);
		$form->addCheckbox('recommended', 'Doporučeno');
		$form->addCheckbox('hidden', 'Skryto');
		
		$form->addSubmits(!$deliveryType);
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			$this->createImageDirs(DeliveryType::IMAGE_DIR);
			
			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}
			
			$values['imageFileName'] = $form['imageFileName']->upload($values['uuid'] . '.%2$s');
			
			$deliveryType = $this->deliveryRepo->syncOne($values, null, true);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$deliveryType]);
		};
		
		return $form;
	}
	
	public function renderDefault()
	{
		$this->template->headerLabel = 'Typy dopravy';
		$this->template->headerTree = [
			['Typy dopravy', 'default'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function renderNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Typy dopravy', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function renderDetail(DeliveryType $deliveryType)
	{
		$this->template->headerLabel = 'Detail typu dopravy';
		$this->template->headerTree = [
			['Typy dopravy', 'default'],
			['Detail typu dopravy'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function actionDetail(DeliveryType $deliveryType)
	{
		/** @var Form $form */
		$form = $this->getComponent('newForm');
		
		$form->setDefaults($deliveryType->toArray(['allowedPaymentTypes']));
	}
	
	public function createComponentPricesGrid()
	{
		$collection = $this->deliveryPriceRepo->many()->where('fk_deliveryType', $this->getParameter('deliveryType')->getPK())
			->select(['rate' => 'rates.rate'])
			->join(['country' => 'eshop_country'], 'country.uuid = this.fk_country')
			->join(['rates' => 'eshop_vatrate'], 'rates.uuid = country.deliveryVatRate AND rates.fk_country=this.fk_country');
		
		$grid = $this->gridFactory->create($collection, 20, 'price', 'ASC');
		$grid->addColumnSelector();
		
		$grid->addColumnInputPrice('Cena', 'price');
		$grid->addColumnInputPrice('Cena s DPH', 'priceVat');
		$grid->addColumnInputFloat('Dostupné do váhy kg (včetně)', 'weightTo', '', '', 'weightTo');
		
		$grid->addColumnText('Měna', 'currency.code', '%s');
		
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll(['weightTo'], [], 'this.uuid');
		$grid->addButtonDeleteSelected();
		
		$grid->addFilterSelectInput('search', 'fk_currency = :q', 'Měna', '- Měna -', null, $this->currencyRepo->getArrayForSelect());
		
		$grid->addFilterButtons(['prices', $this->getParameter('deliveryType')]);
		
		return $grid;
	}
	
	public function createComponentPricesForm()
	{
		$form = $this->formFactory->create();
		$form->addSelect('currency', 'Měna', $this->currencyRepo->getArrayForSelect());
		$form->addSelect('country', 'Země DPH', $this->countryRepository->getArrayForSelect());
		
		$form->addText('price', 'Cena')->addRule($form::FLOAT)->setRequired();
		$form->addText('priceVat', 'Cena s DPH')->addRule($form::FLOAT)->setRequired();
		$form->addText('weightTo', 'Dostupné do váhy kg (včetně)')->setNullable(true)->addCondition(Form::FILLED)->addRule($form::FLOAT);
		$form->addHidden('deliveryType', (string) $this->getParameter('deliveryType'));
		
		$form->addSubmits();
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			$this->deliveryPriceRepo->syncOne($values, null, true);
			
			$this->flashMessage('Vytvořeno', 'success');
			$form->processRedirect('this', 'prices', [$this->getParameter('deliveryType')], [$this->getParameter('deliveryType')]);
		};
		
		return $form;
	}
	
	public function renderPrices(DeliveryType $deliveryType)
	{
		$this->template->headerLabel = 'Ceník typu dopravy - ' . $deliveryType->name;
		$this->template->headerTree = [
			['Typy dopravy', 'default'],
			['Ceník typu dopravy'],
		];
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('pricesNew', [$deliveryType])];
		$this->template->displayControls = [$this->getComponent('pricesGrid')];
	}
	
	public function renderPricesNew(DeliveryType $deliveryType)
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