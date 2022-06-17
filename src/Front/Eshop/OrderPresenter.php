<?php

declare(strict_types=1);

namespace Eshop\Front\Eshop;

use Eshop\Controls\IOrderListFactory;
use Eshop\Controls\OrderList;
use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\DeliveryRepository;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentRepository;
use League\Csv\Writer;
use Nette;
use Nette\Application\Application;
use Nette\Application\Responses\FileResponse;

abstract class OrderPresenter extends \Eshop\Front\FrontendPresenter
{
	/** @inject */
	public IOrderListFactory $orderListFactory;

	/** @inject */
	public OrderRepository $orderRepository;

	/** @inject */
	public DeliveryRepository $deliveryRepository;

	/** @inject */
	public PaymentRepository $paymentRepository;

	/** @inject */
	public Application $application;

	/** @inject */
	public CatalogPermissionRepository $catalogPermRepository;

	/** @persistent */
	public string $tab = 'new';

	/**
	 * @var array<string, string>
	 */
	public array $tabs = [];

	public function checkRequirements($element): void
	{
		parent::checkRequirements($element);

		if ($this->getUser()->isLoggedIn() || $this->isLinkCurrent(':Eshop:Order:order')) {
			return;
		}

		$this->redirect(':Web:Index:default');
	}

	public function handleTestEmailOrder(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId, true);

		$emailVariables = $this->orderRepository->getEmailVariables($order);

		$mail = $this->templateRepository->createMessage('order.created', $emailVariables, $order->purchase->email, $order->purchase->ccEmails);
		$this->mailer->send($mail);
	}

	public function handleExport(string $orderId): void
	{
		$object = $this->orderRepository->one($orderId, true);
		$tempFilename = \tempnam($this->tempDir, 'csv');

		$this->application->onShutdown[] = function () use ($tempFilename): void {
			\Nette\Utils\FileSystem::delete($tempFilename);
		};

		$this->orderRepository->csvExport($object, Writer::createFromPath($tempFilename, 'w+'));

		$this->sendResponse(new FileResponse($tempFilename, "objednavka-$object->code.csv", 'text/csv'));
	}

	public function handleBuyAgain(string $orderId): void
	{
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->one($orderId);

		/** @var \Eshop\DB\Cart|null $cart */
		$cart = $order->purchase->carts->first();

		if (!$cart) {
			return;
		}

		$this->checkoutManager->addItemsFromCart($cart);
		$this->redirect(':Eshop:Checkout:cart');
	}

	public function renderOrder(string $orderId): void
	{
		$order = $this->orderRepository->one($orderId);
		
		if (!$order) {
			throw new Nette\Application\BadRequestException("Order $orderId not exists");
		}
		
		$purchase = $order->purchase;

		$this->template->breadcrumb = [];

		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		if ($this->getUser()->isLoggedIn()) {
			$breadcrumb->addItem($this->translator->translate('.myAccount', 'Můj účet'));
			$breadcrumb->addItem(
				$this->translator->translate('.myOrders', 'Moje objednávky'),
				$this->link('orders'),
			);
		}

		$breadcrumb->addItem($this->translator->translate('order.orderNumber', 'Objednávka č.') . ': ' . $order->code);

		$this->template->order = $order;
		$this->template->deliveryAddress = $purchase->deliveryAddress ?: $purchase->billAddress;
		$this->template->billingAddress = $purchase->billAddress;
		$this->template->purchase = $purchase;
		$this->template->delivery = $order->deliveries->first();
		$this->template->payment = $order->payments->first();
		$this->template->deliveryAndPaymentPrice = $order->getDeliveryPriceSum() + $order->getPaymentPriceSum();
		$this->template->deliveryAndPaymentPriceVat = $order->getDeliveryPriceVatSum() + $order->getPaymentPriceVatSum();
		$this->template->state = $this->translator->translate(
			'orderState.' . $this->orderRepository->getState($orderId),
			$this->orderRepository->getState($orderId),
		);
	}

	public function renderOrders(): void
	{
		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		$breadcrumb->addItem($this->translator->translate('.myAccount', 'Můj účet'));
		$breadcrumb->addItem($this->translator->translate('.myOrders', 'Moje objednávky'));
		$this->template->tabs = $this->tabs = [
			'new' => $this->translator->translate('oOs.currentOrders', 'Aktuální objednávky'),
			'finished' => $this->translator->translate('oOs.completedOrders', 'Vyřízené objednávky'),
			'canceled' => $this->translator->translate('oOs.canceledOrders', 'Stornované objednávky'),
		];

		$this->template->tab = $this->tab;
	}

	public function createComponentOrderList(): OrderList
	{
		if ($this->tab === 'finished' || $this->shopper->getCustomer()) {
			$orderList = $this->orderListFactory->create();
		} elseif ($this->tab === 'new') {
			$orderList = $this->orderListFactory->create($this->orderRepository->getNewOrders(
				$this->shopper->getCustomer(),
				$this->shopper->getMerchant(),
			)->orderBy(['this.createdTS' => 'DESC']));
		} else {
			$orderList = $this->orderListFactory->create($this->orderRepository->getCanceledOrders(
				$this->shopper->getCustomer(),
				$this->shopper->getMerchant(),
			)->orderBy(['this.createdTS' => 'DESC']));
		}

		$orderList->setTempDir($this->tempDir);

		$orderList->onAnchor[] = function (OrderList $orderList): void {
			$orderList->template->merchant = $this->shopper->getMerchant();
			$orderList->template->customer = $this->shopper->getCustomer();
			$orderList->template->tab = $this->tab;
			$orderList->template->setFile(\dirname(__DIR__, 6) . '/app/Eshop/Controls/orderList.latte');
		};

		return $orderList;
	}

	public function createComponentNewOrderList(): OrderList
	{
		/** @var \Eshop\DB\CatalogPermission $permission */
		$permission = $this->catalogPermRepository->many()->where(
			'fk_account',
			$this->shopper->getCustomer()->getAccount(),
		)->first();

		$orderList = $this->orderListFactory->create($this->orderRepository->getNewOrders(
			$this->shopper->getCustomer(),
			$this->shopper->getMerchant(),
			$permission->viewAllOrders ? null : $this->shopper->getCustomer()->getAccount(),
		)->orderBy(['this.createdTS' => 'DESC']));
		$orderList->onAnchor[] = function (OrderList $orderList): void {
			$orderList->template->merchant = $this->shopper->getMerchant();
			$orderList->template->customer = $this->shopper->getCustomer();
			$orderList->template->setFile(\dirname(__DIR__, 6) . '/app/Eshop/Controls/orderList.latte');
		};

		return $orderList;
	}
}
