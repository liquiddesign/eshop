<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\Admin\Controls\DiscountCouponGeneratorForm;
use Eshop\Admin\Controls\IDiscountCouponFormFactory;
use Eshop\Admin\Controls\IDiscountCouponGeneratorFormFactory;
use Eshop\BackendPresenter;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DeliveryDiscountRepository;
use Eshop\DB\Discount;
use Eshop\DB\DiscountCoupon;
use Eshop\DB\DiscountCouponRepository;
use Eshop\DB\DiscountRepository;
use Eshop\DB\OrderRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\RibbonRepository;
use Eshop\FormValidators;
use Forms\Form;
use Grid\Datagrid;
use Nette\Caching\Storage;
use StORM\Connection;
use StORM\ICollection;

class DiscountPresenter extends BackendPresenter
{
	/** @inject */
	public DiscountRepository $discountRepository;

	/** @inject */
	public PricelistRepository $priceListRepository;

	/** @inject */
	public CustomerRepository $customerRepository;

	/** @inject */
	public CurrencyRepository $currencyRepo;

	/** @inject */
	public DeliveryDiscountRepository $deliveryRepo;

	/** @inject */
	public RibbonRepository $ribbonRepository;

	/** @inject */
	public IDiscountCouponFormFactory $discountCouponFormFactory;

	/** @inject */
	public Connection $storm;

	/** @inject */
	public OrderRepository $orderRepository;

	/** @inject */
	public Storage $storage;

	/** @inject */
	public DiscountCouponRepository $discountCouponRepository;

