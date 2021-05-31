<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Shopper;
use League\Csv\Writer;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Localization\Translator;
use Nette\Utils\DateTime;
use Security\DB\Account;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Order>
 */
class OrderRepository extends \StORM\Repository
{
	private Cache $cache;

	private Shopper $shopper;

	private Translator $translator;

	private MerchantRepository $merchantRepository;

	private CatalogPermissionRepository $catalogPermissionRepository;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, Storage $storage, Shopper $shopper, Translator $translator, MerchantRepository $merchantRepository, CatalogPermissionRepository $catalogPermissionRepository)
	{
		parent::__construct($connection, $schemaManager);
		$this->cache = new Cache($storage);
		$this->shopper = $shopper;
		$this->translator = $translator;
		$this->merchantRepository = $merchantRepository;
		$this->catalogPermissionRepository = $catalogPermissionRepository;
	}

	/**
	 * @param string $customerId
	 * @return \StORM\Collection|\Eshop\DB\Order[]
	 * @deprecated use getFinishedOrders(new Customer(['uuid' => $customerId])) instead
	 */
	public function getFinishedOrdersByCustomer(string $customerId): Collection
	{
		return $this->getFinishedOrders(new Customer(['uuid' => $customerId]));
	}

	public function getFinishedOrders(?Customer $customer = null, ?Merchant $merchant = null, ?Account $account = null): Collection
	{
		$collection = $this->many()->where('this.completedTs IS NOT NULL AND this.canceledTs IS NULL');
		$collection->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid');
		$collection->join(['customer' => 'eshop_customer'], 'customer.uuid = purchase.fk_customer');
		$collection->join(['nxn' => 'eshop_merchant_nxn_eshop_customer'], 'customer.uuid = nxn.fk_customer');

		if ($customer) {
			$collection->where('purchase.fk_customer', $customer);
		} elseif ($merchant) {
			$collection->where('nxn.fk_merchant', $merchant);
		}

		if ($account) {
			$collection->where('purchase.fk_account', $account);
		}

		return $collection;
	}

	/**
	 * @param string $customerId
	 * @return \StORM\Collection|\Eshop\DB\Order[]
	 * @deprecated use getNewOrders(new Customer(['uuid' => $customerId])) instead
	 */
	public function getNewOrdersByCustomer(string $customerId): Collection
	{
		return $this->many()
			->join(['purchase' => 'eshop_purchase'], 'purchase.fk_purchase = purchase.uuid')
			->join(['customer' => 'eshop_customer'], 'customer.uuid = purchase.fk_customer')
			->where('purchase.fk_customer', $customerId)
			->where('this.completedTs IS NULL AND this.canceledTs IS NULL');
	}

	public function getNewOrders(?Customer $customer, ?Merchant $merchant = null, ?Account $account = null): Collection
	{
		$collection = $this->many()->where('this.completedTs IS NULL AND this.canceledTs IS NULL');
		$collection->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid');
		$collection->join(['customer' => 'eshop_customer'], 'customer.uuid = purchase.fk_customer');
		$collection->join(['nxn' => 'eshop_merchant_nxn_eshop_customer'], 'customer.uuid = nxn.fk_customer');

		if ($customer) {
			$collection->where('purchase.fk_customer', $customer);
		} elseif ($merchant) {
			$collection->where('nxn.fk_merchant', $merchant);
		}

		if ($account) {
			$collection->where('purchase.fk_account', $account);
		}

		return $collection;
	}

	public function getCanceledOrders(?Customer $customer, ?Merchant $merchant = null, ?Account $account = null): Collection
	{
		$collection = $this->many()->where('this.canceledTs IS NOT NULL');
		$collection->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid');
		$collection->join(['customer' => 'eshop_customer'], 'customer.uuid = purchase.fk_customer');
		$collection->join(['nxn' => 'eshop_merchant_nxn_eshop_customer'], 'customer.uuid = nxn.fk_customer');

		if ($customer) {
			$collection->where('purchase.fk_customer', $customer);
		} elseif ($merchant) {
			$collection->where('nxn.fk_merchant', $merchant);
		}

		if ($account) {
			$collection->where('purchase.fk_account', $account);
		}

		return $collection;
	}

	public function csvExport(Order $order, Writer $writer)
	{
		$writer->setDelimiter(';');

		$writer->insertOne([
			'productName',
			'productCode',
			'productWeight',
			'variantName',
			'amount',
			'realAmount',
			'price',
			'priceVat',
			'priceSum',
			'priceVatSum',
			'vatPct',
			'note'
		]);

		foreach ($order->purchase->getItems() as $item) {
			$writer->insertOne([
				$item->productName,
				$item->getFullCode(),
				$item->productWeight,
				$item->variantName,
				$item->amount,
				$item->realAmount,
				$item->price,
				$item->priceVat,
				$item->getPriceSum(),
				$item->getPriceVatSum(),
				$item->vatPct,
				$item->note
			]);
		}
	}

	public function excelExport(Order $order, \XLSXWriter $writer, string $sheetName = 'sheet')
	{
		$writer->writeSheetRow($sheetName, array(
			$order->code
		));
		$writer->writeSheetRow($sheetName, []);

		$styles = array('font-style' => 'bold');

		$writer->writeSheetRow($sheetName, array(
			$this->translator->translate('orderEE.productName', 'Název produktu'),
			$this->translator->translate('orderEE.productCode', 'Kód produktu'),
			$this->translator->translate('orderEE.amount', 'Množství'),
			$this->translator->translate('orderEE.pcsPrice', 'Cena za kus'),
			$this->translator->translate('orderEE.sumPrice', 'Mezisoučet'),
			$this->translator->translate('orderEE.note', 'Poznámka'),
		), $styles);

		foreach ($order->purchase->getItems() as $item) {
			$writer->writeSheetRow($sheetName, [
				$item->productName,
				$item->getFullCode(),
				$item->amount,
				\str_replace(',', '.', (string)$this->shopper->filterPrice($item->price, $order->purchase->currency->code)),
				\str_replace(',', '.', (string)$this->shopper->filterPrice($item->getPriceSum(), $order->purchase->currency->code)),
				$item->note
			]);
		}

		$writer->writeSheetRow($sheetName, []);
		$writer->writeSheetRow($sheetName, array(
			$this->translator->translate('orderEE.totalPrice', 'Celková cena'), \str_replace(',', '.', (string)$this->shopper->filterPrice($order->getTotalPrice(), $order->purchase->currency->code)),
		));
	}

	/**
	 * @param \Eshop\DB\Order[] $orders
	 * @param \XLSXWriter $writer
	 */
	public function excelExportAll(array $orders, \XLSXWriter $writer)
	{
		$styles = array('font-style' => 'bold');

		$sheetName = $this->translator->translate('orderEE.orders', 'Objednávky');

		$writer->writeSheetRow($sheetName, array(
			$this->translator->translate('orderEE.order', 'Objednávka'),
			$this->translator->translate('orderEE.productName', 'Název produktu'),
			$this->translator->translate('orderEE.productCode', 'Kód produktu'),
			$this->translator->translate('orderEE.amount', 'Množství'),
			$this->translator->translate('orderEE.pcsPrice', 'Cena za kus'),
			$this->translator->translate('orderEE.sumPrice', 'Mezisoučet'),
			$this->translator->translate('orderEE.note', 'Poznámka'),
			$this->translator->translate('orderEE.account', 'Servisní technik'),
		), $styles);

		$sumPrice = 0;

		foreach ($orders as $order) {
			$sumPrice += $order->getTotalPrice();

			foreach ($order->purchase->getItems() as $item) {
				$writer->writeSheetRow($sheetName, [
					$order->code,
					$item->productName,
					$item->getFullCode(),
					$item->amount,
					\str_replace(',', '.', (string)$this->shopper->filterPrice($item->price, $order->purchase->currency->code)),
					\str_replace(',', '.', (string)$this->shopper->filterPrice($item->getPriceSum(), $order->purchase->currency->code)),
					$item->note,
					$order->purchase->account ? $order->purchase->account->fullname : $order->purchase->accountFullname
				]);

			}
		}

		$writer->writeSheetRow($sheetName, []);
		$writer->writeSheetRow($sheetName, array(
			$this->translator->translate('orderEE.totalPrice', 'Celková cena'), \str_replace(',', '.', (string)$this->shopper->filterPrice($sumPrice, $order->purchase->currency->code)),
		));
	}

	/**
	 * @param \Eshop\DB\Order[] $orders
	 * @param \League\Csv\Writer $writer
	 * @throws \League\Csv\CannotInsertRecord
	 * @throws \League\Csv\InvalidArgument
	 */
	public function csvExportOrders(array $orders, Writer $writer)
	{
		$writer->setDelimiter(';');

		$writer->insertOne([
			'code',
			'currency',
			'createdTs',
			'receivedTs',
			'processedTs',
			'completedTs',
			'canceledTs',
			'price',
			'priceVat',
			'customer',
			'account'
		]);

		foreach ($orders as $order) {
			$writer->insertOne([
				$order->code,
				$order->purchase->currency->code,
				$order->createdTs,
				$order->receivedTs,
				$order->processedTs,
				$order->completedTs,
				$order->canceledTs,
				$order->purchase->getSumPrice(),
				$order->purchase->getSumPriceVat(),
				$order->purchase->fullname,
				$order->purchase->accountFullname
			]);
		}
	}

	public function csvExportZasilkovna(array $orders, Writer $writer): void
	{
		$writer->setDelimiter(';');

		/** @var \Eshop\DB\DeliveryRepository $deliveryRepository */
		$deliveryRepository = $this->getConnection()->findRepository(Delivery::class);

		$writer->insertOne(['version 6']);
		$writer->insertOne([]);

		foreach ($orders as $order) {
			/** @var \Eshop\DB\Order $order */
			$order = $this->one($order);

			$purchase = $order->purchase;

			if (!$purchase->zasilkovnaId) {
				continue;
			}

			$payment = $order->getPayment();

			$writer->insertOne([
				'',
				$order->code,
				$purchase->fullname,
				'',
				'',
				$purchase->email,
				$purchase->phone,
				$payment->typeCode == 'dob' ? $order->getTotalPriceVat() : '',
				$this->shopper->getCurrency(),
				$order->getTotalPriceVat(),
				'',
				$purchase->zasilkovnaId,
				$this->shopper->getProjectUrl()
			]);
		}
	}

	public function ediExport(Order $order): string
	{
		$gln = '8590804000006';
		$user = $order->purchase->customer;
		$created = new DateTime($order->createdTs);
		$string = "";
		$string .= "SYS" . \str_pad($gln, 14, " ", STR_PAD_RIGHT) . "ED  96AORDERSP\r\n";
		$string .= "HDR"
			. \str_pad($order->code, 15, " ", STR_PAD_RIGHT)
			. $created->format('Ymd')
			. \str_pad($user && $user->ediCompany ? $user->ediCompany : $gln, 17, " ")
			. \str_pad($user && $user->ediBranch ? $user->ediBranch : $gln, 17, " ")
			. \str_pad($user && $user->ediBranch ? $user->ediBranch : $gln, 17, " ")
			. \str_pad($gln, 17, " ", STR_PAD_RIGHT)
			. \str_pad(" ", 17, " ", STR_PAD_RIGHT)
			. \str_pad((\date('Ymd', \strtotime($order->canceledTs ?? $order->createdTs))) . "0000", 17, " ")
			//."!!!".$order->note."!!!************>>"
			. "" . $order->purchase->note . ""
			. "\r\n";
		$line = 1;
		foreach ($order->getGroupedItems() as $i) {
			$string .= "LIN"
				. \str_pad((string)$line, 6, " ", STR_PAD_LEFT)
				. \str_pad($i->product ? $i->product->getFullCode() : $i->getFullCode(), 25, " ", STR_PAD_RIGHT)
				. \str_pad("", 25, " ", STR_PAD_RIGHT)
				. \str_pad(\number_format($i->amount, 3, ".", ""), 12, " ", STR_PAD_LEFT)
				. \str_pad("", 15, " ")
				. "\r\n";
			$line++;
		}
		return $string;
	}

	/**
	 * @param \Eshop\DB\Customer|\Eshop\DB\Merchant|null $users
	 * @param DateTime $from
	 * @param DateTime $to
	 * @param Currency $currency
	 * @return array
	 * @throws \Exception
	 */
	public function getCustomerGroupedOrdersPrices($users, DateTime $from, DateTime $to, Currency $currency): array
	{
		/** @var Order[] $orders */
		$orders = $this->getOrdersByUserInRange($users, $from, $to)->toArray();

		/** @var CartRepository $cartRepo */
		$cartRepo = $this->getConnection()->findRepository(Cart::class);

		$data = [];
		$from->setDate((int)$from->format('Y'), (int)$from->format('m'), 1);

		while ($from <= $to) {
			$data[$from->format('Y-m')] = [
				'price' => 0,
				'priceVat' => 0
			];

			$from->modify('+1 month');
		}

		$prevDate = \count($orders) > 0 && isset($orders[\array_keys($orders)[0]]) ? (new DateTime($orders[\array_keys($orders)[0]]->createdTs))->format('Y-m') : '';
		$price = 0;
		$priceVat = 0;

		foreach ($orders as $order) {
			/** @var Cart $cart */
			$cart = $cartRepo->many()->where('fk_purchase', $order->purchase->getPK())->fetch();

			if (!$cart || $cart->currency->getPK() != $currency->getPK()) {
				continue;
			}

			if ((new DateTime($order->createdTs))->format('Y-m') != $prevDate) {
				$data[$prevDate] = [
					'price' => $price,
					'priceVat' => $priceVat
				];

				$price = 0;
				$priceVat = 0;
			}

			$price += $order->getTotalPrice();
			$priceVat += $order->getTotalPriceVat();
			$prevDate = (new DateTime($order->createdTs))->format('Y-m');
		}

		if (isset($order)) {
			$data[$prevDate] = [
				'price' => $price,
				'priceVat' => $priceVat
			];
		}

		return $data;
	}

	/**
	 * @param \Eshop\DB\Customer|\Eshop\DB\Merchant|null $users
	 * @param DateTime $from
	 * @param DateTime $to
	 * @param Currency $currency
	 * @return array
	 */
	public function getCustomerOrdersCategoriesGroupedByAmountPercentage($users, DateTime $from, DateTime $to, Currency $currency): array
	{
		/** @var Order[] $orders */
		$orders = $this->getOrdersByUserInRange($users, $from, $to)->toArray();

		/** @var CategoryRepository $categoryRepo */
		$categoryRepo = $this->getConnection()->findRepository(Category::class);

		/** @var CartRepository $cartRepo */
		$cartRepo = $this->getConnection()->findRepository(Cart::class);

		$rootCategories = $categoryRepo->many()
			->where('fk_ancestor IS NULL')
			->toArrayOf('name');

		foreach ($rootCategories as $key => $category) {
			$rootCategories[$key] = [
				'name' => $category,
				'amount' => 0
			];
		}

		$rootCategories[null] = [
			'name' => $this->translator->translate('.notAssigned', 'Nepřiřazeno'),
			'amount' => 0
		];

		$sum = 0;

		foreach ($orders as $order) {
			/** @var Cart $cart */
			$cart = $cartRepo->many()->where('fk_purchase', $order->purchase->getPK())->fetch();

			if (!$cart || $cart->currency->getPK() != $currency->getPK()) {
				continue;
			}

			$items = $order->purchase->getItems();

			foreach ($items as $item) {
				$category = $item->product ? $item->product->getPrimaryCategory() : null;
				$sum += $item->amount;

				if (!$category) {
					$rootCategories[null]['amount'] += $item->amount;
					continue;
				}

				$root = $categoryRepo->getRootCategoryOfCategory($category);
				$rootCategories[$root->getPK()]['amount'] += $item->amount;
			}
		}

		$empty = true;

		foreach ($rootCategories as $key => $category) {
			if ($sum != 0) {
				$empty = false;
				$rootCategories[$key]['share'] = \round($category['amount'] / (float)$sum * 100);
			} else {
				$rootCategories[$key]['share'] = 0;
			}
		}

		return $empty ? [] : $rootCategories;
	}

	/**
	 * @param \Eshop\DB\Customer|\Eshop\DB\Merchant|null $users
	 * @param DateTime $from
	 * @param DateTime $to
	 * @param Currency $currency
	 * @return array
	 */
	public function getCustomerOrdersTopProductsByAmount($users, DateTime $from, DateTime $to, Currency $currency): array
	{
		/** @var Order[] $orders */
		$orders = $this->getOrdersByUserInRange($users, $from, $to)->toArray();

		/** @var CartRepository $cartRepo */
		$cartRepo = $this->getConnection()->findRepository(Cart::class);

		$data = [];

		foreach ($orders as $order) {
			/** @var Cart $cart */
			$cart = $cartRepo->many()->where('fk_purchase', $order->purchase->getPK())->fetch();

			if (!$cart || $cart->currency->getPK() != $currency->getPK()) {
				continue;
			}

			$items = $order->purchase->getItems();

			foreach ($items as $item) {
				$code = $item->product ? $item->product->getFullCode() : $item->getFullCode();

				if (isset($data[$code])) {
					$data[$code]['amount'] += $item->amount;
					$data[$code]['priceSum'] += $item->getPriceSum();
					$data[$code]['priceSumVat'] += $item->getPriceVatSum();
				} else {
					$data[$code] = [
						'product' => $item->product ?? null,
						'item' => $item,
						'amount' => $item->amount,
						'priceSum' => $item->getPriceSum(),
						'priceSumVat' => $item->getPriceVatSum(),
					];
				}
			}
		}

		\uasort($data, function ($a, $b) {
			if ($a['amount'] == $b['amount']) {
				return 0;
			}

			return $a['amount'] < $b['amount'];
		});

		return \array_slice($data, 0, 5);
	}

	/**
	 * @param \Eshop\DB\Customer|\Eshop\DB\Merchant|null $user
	 * @param \Nette\Utils\DateTime $from
	 * @param \Nette\Utils\DateTime $to
	 * @return \StORM\Collection
	 */
	public function getOrdersByUserInRange($user, DateTime $from, DateTime $to): ?Collection
	{
		$from->setTime(0, 0);
		$to->setTime(23, 59, 59);
		$fromString = $from->format('Y-m-d\TH:i:s');
		$toString = $to->format('Y-m-d\TH:i:s');

		$collection = $this->many()
			->select(["date" => "DATE_FORMAT(this.createdTs, '%Y-%m')"])
			->where('this.completedTs IS NOT NULL')
			->where('this.createdTs >= :from AND this.createdTs <= :to', ['from' => $fromString, 'to' => $toString])
			->orderBy(["date"]);

		if ($user) {
			if ($user instanceof Merchant) {
				/** @var MerchantRepository $merchantRepo */
				$merchantRepo = $this->getConnection()->findRepository(Merchant::class);
				$customers = $merchantRepo->getMerchantCustomers($user);

				$collection->where('purchase.fk_customer', \array_keys($customers->toArray()));
			} elseif ($user->getAccount()) {
				/** @var CatalogPermission $perm */
				$perm = $this->catalogPermissionRepository->many()->where('fk_account', $user->getAccount()->getPK())->first();

				if ($perm->viewAllOrders) {
					$collection->where('purchase.fk_customer', $user->getPK());
				} else {
					$collection->where('purchase.fk_account', $user->getAccount()->getPK());
				}
			}
		}

		return $collection;
	}

	public function getEmailVariables(Order $order): array
	{
		$purchase = $order->purchase;
		$items = [];

		/** @var \Eshop\DB\CartItem $cartItem */
		foreach ($purchase->getItems() as $cartItem) {
			$items[$cartItem->getPK()] = $cartItem->toArray();
			$items[$cartItem->getPK()]['fullCode'] = $cartItem->getFullCode();
			$items[$cartItem->getPK()]['totalPrice'] = $cartItem->getPriceSum();
			$items[$cartItem->getPK()]['totalPriceVat'] = $cartItem->getPriceVatSum();
		}

		$deliveryPrice = $order->getDeliveryPriceVatSum();
		$paymentPrice = $order->getPaymentPriceVatSum();
		$totalDeliveryPrice = $deliveryPrice + $paymentPrice;
		$totalDeliveryPriceVat = $order->getDeliveryPriceVatSum() + $order->getPaymentPriceVatSum();

		$values = [
			'orderCode' => $order->code,
			'currencyCode' => $order->purchase->currency->code,
			'desiredShippingDate' => $purchase->desiredShippingDate,
			'internalOrderCode' => $purchase->internalOrderCode,
			'phone' => $purchase->phone,
			'email' => $purchase->email,
			'items' => $items,
			'note' => $purchase->note,
			'deliveryType' => $purchase->deliveryType ? $purchase->deliveryType->name : null,
			'deliveryPrice' => $order->deliveries->firstValue('price'),
			'totalDeliveryPrice' => $totalDeliveryPrice,
			'totalDeliveryPriceVat' => $totalDeliveryPriceVat,
			'deliveryPriceVat' => $order->deliveries->firstValue('priceVat'),
			'paymentType' => $purchase->paymentType ? $purchase->paymentType->name : null,
			'paymentPrice' => $order->payments->firstValue('price'),
			'paymentPriceVat' => $order->payments->firstValue('priceVat'),
			'billName' => $purchase->fullname,
			'billingAddress' => $purchase->billAddress ? $purchase->billAddress->jsonSerialize() : [],
			'deliveryAddress' => $purchase->deliveryAddress ? $purchase->deliveryAddress->jsonSerialize() : ($purchase->billAddress ? $purchase->billAddress->jsonSerialize() : []),
			'totalPrice' => $this->shopper->getCatalogPermission() == 'price' ? $order->getTotalPrice() : null,
			'totalPriceVat' => $this->shopper->getCatalogPermission() == 'price' ? $order->getTotalPriceVat() : null,
			'currency' => $order->purchase->currency,
			'shopper' => $this->shopper
		];

		return $values;
	}

	public function getProductUniqueOrderCountInDateRange($product, DateTime $from, DateTime $to): int
	{
		/** @var \Eshop\DB\ProductRepository $productRepository */
		$productRepository = $this->getConnection()->findRepository(Product::class);

		/** @var \Eshop\DB\CartItemRepository $cartItemRepository */
		$cartItemRepository = $this->getConnection()->findRepository(CartItem::class);

		if (!$product instanceof Product) {
			if (!$product = $productRepository->one($product)) {
				return 0;
			}
		}

		return $this->cache->load("uniqueOrderCount_" . $product->getPK(), function (&$dependencies) use ($product, $from, $to, $cartItemRepository) {
			$dependencies = [
				Cache::TAGS => 'stats',
			];

			$from->setTime(0, 0);
			$to->setTime(23, 59, 59);
			$fromString = $from->format('Y-m-d\TH:i:s');
			$toString = $to->format('Y-m-d\TH:i:s');

			return $cartItemRepository->many()
				->join(['cart' => 'eshop_cart'], 'this.fk_cart = cart.uuid')
				->join(['purchase' => 'eshop_purchase'], 'cart.fk_purchase = purchase.uuid')
				->join(['orderTable' => 'eshop_order'], 'orderTable.fk_purchase = purchase.uuid')
				->select(["date" => "DATE_FORMAT(order.createdTs, '%Y-%m')"])
				->where('orderTable.completedTs IS NOT NULL')
				->where('orderTable.createdTs >= :from AND orderTable.createdTs <= :to', ['from' => $fromString, 'to' => $toString])
				->where('this.fk_product', $product->getPK())
				->enum();
		});
	}

	/**
	 * @param \Eshop\DB\Order|string $order
	 * @return bool|null
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function isOrderApproved($order): ?bool
	{
		if (!$order instanceof Order) {
			if (!$order = $this->one($order)) {
				return false;
			}
		}

		/** @var \Eshop\DB\CartRepository $cartRepository */
		$cartRepository = $this->getConnection()->findRepository(Cart::class);

		/** @var \Eshop\DB\Cart[] $carts */
		$carts = $cartRepository->many()
			->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid')
			->join(['orders' => 'eshop_order'], 'orders.fk_purchase = purchase.uuid')
			->where('orders.uuid', $order->getPK());

		foreach ($carts as $cart) {
			if ($cart->approved == 'no') {
				return false;
			}

			if ($cart->approved == 'waiting') {
				return null;
			}
		}

		return true;
	}

	public function hasMerchantUnfinishedOrders($merchant): bool
	{
		return $this->getMerchantUnfinishedOrders($merchant)->count() > 0;
	}

	public function getMerchantUnfinishedOrders($merchant): ?Collection
	{
		if (!$merchant instanceof Merchant) {
			if (!$merchant = $this->merchantRepository->one($merchant)) {
				return null;
			}
		}

		$customers = $this->merchantRepository->getMerchantCustomers($merchant)->toArray();

		return $this->many()->where('this.completedTs IS NULL AND this.canceledTs IS NULL')
			->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid')
			->join(['customer' => 'eshop_customer'], 'purchase.fk_customer = customer.uuid')
			->where('customer.uuid', \array_keys($customers));
	}

	/**
	 * @param $order
	 * @return string|null Order::STATE
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getState($order): ?string
	{
		if (!$order instanceof Order) {
			if (!$order = $this->one($order)) {
				return null;
			}
		}

		if (!$order->completedTs && !$order->canceledTs) {
			return 'received';
		}

		if ($order->completedTs && !$order->canceledTs) {
			return 'finished';
		}

		if ($order->canceledTs) {
			return 'canceled';
		}

		return null;
	}
}
