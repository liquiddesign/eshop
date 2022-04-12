<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CartItem;
use Eshop\DB\CartItemRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PackageItem;
use Eshop\DB\PackageItemRepository;
use Eshop\DB\Supplier;
use Eshop\DB\SupplierRepository;
use Forms\Form;
use StORM\Collection;
use StORM\ICollection;

class OrderItemsPresenter extends \Eshop\BackendPresenter
{
	/** @inject */
	public CartItemRepository $cartItemRepository;

	/** @inject */
	public OrderRepository $orderRepository;

	/** @inject */
	public PackageItemRepository $packageItemRepository;

	/** @inject */
	public SupplierRepository $supplierRepository;

	public function createComponentGrid(): AdminGrid
	{
		$orders = $this->orderRepository->many()->toArray();

		$grid = $this->gridFactory->create($this->cartItemRepository->many()
			->join(['e_pr' => 'eshop_product'], 'this.fk_product = e_pr.uuid')
			->join(['e_c' => 'eshop_cart'], 'this.fk_cart = e_c.uuid')
			->join(['e_pu' => 'eshop_purchase'], 'e_c.fk_purchase = e_pu.uuid')
			->join(['e_o' => 'eshop_order'], 'e_pu.uuid = e_o.fk_purchase')
			->join(['e_sp' => 'eshop_supplierproduct'], 'e_sp.fk_product = this.fk_product')
			->join(['e_pai' => 'eshop_packageitem'], 'this.uuid = e_pai.fk_cartItem')
			->join(['e_st' => 'eshop_store'], 'e_st.uuid = e_pai.fk_store')
			->join(['e_su' => 'eshop_supplier'], 'e_su.uuid = e_st.fk_supplier')
			->selectAliases(['e_pai' => PackageItem::class], 'packageitem_')
			->selectAliases(['e_o' => Order::class], 'order_')
			->selectAliases(['e_su' => Supplier::class], 'supplier_')
			->where('e_o.receivedTs IS NOT NULL AND e_o.completedTs IS NOT NULL AND e_o.canceledTs IS NULL'), 100, 'e_o.createdTs', 'DESC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Vytvořeno', 'order_createdTs|date', '%s', 'e_o.createdTs', ['class' => 'fit']);

		$grid->addColumn('Objednávka', function (CartItem $cartItem) use ($orders): ?string {
			$name = $cartItem->getValue('order_code');
			$link = $cartItem->getValue('order_code') && $this->admin->isAllowed(':Eshop:Admin:Order:printDetail') ?
				$this->link(':Eshop:Admin:Order:printDetail', $orders[$cartItem->getValue('order_uuid')]) :
				null;

			return $link ? "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;$name</a>" : $name;
		}, '%s', 'code');

		$grid->addColumn('Kód', function (CartItem $cartItem): string {
			return $cartItem->getProduct() ? $cartItem->getProduct()->getFullCode() : $cartItem->getFullCode();
		}, '%s', 'code');

		$grid->addColumn('Produkt', function (CartItem $cartItem): string {
			$name = $cartItem->getProduct() ? $cartItem->getProduct()->name : $cartItem->productName;
			$link = $cartItem->getProduct() && $this->admin->isAllowed(':Eshop:Admin:Product:edit') ? $this->link(':Eshop:Admin:Product:edit', $cartItem->getProduct()) : null;

			return $link ? "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;$name</a>" : $name;
		}, '%s', 'code');

		$grid->addColumnText('Množství', 'amount', '%s', 'amount', ['class' => 'fit']);

		$grid->addColumn('Dodavatel', function (CartItem $cartItem): ?string {
			return $cartItem->getValue('supplier_name');
		}, '%s', 'code');

		$grid->addColumn('Objednáno', function (CartItem $cartItem): string {
			return $cartItem->getValue('packageitem_status') === 'reserved' ? '<i class="fa fa-check text-success"></i>' : '<i class="fa fa-times text-danger"></i>';
		}, '%s', 'code', ['class' => 'minimal']);

//		$btnSecondary = 'btn btn-sm btn-outline-primary';
//		$sendIco = "<a href='%s' class='$btnSecondary' title='Objednat'><i class='fas fa-paper-plane'></i></a>";

//		$grid->addColumnAction('', $sendIco, [$this, 'sendItem'], [], null, ['class' => 'minimal']);

		$grid->addFilterTextInput('search', ['this.productName_cs', 'this.productCode', 'e_pr.name_cs', 'e_pr.code'], null, 'Kód, název');

		$grid->addFilterDatetime(function (ICollection $source, $value): void {
			$source->where('e_o.createdTs >= :created_from', ['created_from' => $value]);
		}, '', 'date_from', null)->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Datum od');

		$grid->addFilterDatetime(function (ICollection $source, $value): void {
			$source->where('e_o.createdTs <= :created_to', ['created_to' => $value]);
		}, '', 'date_to', null)->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Datum do');

		$grid->addFilterDataSelect(function (Collection $source, $value): void {
			$source->where('e_st.fk_supplier', $value);
		}, '', 'supplier', null, $this->supplierRepository->getArrayForSelect())->setPrompt('- Dodavatel -');

		$grid->addFilterButtons();

		$grid->addBulkAction('item', 'itemSelected', 'Generovat CSV');
		$grid->addBulkAction('mark', 'markSent', 'Hromadně označit');
		
		return $grid;
	}
	