	/** @inject */
	public IDiscountCouponGeneratorFormFactory $discountCouponGeneratorFormFactory;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->discountRepository->many(), 20, 'name', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumn('', function (Discount $object, Datagrid $datagrid) {
			return '<i title=' . ($object->isActive() ? 'Aktivní' : 'Neaktivní') . ' class="fa fa-circle fa-sm text-' . ($object->isActive() ? 'success' : 'danger') . '">';
		}, '%s', null, ['class' => 'fit']);
		$grid->addColumnText('Platnost od', "validFrom|date:'d.m.Y G:i'", '%s', 'validFrom', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Platnost od', "validTo|date:'d.m.Y G:i'", '%s', 'validTo', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnText('Interní název', 'internalName', '%s', 'internalName');

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

		//$cache = new Cache($this->storage);

		/*
		$grid->addColumn('Využití', function (Discount $object, $datagrid) use ($cache): array {
			return $cache->load('discount_usage_' . $object->getPK(), function (&$dependencies) use ($object): array {
				$dependencies[Cache::EXPIRE] = '1 hour';

				$orders = $this->orderRepository->many()
					->where('this.receivedTs IS NOT NULL AND this.completedTs IS NOT NULL AND this.canceledTs IS NULL');

				if ($object->validFrom) {
					$orders->where('this.createdTs >= :created1', ['created1' => $object->validFrom]);
				}

				if ($object->validTo) {
					$orders->where('this.createdTs <= :created2', ['created2' => $object->validTo]);
				}

				$orders = $orders->toArray();
				$coupons = $object->coupons->toArray();

				$countUsage = $this->orderRepository->getDiscountCouponsUsage($orders, $coupons)[1] ?? [];

				$ordersCount = \count($orders);
				$totalCountUsage = 0;

				foreach ($countUsage as $count) {
					$totalCountUsage += $count;
				}

				return [\round($totalCountUsage / $ordersCount * 100, 4), $totalCountUsage, \count($orders)];
			});
		}, '%s %% | %sx z %s');*/
		
		$grid->addColumn('Kupóny', function (Discount $object, $datagrid) {
			try {
				/** @var \stdClass $test */
				$test = $this->discountCouponRepository->getConnection()->rows(['this' => 'eshop_discountcoupon'])
					->where('this.fk_discount', $object->getPK())
					->setSelect(['usagesCountSum' => 'SUM(usagesCount)', 'usageLimitSum' => 'SUM(usageLimit)'])
					->first();

				return [(int) $test->usagesCountSum, (int) $test->usageLimitSum];
			} catch (\PDOException $exception) {
				return [0, 0,];
			}
		}, '%s/%s')->onRenderCell[] = [$grid, 'decoratorNumber'];

		$grid->addColumnInputCheckbox('<i title="Doporučeno" class="far fa-thumbs-up"></i>', 'recommended', '', '', 'recommended');

		$grid->addColumnLink('deliveryDiscounts', 'Slevy na dopravu');
		$grid->addColumnLink('coupons', 'Kupóny');

		$grid->addColumnLinkDetail();

		$deleteCondition = function (Discount $discount): bool {
			$usedCoupons = $this->discountCouponRepository->many()->where('EXISTS (SELECT uuid, fk_coupon FROM eshop_purchase WHERE eshop_purchase.fk_coupon = this.uuid)')->toArray();

			foreach ($discount->coupons as $coupon) {
				if (isset($usedCoupons[$coupon->getPK()])) {
					return false;
				}
			}

			return true;
		};

		$grid->addColumnActionDelete(condition: $deleteCondition);

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(condition: $deleteCondition);

		$grid->addFilterTextInput('search', ['name_cs', 'internalName_cs'], null, 'Název, interní název');

		if ($ribbons = $this->ribbonRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
				$source->join(['nxn' => 'eshop_discount_nxn_eshop_ribbon'], 'nxn.fk_discount=this.uuid');
				$source->where('nxn.fk_ribbon', $value);
			}, '', 'ribbons', null, $ribbons, ['placeholder' => '- Štítky -']);
		}

		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create(true);

		$form->addLocaleText('name', 'Název');
		$form->addLocaleText('internalName', 'Interní název');
		$form->addDatetime('validFrom', 'Platný od')->setNullable(true);
		$form->addDatetime('validTo', 'Platný do')->setNullable(true);

		/** @var \Eshop\DB\Discount|null $discount */
		$discount = $this->getParameter('discount');

		if (!$discount) {
			$form->addText('deliveryDiscount', 'Sleva na dopravu')
				->setHtmlAttribute('data-info', 'Zadejte hodnotu v procentech (%).')
				->addCondition(Form::FILLED)
				->addRule($form::FLOAT)
				->addRule([FormValidators::class, 'isPercent'], 'Zadaná hodnota není procento!');
		}

		$pricelists = $discount ?
			$this->priceListRepository->many()->where('fk_discount IS NULL OR fk_discount = :q', ['q' => $discount->getPK()]) : $this->priceListRepository->many()->where('fk_discount IS NULL');
		$form->addDataMultiSelect('pricelists', 'Ceníky', $pricelists->toArrayOf('name'))->setHtmlAttribute('placeholder', 'Vyberte položky...');
		$form->addDataMultiSelect(
			'ribbons',
			'Štítky',
			$this->ribbonRepository->getCollection(true)->where('this.dynamic', true)->toArrayOf('name'),
		)->setHtmlAttribute('placeholder', 'Vyberte položky...');

		$form->addCheckbox('recommended', 'Doporučeno');
		$form->addSubmits(!$discount);

		$form->onSuccess[] = function (AdminForm $form) use ($discount): void {
			$values = $form->getValues('array');

			if ($discount) {
				$this->priceListRepository->many()->where('fk_discount', $discount->getPK())->update(['discount' => null]);
			}

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

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Akce';
		$this->template->headerTree = [
			['Akce', 'default'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Akce', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(Discount $discount): void
	{
		unset($discount);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Akce', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(Discount $discount): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');

		$form->setDefaults($discount->toArray(['pricelists', 'ribbons']));
	}

	public function createComponentCouponsGrid(): AdminGrid
	{
		/** @var \Eshop\DB\Discount $discount */
		$discount = $this->getParameter('discount');

		$grid = $this->gridFactory->create($discount->coupons, 20, 'code', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Vytvořen', "createdTs|date:'d.m.Y G:i'", '%s', 'createdTs', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumn('Exkluzivně pro zákazníka', function (DiscountCoupon $object, Datagrid $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:Customer:edit') && $object->exclusiveCustomer ?
				$datagrid->getPresenter()->link(':Eshop:Admin:Customer:edit', [$object->exclusiveCustomer, 'backLink' => $this->storeRequest()]) : '#';

			return $object->exclusiveCustomer ? '<a href="' . $link . "\"><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" . $object->exclusiveCustomer->fullname . '</a>' : '';
		});
		$grid->addColumnText('Sleva (%)', 'discountPct', '%s %%', 'discountPct', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumnText('Sleva', 'discountValue|price:currency.code', '%s', 'discountValue', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumnText('Sleva s DPH', 'discountValueVat|price:currency.code', '%s', 'discountValueVat', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];

//		$orders = $this->orderRepository->many()
//			->where('this.receivedTs IS NOT NULL AND this.completedTs IS NOT NULL AND this.canceledTs IS NULL');
//
//		if ($discount->validFrom) {
//			$orders->where('this.createdTs >= :created1', ['created1' => $discount->validFrom]);
//		}
//
//		if ($discount->validTo) {
//			$orders->where('this.createdTs <= :created2', ['created2' => $discount->validTo]);
//		}

//		$ordersCount = \count($orders);
//
//		$cache = new Cache($this->storage);
		
//		$grid->addColumn('Využití', function (DiscountCoupon $object, $datagrid) use ($orders, $cache, $ordersCount): array {
//			return $cache->load('discount_coupon_usage_' . $object->getPK(), function (&$dependencies) use ($object, $orders, $ordersCount): array {
//				$dependencies[Cache::EXPIRE] = '1 hour';
//
//				$usages = $this->orderRepository->getDiscountCouponsUsage($orders->toArray(), [$object]);
//
//				return [$usages[0][$object->getPK()], $usages[1][$object->getPK()], $ordersCount];
//			});
//		}, '%s %% | %sx z %s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		
		$grid->addColumnText('Uplatnění', ['usagesCount', 'usageLimit'], '%s / %s', 'usagesCount', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumn('', function (DiscountCoupon $coupon): string {
			return $this->link(':Eshop:Admin:Order:default', [
				'ordersGrid-coupon' => $coupon->code,
				'tab' => 'finished',
			]);
		}, '<a href="%s" target="_blank"><i class="fas fa-external-link-square-alt"></i></a>', wrapperAttributes: ['class' => 'minimal']);
		$grid->addColumnLinkDetail('couponsDetail');

		$deleteCondition = function (DiscountCoupon $discountCoupon): bool {
			return $this->discountCouponRepository->many()
					->where('this.uuid', $discountCoupon->getPK())
					->where('EXISTS (SELECT uuid, fk_coupon FROM eshop_purchase WHERE eshop_purchase.fk_coupon = this.uuid)')
					->count() === 0;
		};

		$grid->addColumnActionDelete(condition: $deleteCondition);
		$grid->addButtonDeleteSelected(condition: $deleteCondition);

		$grid->addFilterSelectInput('search', 'fk_currency = :q', 'Měna', '- Měna -', null, $this->currencyRepo->getArrayForSelect());
		$grid->addFilterButtons(['coupons', $this->getParameter('discount')]);

		return $grid;
	}

	public function createComponentDeliveryDiscountsGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->getParameter('discount')->deliveryDiscounts, 20, 'email', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumnText('Měna', 'currency.code', '%s', 'currency');
		$grid->addColumnInputFloat('Sleva v měně', 'discountValue', '', '', 'discountValue');
		$grid->addColumnInputFloat('Sleva s DPH', 'discountValueVat', '', '', 'discountValueVat');
		$grid->addColumnInputFloat('Sleva v %', 'discountPct', '', '', 'discountPct');
		$grid->addColumnInputFloat('Od ceny košíku', 'discountPriceFrom', '', '', 'discountPriceFrom');
		$grid->addColumnInputFloat('Od váhy košíku', 'weightFrom', '', '', 'weightFrom');
		$grid->addColumnInputFloat('Do váhy košíku', 'weightTo', '', '', 'weightTo');

		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll(['discountValue', 'discountPct', 'discountPriceFrom'], [], null, false, null, function ($id, &$data): void {
			if (!isset($data['discountPriceFrom'])) {
				$data['discountPriceFrom'] = 0;
			}
		}, false);
		$grid->addButtonDeleteSelected();

		$grid->addFilterSelectInput('search', 'fk_currency = :q', 'Měna', '- Měna -', null, $this->currencyRepo->getArrayForSelect());
		$grid->addFilterButtons(['deliveryDiscounts', $this->getParameter('discount')]);

		return $grid;
	}

	public function createComponentCouponsForm(): Controls\DiscountCouponForm
	{
		return $this->discountCouponFormFactory->create($this->getParameter('discountCoupon'), $this->getParameter('discount'));
	}

	public function createComponentDeliveryDiscountsForm(): AdminForm
	{
		$form = $this->formFactory->create();

		/** @var \Eshop\DB\Discount|null $discount */
		$discount = $this->getParameter('discount');

		$form->addText('discountPriceFrom', 'Od jaké ceny košíku je sleva')->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('discountPct', 'Sleva (%)')->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addGroup('Absolutní sleva');
		$form->addSelect('currency', 'Měna', $this->currencyRepo->getArrayForSelect());
		$form->addText('discountValue', 'Sleva v měně')->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('discountValueVat', 'Sleva na měně s DPH')->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('weightFrom', 'Od váhy košíku')->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('weightTo', 'Do váhy košíku')->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);

		$form->bind($this->deliveryRepo->getStructure());
		$form->addHidden('discount', $discount ? (string)$discount : $this->getParameter('deliveryDiscount')->getValue('discount'));
		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$this->deliveryRepo->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('', 'deliveryDiscounts', [], [$this->getParameter('discount') ?? $this->getParameter('deliveryDiscount')->discount]);
		};

		return $form;
	}

	public function actionCoupons(Discount $discount, ?string $backLink = null): void
	{
		unset($backLink);

		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('couponsCreate', [$discount])];
		$this->template->displayControls = [$this->getComponent('couponsGrid')];
	}

	public function actionCouponsDetail(DiscountCoupon $discountCoupon, ?string $backLink = null): void
	{
		unset($backLink);

		/** @var \Forms\Form $form */
		$form = $this->getComponent('couponsForm')['form'];

		$values = $discountCoupon->toArray();
		$values['usedTs'] = $values['usedTs'] ? \date('Y-m-d\TH:i:s', \strtotime($values['usedTs'])) : '';
		$values['createdTs'] = $values['createdTs'] ? \date('Y-m-d\TH:i:s', \strtotime($values['createdTs'])) : '';
		$form->setDefaults($values);
	}

	public function renderCoupons(Discount $discount): void
	{
		$this->template->headerLabel = 'Kupóny akce - ' . $discount->name;
		$this->template->headerTree = [
			['Akce', 'default'],
			['Kupóny akce'],
		];
		$this->template->displayButtons = [
			$this->createBackButton('default'),
			$this->createNewItemButton('couponsCreate', [$discount]),
			$this->createButton('discountCouponGenerator', 'Generátor kupónů', [$discount]),
		];
		$this->template->displayControls = [$this->getComponent('couponsGrid')];
	}

	public function renderCouponsDetail(DiscountCoupon $discountCoupon): void
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

	public function renderCouponsCreate(Discount $discount): void
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

	public function renderDeliveryDiscounts(Discount $discount): void
	{
		$this->template->headerLabel = 'Slevy na dopravu';
		$this->template->headerTree = [
			['Akce', 'default'],
			['Slevy na dopravu'],
		];
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createNewItemButton('deliveryDiscountsNew', [$discount])];
		$this->template->displayControls = [$this->getComponent('deliveryDiscountsGrid')];
	}

	public function renderDeliveryDiscountsNew(Discount $discount): void
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

	public function renderDiscountCouponGenerator(Discount $discount): void
	{
		$this->template->headerLabel = 'Generátor kupónů';
		$this->template->headerTree = [
			['Akce', 'default'],
			['Kupóny', ':Eshop:Admin:Discount:coupons', $discount],
			['Generátor'],
		];
		$this->template->displayButtons = [$this->createBackButton('coupons', $discount)];
		$this->template->displayControls = [$this->getComponent('discountCouponGeneratorForm')];
	}

	public function createComponentDiscountCouponGeneratorForm(): DiscountCouponGeneratorForm
	{
		return $this->discountCouponGeneratorFormFactory->create($this->getParameter('discount'));
	}
}
