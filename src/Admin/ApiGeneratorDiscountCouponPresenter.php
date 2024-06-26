<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminGrid;
use Eshop\Admin\Controls\IApiGeneratorDiscountCouponFormFactory;
use Eshop\BackendPresenter;
use Eshop\DB\ApiGeneratorDiscountCoupon;
use Eshop\DB\ApiGeneratorDiscountCouponRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DiscountCouponRepository;
use Eshop\DB\DiscountRepository;
use Grid\Datagrid;

class ApiGeneratorDiscountCouponPresenter extends BackendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public ApiGeneratorDiscountCouponRepository $discountCouponApiKeyRepository;

	#[\Nette\DI\Attributes\Inject]
	public CurrencyRepository $currencyRepository;

	#[\Nette\DI\Attributes\Inject]
	public CustomerRepository $customerRepository;

	#[\Nette\DI\Attributes\Inject]
	public DiscountRepository $discountRepository;

	#[\Nette\DI\Attributes\Inject]
	public DiscountCouponRepository $discountCouponRepository;

	#[\Nette\DI\Attributes\Inject]
	public IApiGeneratorDiscountCouponFormFactory $apiGeneratorDiscountCouponFormFactory;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->discountCouponApiKeyRepository->many(), 20, 'code', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumn('', function (ApiGeneratorDiscountCoupon $object, Datagrid $datagrid) {
			return '<i title=' . ($object->isActive() ? 'Aktivní' : 'Neaktivní') . ' class="fa fa-circle fa-sm text-' . ($object->isActive() ? 'success' : 'danger') . '">';
		}, '%s', null, ['class' => 'fit']);
		$grid->addColumnText('Platnost od', "validFrom|date:'d.m.Y G:i'", '%s', 'validFrom', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Platnost do', "validTo|date:'d.m.Y G:i'", '%s', 'validTo', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];
		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);
		$grid->addColumnText('Název', 'label', '%s', 'label');
		$grid->addColumn('Odkaz pro generování', function (ApiGeneratorDiscountCoupon $apiGeneratorDiscountCoupon) {
			$url = $this->link('//:Eshop:ApiGenerator:default', [
				'generator' => 'discountCoupon',
				'code' => $apiGeneratorDiscountCoupon->code,
				'hash' => $apiGeneratorDiscountCoupon->hash,
			]);

			return "<a href='$url' target='_blank'><i class='fas fa-external-link-alt mr-1'></i>$url</a>";
		});

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['this.label', 'this.code'], null, 'Kód, název');

		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentNewForm(): Controls\ApiGeneratorDiscountCouponForm
	{
		return $this->apiGeneratorDiscountCouponFormFactory->create($this->getParameter('discountCouponApiKey'));
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Akční kupóny';
		$this->template->headerTree = [
			['API'],
			['Akční kupóny'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['API'],
			['Akční kupóny'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['API'],
			['Akční kupóny', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(ApiGeneratorDiscountCoupon $discountCouponApiKey): void
	{
		/** @var \Eshop\Admin\Controls\ApiGeneratorDiscountCouponForm $form */
		$form = $this->getComponent('newForm');

		/** @var \Admin\Controls\AdminForm $form */
		$form = $form['form'];

		$form->setDefaults($discountCouponApiKey->toArray());
	}
}
