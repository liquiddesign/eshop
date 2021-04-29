<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Eshop\FormValidators;
use Admin\Controls\AdminForm;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\DeliveryDiscountRepository;
use Eshop\DB\Discount;
use Eshop\DB\DiscountCoupon;
use Eshop\DB\DiscountCouponRepository;
use Eshop\DB\DiscountRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\TagRepository;
use Eshop\DB\CustomerRepository;
use Forms\Form;
use Grid\Datagrid;
use StORM\Connection;

class DiscountPresenter extends BackendPresenter
{
	/** @inject */
	public DiscountRepository $discountRepository;

	/** @inject */
	public PricelistRepository $priceListRepository;

	/** @inject */
	public DiscountCouponRepository $couponRepository;

	/** @inject */
	public CustomerRepository $customerRepository;

	/** @inject */
	public CurrencyRepository $currencyRepo;

	/** @inject */
	public DeliveryDiscountRepository $deliveryRepo;

	/** @inject */
	public TagRepository $tagRepo;

	/** @inject */
	public Connection $storm;

	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->discountRepository->many(), 20, 'name', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumn('', function (Discount $object, Datagrid $datagrid) {
			return '<i title=' . ($object->isActive() ? 'Aktvní' : 'Neaktivní') . ' class="fa fa-circle fa-sm text-' . ($object->isActive() ? 'success' : 'danger') . '">';
		}, '%s', null, ['class' => 'fit']);
		$grid->addColumnText('Platnost od', "validFrom|date:'d.m.Y G:i'", '%s', 'validFrom', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Platnost od', "validTo|date:'d.m.Y G:i'", '%s', 'validTo', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Název', 'name','%s', 'name');
		
		$grid->addColumn('Akční ceníky', function (Discount $object, $datagrid) {
			$resultString = '';
			
			foreach ($object->pricelists as $pricelist) {
				$link = ':Eshop:Admin:Pricelists:priceListDetail';
				if (!$this->admin->isAllowed($link)) {
					$resultString .= $pricelist->name . ', ';
				} else {
					$resultString .= '<a href=' . $this->link($link, [$pricelist, 'backlink' => $this->storeRequest()]) . '>' . $pricelist->name . '</a>, ';
				}
			}
			
			return \substr($resultString, 0, -2);
		}, '%s');
		
		$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');

		$grid->addColumnLink('deliveryDiscounts', 'Slevy na dopravu');
		$grid->addColumnLink('coupons', 'Kupóny');

