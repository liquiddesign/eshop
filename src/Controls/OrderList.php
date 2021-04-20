<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\Admin\Controls\OrderGridFactory;
use Eshop\Shopper;
use Eshop\DB\OrderRepository;
use Grid\Datalist;
use Nette\Application\UI\Form;
use Nette\Localization\Translator;
use StORM\Collection;
use StORM\ICollection;

/**
 * Class Products
 * @package Eshop\Controls
 */
class OrderList extends Datalist
{
	private Translator $translator;

	private OrderGridFactory $orderGridFactory;

	private OrderRepository $orderRepository;

	public function __construct(Translator $translator, OrderGridFactory $orderGridFactory, OrderRepository $orderRepository, Shopper $shopper, ?Collection $orders = null)
	{
		parent::__construct($orders ?? $orderRepository->getFinishedOrders($shopper->getCustomer(), $shopper->getMerchant()));

		$this->setDefaultOnPage(10);
		$this->setDefaultOrder('this.createdTs', 'DESC');

		$this->addFilterExpression('search', function (ICollection $collection, $value) use ($orderRepository, $shopper): void {
			$suffix = $orderRepository->getConnection()->getMutationSuffix();

			$or = "this.code = :code OR items.productName$suffix LIKE :string";

			if ($shopper->getMerchant()) {
				$or .= ' OR purchase.accountFullname LIKE :string OR account.fullname LIKE :string';
				$or .= ' OR purchase.fullname LIKE :string OR customer.fullname LIKE :string OR customer.company LIKE :string';
			}

			$collection->where($or, ['code' => $value, 'string' => '%' . $value . '%'])
				->join(['carts' => 'eshop_cart'], 'purchase.uuid=carts.fk_purchase')
				->join(['items' => 'eshop_cartitem'], 'carts.uuid=items.fk_cart')
				->join(['account' => 'security_account'], 'account.uuid=purchase.fk_account');
		}, '');

		$this->getFilterForm()->addText('search');
		$this->getFilterForm()->addSubmit('submit');

		$this->translator = $translator;
		$this->orderGridFactory = $orderGridFactory;
		$this->orderRepository = $orderRepository;
	}

	public function render(): void
	{
		$this->template->paginator = $this->getPaginator();
		$this->template->render($this->template->getFile() ?: __DIR__ . '/orderList.latte');
	}

	public function createComponentSelectOrdersForm()
	{
		$form = new \Nette\Application\UI\Form();

		foreach ($this->getItemsOnPage() as $order) {
			$form->addCheckbox('check_' . $order->getPK());
		}

		$form->addSubmit('finish', $this->translator->translate('orderL.finish', 'Vyřídit vybrané'));
		$form->addSubmit('cancel', $this->translator->translate('orderL.cancel', 'Stornovat vybrané'));

		$form->onSuccess[] = function (Form $form) {
			$values = $form->getValues('array');

			$submitName = $form->isSubmitted()->getName();

			foreach ($values as $key => $value) {
				$order = $this->orderRepository->one(\explode('_', $key)[1], true);

				if($value){
					if ($submitName == 'finish') {
						$this->orderGridFactory->completeOrder($order);
					} else {
						$this->orderGridFactory->cancelOrder($order);
					}
				}
			}

			$this->getPresenter()->redirect('this');
		};

		return $form;
	}
}