	public function createComponentForm(): Form
	{
		/** @var \Eshop\DB\CartItem|null $cartItem */
		$cartItem = $this->getParameter('cartItem');

		$form = $this->formFactory->create(true);
		
		$form->addSubmits(!$cartItem);
		
		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$cartItem = $this->cartItemRepository->syncOne($values);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$cartItem]);
		};
		
		return $form;
	}

	public function sendItem(CartItem $cartItem): void
	{
		unset($cartItem);
	}

	public function createComponentItemSelectedForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('grid'), function (array $values, Collection $collection): void {
			/** @var \Eshop\DB\CartItem $item */
			foreach ($collection as $item) {
				$this->sendItem($item);
			}

			$this->flashMessage('Provedeno', 'success');
		}, $this->getBulkFormActionLink(), $this->orderRepository->many(), $this->getBulkFormIds());
	}

	public function createComponentMarkSentForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('grid'), function (array $values, Collection $collection): void {
			$itemsToUpdate = $collection->toArrayOf('uuid', [], true);

			$this->packageItemRepository->many()->where('this.fk_cartItem', $itemsToUpdate)->update(['status' => $values['status'] === 'y' ? 'reserved' : 'waiting']);

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('this');
		}, $this->getBulkFormActionLink(), $this->cartItemRepository->many(), $this->getBulkFormIds(), function (AdminForm $form): void {
			$form->addSelect('status', 'Odesláno', ['n' => 'Ne', 'y' => 'Ano',])->setDefaultValue('y')->setRequired()
				->setHtmlAttribute('data-info', 'Nedochází ke skutečnému odeslání dodavateli! Položky se pouze interně ozznačí.');
		});
	}

	public function renderItemSelected(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Objednat u dodavatelů';
		$this->template->headerTree = [
			['Objednané položky', 'default'],
			['Objednat u dodavatelů'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('itemSelectedForm')];
	}

	public function renderMarkSent(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Označit jako objednané';
		$this->template->headerTree = [
			['Objednané položky', 'default'],
			['Označit jako objednané'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('markSentForm')];
	}
	
	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Objednané položky';
		$this->template->headerTree = [
			['Objednané položky', 'default'],
		];
//		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Objednané položky', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function renderDetail(CartItem $cartItem): void
	{
		unset($cartItem);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Objednané položky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
	
	public function actionDetail(CartItem $cartItem): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');

		$defaults = $cartItem->toArray();

		$form->setDefaults($defaults);
	}
}