		$grid->addColumnLinkDetail();
		$grid->addColumnActionDelete();
		
		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['name_cs'], null, 'Název');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create(true);

		$form->addLocaleText('name', 'Název');
		$form->addDatetime('validFrom', 'Platný od')->setNullable(true);
		$form->addDatetime('validTo', 'Platný do')->setNullable(true);

		/** @var Discount $discount */
		$discount = $this->getParameter('discount');
		if (!$discount) {
			$form->addText('deliveryDiscount', 'Sleva na dopravu')
				->setHtmlAttribute('data-info', 'Zadejte hodnotu v procentech (%).')
				->addCondition(Form::FILLED)
				->addRule($form::FLOAT)
				->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');
		}

		$pricelists = $discount ? $this->priceListRepository->many()->where('fk_discount IS NULL OR fk_discount = :q', ['q' => $discount->getPK()]) : $this->priceListRepository->many()->where('fk_discount IS NULL');
		$form->addDataMultiSelect('pricelists', 'Ceníky', $pricelists->toArrayOf('name'))->setHtmlAttribute('placeholder', 'Vyberte položky...');
		
		$form->addCheckbox('recommended', 'Doporučeno');
		$form->addSubmits(!$discount);

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$discount = $this->discountRepository->syncOne($values, null, true);
			
			if (isset($values['deliveryDiscount']) && $values['deliveryDiscount'] > 0) {
				foreach ($this->currencyRepo->many() as $currency) {
					$this->deliveryRepo->createOne([
						'discountPct' => $values['deliveryDiscount'],
						'currency' => $currency,
						'discount' => $discount,
					]);
				}
			}

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$discount]);
		};

		return $form;
	}

	public function renderDefault()
	{
		$this->template->headerLabel = 'Akce';
		$this->template->headerTree = [
			['Akce', 'default'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Akce', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(Discount $discount)
	{
		$this->template->headerLabel = 'Detail akce - ' . $discount->name;
		$this->template->headerTree = [
			['Akce', 'default'],
			['Detail akce'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(Discount $discount)
	{
		/** @var Form $form */
		$form = $this->getComponent('newForm');
		
		$form->setDefaults($discount->toArray(['pricelists']));
	}

	public function createComponentCouponsGrid()
	{
		$grid = $this->gridFactory->create($this->getParameter('discount')->coupons, 20, 'code', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Vytvořen', "createdTs|date:'d.m.Y G:i'", '%s','createdTs', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumn('Exkluzivně pro zákazníka', function (DiscountCoupon $object, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Customer:edit') && $object->exclusiveCustomer ? $datagrid->getPresenter()->link(':Eshop:Admin:Customer:edit', [$object->exclusiveCustomer, 'backLink' => $this->storeRequest()]) : '#';
			
			return $object->exclusiveCustomer ? "<a href=\"" . $link . "\"><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" . $object->exclusiveCustomer->fullname . "</a>" : '';
		});
		$grid->addColumnText('Měna', 'currency.code', '%s', 'currency', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Sleva (%)', 'discountPct', '%s %%', 'discountPct', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumnText('Sleva', "discountValue|price:currency.code",'%s','discountValue', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumnText('Sleva s DPH', "discountValueVat|price:currency.code",'%s','discountValueVat', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		
		

		$grid->addColumnLinkDetail('couponsDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected();

		$grid->addFilterSelectInput('search', 'fk_currency = :q', 'Měna', '- Měna -', null, $this->currencyRepo->getArrayForSelect());
		$grid->addFilterButtons(['coupons', $this->getParameter('discount')]);

		return $grid;
	}

	public function createComponentDeliveryDiscountsGrid()
	{
		$grid = $this->gridFactory->create($this->getParameter('discount')->deliveryDiscounts, 20, 'email', 'ASC', true);
		$grid->addColumnSelectorMinimal();
		$grid->addColumnText('Měna', 'currency.code', '%s', 'currency');
		$grid->addColumnInputFloat('Sleva v měně', 'discountValue', '', '', 'discountValue');
		$grid->addColumnInputFloat('Sleva s DPH', 'discountValueVat', '', '', 'discountValueVat');
		$grid->addColumnInputFloat('Sleva v %', 'discountPct', '', '', 'discountPct');
		$grid->addColumnInputFloat('Od ceny košíku', 'discountPriceFrom', '', '', 'discountPriceFrom');
		
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll(['discountValue', 'discountPct', 'discountPriceFrom']);
		$grid->addButtonDeleteSelected();

		$grid->addFilterSelectInput('search', 'fk_currency = :q', 'Měna', '- Měna -', null, $this->currencyRepo->getArrayForSelect());
		$grid->addFilterButtons(['deliveryDiscounts', $this->getParameter('discount')]);

		return $grid;
	}

	public function createComponentCouponsForm()
	{
		$form = $this->formFactory->create();

		$form->addText('code', 'Kód')->setRequired();
		$form->addText('label', 'Popisek');
		$form->addDataSelect('exclusiveCustomer', 'Jen pro zákazníka', $this->customerRepository->getListForSelect())->setPrompt('Žádný');
		$form->addText('discountPct', 'Sleva (%)')->addRule($form::FLOAT)->addRule(CustomValidators::IS_PERCENT,'Hodnota není platné procento!');
		$form->addGroup('Absolutní sleva');
		$form->addDataSelect('currency', 'Měna', $this->currencyRepo->getArrayForSelect());
		$form->addText('discountValue', 'Sleva')->setHtmlAttribute('data-info','Zadejte hodnotu ve zvolené měně.')->addCondition(Form::FILLED)->addRule($form::FLOAT);
		$form->addText('discountValueVat', 'Sleva s DPH')->setHtmlAttribute('data-info','Zadejte hodnotu ve zvolené měně.')->addCondition(Form::FILLED)->addRule($form::FLOAT);
		$form->bind($this->couponRepository->getStructure());
		$form->addHidden('discount', (string) $this->getParameter('discount') ?? $this->getParameter('discountCoupon')->getValue('discount'));
		
		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
	
			$coupon = $this->couponRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');

			$form->processRedirect('coupons', 'coupons', [], [$this->getParameter('discount') ?? $this->getParameter('discountCoupon')->discount]);
		};

		return $form;
	}

	public function createComponentDeliveryDiscountsForm()
	{
		$form = $this->formFactory->create();
		
		$form->addText('discountPriceFrom', 'Od jaké ceny košíku je sleva')->addCondition(Form::FILLED)->addRule($form::FLOAT);
		$form->addText('discountPct', 'Sleva (%)')->addCondition(Form::FILLED)->addRule($form::FLOAT);
		$form->addGroup('Absolutní sleva');
		$form->addSelect('currency', 'Měna', $this->currencyRepo->getArrayForSelect());
		$form->addText('discountValue', 'Sleva v měně')->addCondition(Form::FILLED)->addRule($form::FLOAT);
		$form->addText('discountValueVat', 'Sleva na měně s DPH')->addCondition(Form::FILLED)->addRule($form::FLOAT);
		
		$form->bind($this->deliveryRepo->getStructure());
		$form->addHidden('discount', (string) $this->getParameter('discount') ?? $this->getParameter('deliveryDiscount')->getValue('discount'));
		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			$this->deliveryRepo->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('', 'deliveryDiscounts', [], [$this->getParameter('discount') ?? $this->getParameter('deliveryDiscount')->discount]);
		};

		return $form;
	}

	public function actionCoupons(Discount $discount, ?string $backLink = null)
	{
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('couponsCreate', [$discount])];
		$this->template->displayControls = [$this->getComponent('couponsGrid')];
	}

	public function actionCouponsDetail(DiscountCoupon $discountCoupon, ?string $backLink = null)
	{
		/** @var Form $form */
		$form = $this->getComponent('couponsForm');

		$values = $discountCoupon->toArray();
		$values['usedTs'] = $values['usedTs'] ? date('Y-m-d\TH:i:s', strtotime($values['usedTs'])) : '';
		$values['createdTs'] = $values['createdTs'] ? date('Y-m-d\TH:i:s', strtotime($values['createdTs'])) : '';
		$form->setDefaults($values);
	}
	
	public function renderCoupons(Discount $discount)
	{
		$this->template->headerLabel = 'Kupóny akce - ' . $discount->name;
		$this->template->headerTree = [
			['Akce', 'default'],
			['Kupóny akce'],
		];
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('couponsCreate', [$discount])];
		$this->template->displayControls = [$this->getComponent('couponsGrid')];
	}

	public function renderCouponsDetail(DiscountCoupon $discountCoupon)
	{
		$this->template->headerLabel = 'Detail kupónu - ' . $discountCoupon->code;
		$this->template->headerTree = [
			['Akce', 'default'],
			['Kupóny akce', ':Eshop:Admin:Discount:coupons', $discountCoupon->discount],
			['Detail kopónu'],
		];
		$this->template->displayButtons = [$this->createBackButton('coupons', $discountCoupon->discount)];
		$this->template->displayControls = [$this->getComponent('couponsForm')];
	}

	public function renderCouponsCreate(Discount $discount)
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Akce', 'default'],
			['Kupóny akce', ':Eshop:Admin:Discount:coupons', $discount],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('coupons', $discount)];
		$this->template->displayControls = [$this->getComponent('couponsForm')];
	}

	public function renderDeliveryDiscounts(Discount $discount)
	{
		$this->template->headerLabel = 'Slevy na dopravu';
		$this->template->headerTree = [
			['Akce', 'default'],
			['Slevy na dopravu'],
		];
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('deliveryDiscountsNew', [$discount])];
		$this->template->displayControls = [$this->getComponent('deliveryDiscountsGrid')];
	}

	public function renderDeliveryDiscountsNew(Discount $discount)
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Akce', 'default'],
			['Slevy na dopravu akce', ':Eshop:Admin:Discount:deliveryDiscounts', $discount],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('deliveryDiscounts', $discount)];
		$this->template->displayControls = [$this->getComponent('deliveryDiscountsForm')];
	}
}