<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\Shopper;
use League\Csv\EncloseField;
use League\Csv\Writer;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Localization\Translator;
use Nette\Utils\DateTime;
use Security\DB\Account;
use StORM\Collection;
use StORM\DIConnection;
use StORM\ICollection;
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

	private PackageRepository $packageRepository;

	private PackageItemRepository $packageItemRepository;

	private BannedEmailRepository $bannedEmailRepository;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		Storage $storage,
		Shopper $shopper,
		Translator $translator,
		MerchantRepository $merchantRepository,
		CatalogPermissionRepository $catalogPermissionRepository,
		PackageRepository $packageRepository,
		PackageItemRepository $packageItemRepository,
		BannedEmailRepository $bannedEmailRepository
	) {
		parent::__construct($connection, $schemaManager);

		$this->cache = new Cache($storage);
		$this->shopper = $shopper;
		$this->translator = $translator;
		$this->merchantRepository = $merchantRepository;
		$this->catalogPermissionRepository = $catalogPermissionRepository;
		$this->packageRepository = $packageRepository;
		$this->packageItemRepository = $packageItemRepository;
		$this->bannedEmailRepository = $bannedEmailRepository;
	}

	/**
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

	public function csvPPCExport(ICollection $orders, Writer $writer, array $columns = [], string $delimiter = ';', ?array $header = null): void
	{
		$writer->setDelimiter($delimiter);

		EncloseField::addTo($writer, "\t\22");

		if ($header) {
			$writer->insertOne($header);
		}

		/**
		 * @var \Eshop\DB\Order $order
		 * @phpstan-ignore-next-line specific situation
		 */
		while ($order = $orders->fetch()) {
			foreach ($order->purchase->getItems() as $item) {
				$row = [];

				foreach (\array_keys($columns) as $columnKey) {
					if ($columnKey === 'customer') {
						$row[] = $order->purchase->account ? $order->purchase->account->login : ($order->purchase->accountEmail ?? $order->purchase->email);
					} elseif ($columnKey === 'state') {
						$row[] = $this->getState($order);
					} elseif ($columnKey === 'totalPriceVat') {
						$row[] = $item->getPriceVatSum();
					} elseif ($columnKey === 'productName') {
						$row[] = $item->productName;
					} elseif ($columnKey === 'productPrice') {
						$row[] = $item->price;
					} elseif ($columnKey === 'productPriceVat') {
						$row[] = $item->priceVat;
					} elseif ($columnKey === 'productVat') {
						$row[] = $item->vatPct;
					} elseif ($columnKey === 'shippingName') {
						$row[] = $order->purchase->deliveryType ? $order->purchase->deliveryType->name : null;
					} elseif ($columnKey === 'shippingPriceVat') {
						$row[] = $order->getDeliveryPriceVatSum();
					} elseif ($columnKey === 'paymentMethod') {
						$row[] = $order->getPayment() ? $order->getPayment()->getTypeName() : null;
					} else {
						$row[] = $order->getValue($columnKey) === false ? '0' : $order->getValue($columnKey);
					}
				}

				$writer->insertOne($row);
			}
		}
	}

	/**
	 * @param string|\Eshop\DB\Order|null $order
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

		if ($this->shopper->getEditOrderAfterCreation() && !$order->receivedTs) {
			return Order::STATE_OPEN;
		}

		if (!$order->completedTs && !$order->canceledTs) {
			return Order::STATE_RECEIVED;
		}

		if ($order->completedTs && !$order->canceledTs) {
			return Order::STATE_COMPLETED;
		}

		if ($order->canceledTs) {
			return Order::STATE_CANCELED;
		}

		return null;
	}

	public function getCollectionByState(string $state): Collection
	{
		if ($state === Order::STATE_OPEN) {
			return $this->many()->where('this.receivedTs IS NULL AND this.completedTs IS NULL AND this.canceledTs IS NULL')
				->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid')
				->join(['customer' => 'eshop_customer'], 'purchase.fk_customer = customer.uuid');
		}

		if ($state === Order::STATE_RECEIVED) {
			return $this->many()->where('this.receivedTs IS NOT NULL AND this.completedTs IS NULL AND this.canceledTs IS NULL')
				->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid')
				->join(['customer' => 'eshop_customer'], 'purchase.fk_customer = customer.uuid');
		}

		if ($state === Order::STATE_COMPLETED) {
			return $this->many()->where('this.receivedTs IS NOT NULL AND this.completedTs IS NOT NULL AND this.canceledTs IS NULL')
				->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid')
				->join(['customer' => 'eshop_customer'], 'purchase.fk_customer = customer.uuid');
		}

		if ($state === Order::STATE_CANCELED) {
			return $this->many()->where('this.receivedTs IS NOT NULL AND this.canceledTs IS NOT NULL')
				->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid')
				->join(['customer' => 'eshop_customer'], 'purchase.fk_customer = customer.uuid');
		}

		throw new \DomainException("Invalid state: $state");
	}

	public function csvExport(Order $order, Writer $writer): void
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
			'note',
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
				$item->note,
			]);
		}
	}

	public function excelExport(Order $order, \XLSXWriter $writer, string $sheetName = 'sheet'): void
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
				$item->note,
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
	public function excelExportAll(array $orders, \XLSXWriter $writer): void
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

		if (\count($orders) === 0) {
			return;
		}

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
					$order->purchase->account ? $order->purchase->account->fullname : $order->purchase->accountFullname,
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
	public function csvExportOrders(array $orders, Writer $writer): void
	{
		$writer->setDelimiter(';');

		$writer->insertOne([
			'code',
			'currency',
			'createdTs',
			'receivedTs',
			'completedTs',
			'canceledTs',
			'price',
			'priceVat',
			'customer',
			'account',
		]);

		foreach ($orders as $order) {
			$writer->insertOne([
				$order->code,
				$order->purchase->currency->code,
				$order->createdTs,
				$order->receivedTs,
				$order->completedTs,
				$order->canceledTs,
				$order->getTotalPrice(),
				$order->getTotalPriceVat(),
				$order->purchase->fullname,
				$order->purchase->accountFullname,
			]);
		}
	}

	public function csvExportZasilkovna(array $orders, Writer $writer): void
	{
		$writer->setDelimiter(';');
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
				$payment->typeCode === 'dob' ? $order->getTotalPriceVat() : '',
				$this->shopper->getCurrency(),
				$order->getTotalPriceVat(),
				'',
				$purchase->zasilkovnaId,
				$this->shopper->getProjectUrl(),
			]);
		}
	}

	public function ediExport(Order $order): string
	{
		$gln = '8590804000006';
		$user = $order->purchase->customer;
		$created = new DateTime($order->createdTs);
		$string = '';
		$string .= 'SYS' . \str_pad($gln, 14, ' ', \STR_PAD_RIGHT) . "ED  96AORDERSP\r\n";
		$string .= 'HDR'
			. \str_pad($order->code, 15, ' ', \STR_PAD_RIGHT)
			. $created->format('Ymd')
			. \str_pad($user && $user->ediCompany ? $user->ediCompany : $gln, 17, ' ')
			. \str_pad($user && $user->ediBranch ? $user->ediBranch : $gln, 17, ' ')
			. \str_pad($user && $user->ediBranch ? $user->ediBranch : $gln, 17, ' ')
			. \str_pad($gln, 17, ' ', \STR_PAD_RIGHT)
			. \str_pad(' ', 17, ' ', \STR_PAD_RIGHT)
			. \str_pad((\date('Ymd', \strtotime($order->canceledTs ?? $order->createdTs))) . '0000', 17, ' ')
			//."!!!".$order->note."!!!************>>"
			. '' . $order->purchase->note . ''
			. "\r\n";
		$line = 1;

		foreach ($order->getGroupedItems() as $i) {
			$string .= 'LIN'
				. \str_pad((string)$line, 6, ' ', \STR_PAD_LEFT)
				. \str_pad($i->product ? $i->product->getFullCode() : $i->getFullCode(), 25, ' ', \STR_PAD_RIGHT)
				. \str_pad('', 25, ' ', \STR_PAD_RIGHT)
				. \str_pad(\number_format($i->amount, 3, '.', ''), 12, ' ', \STR_PAD_LEFT)
				. \str_pad('', 15, ' ')
				. "\r\n";
			$line++;
		}

		return $string;
	}

	public function getCustomerTotalTurnover(Customer $customer, ?DateTime $from = null, ?DateTime $to = null): float
	{
		$from ??= new DateTime('1970-01-01');
		$to ??= new DateTime();

		$orders = $this->getOrdersByUserInRange($customer, $from, $to);

		$total = 0.0;

		$vat = false;

		if ($this->shopper->getShowPrice() === 'withVat') {
			$vat = true;
		}

		while ($order = $orders->fetch()) {
			/** @var \Eshop\DB\Order $order */

			$price = $vat ? $order->getTotalPriceVat() : $order->getTotalPrice();

			$currency = $order->purchase->currency;

			$total += $currency->isConversionEnabled() ? \round($price * $currency->convertRatio, $currency->calculationPrecision) : $price;
		}

		return $total;
	}

	/**
	 * @param \Eshop\DB\Customer|\Eshop\DB\Merchant|null $user
	 * @param \Nette\Utils\DateTime $from
	 * @param \Nette\Utils\DateTime $to
	 */
	public function getOrdersByUserInRange($user, DateTime $from, DateTime $to): ?Collection
	{
		$from->setTime(0, 0);
		$to->setTime(23, 59, 59);
		$fromString = $from->format('Y-m-d\TH:i:s');
		$toString = $to->format('Y-m-d\TH:i:s');

		$collection = $this->many()
			->select(['date' => "DATE_FORMAT(this.createdTs, '%Y-%m')"])
			->where('this.receivedTs IS NOT NULL AND this.completedTs IS NOT NULL AND this.canceledTs IS NULL')
			->where('this.createdTs >= :from AND this.createdTs <= :to', ['from' => $fromString, 'to' => $toString])
			->orderBy(['date']);

		if ($user) {
			if ($user instanceof Merchant) {
				/** @var \Eshop\DB\MerchantRepository $merchantRepo */
				$merchantRepo = $this->getConnection()->findRepository(Merchant::class);
				$customers = $merchantRepo->getMerchantCustomers($user);

				$collection->where('purchase.fk_customer', \array_keys($customers->toArray()));
			} elseif ($user->getAccount()) {
				/** @var \Eshop\DB\CatalogPermission $perm */
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

	/**
	 * @param array<\Eshop\DB\Order> $orders
	 * @param \Nette\Utils\DateTime $from
	 * @param \Nette\Utils\DateTime $to
	 * @param \Eshop\DB\Currency $currency
	 * @return array<string, array<string, float>>
	 * @throws \Exception
	 */
	public function getGroupedOrdersPrices(array $orders, DateTime $from, DateTime $to, Currency $currency): array
	{
		$from = $from->modifyClone();
		$to = $to->modifyClone();

		/** @var \Eshop\DB\CartRepository $cartRepo */
		$cartRepo = $this->getConnection()->findRepository(Cart::class);

		$data = [];
		$from->setDate((int)$from->format('Y'), (int)$from->format('m'), 1);

		while ($from <= $to) {
			$data[$from->format('Y-m')] = [
				'price' => 0,
				'priceVat' => 0,
			];

			$from->modify('+1 month');
		}

		$prevDate = \count($orders) > 0 && isset($orders[\array_keys($orders)[0]]) ? (new DateTime($orders[\array_keys($orders)[0]]->createdTs))->format('Y-m') : '';
		$price = 0;
		$priceVat = 0;

		foreach ($orders as $order) {
			/** @var \Eshop\DB\Cart|null $cart */
			$cart = $cartRepo->many()->where('fk_purchase', $order->purchase->getPK())->first();

			if (!$cart || $cart->currency->getPK() !== $currency->getPK()) {
				continue;
			}

			if ((new DateTime($order->createdTs))->format('Y-m') !== $prevDate) {
				$data[$prevDate] = [
					'price' => $price,
					'priceVat' => $priceVat,
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
				'priceVat' => $priceVat,
			];
		}

		return $data;
	}

	/**
	 * @param array<\Eshop\DB\Order> $orders
	 * @return float[]
	 */
	public function getAverageOrderPrice(array $orders): array
	{
		$total = 0;
		$totalVat = 0;
		$count = \count($orders);

		if ($count === 0) {
			return [0, 0];
		}

		foreach ($orders as $order) {
			/** @var \Eshop\DB\Order $order */
			$total += $order->getTotalPrice();
			$totalVat += $order->getTotalPriceVat();
		}

		return [$total / $count, $totalVat / $count];
	}

	/**
	 * @param array<\Eshop\DB\Order> $orders
	 * @param \Eshop\DB\Currency $currency
	 * @return array<string, array<string, float|string>>
	 */
	public function getOrdersCategoriesGroupedByAmountPercentage(array $orders, Currency $currency): array
	{
		/** @var \Eshop\DB\CategoryRepository $categoryRepo */
		$categoryRepo = $this->getConnection()->findRepository(Category::class);

		/** @var \Eshop\DB\CartRepository $cartRepo */
		$cartRepo = $this->getConnection()->findRepository(Cart::class);

		$rootCategories = [];

		$rootCategories[null] = [
			'name' => $this->translator->translate('.notAssigned', 'Nepřiřazeno'),
			'amount' => 0,
		];

		$sum = 0;

		foreach ($orders as $order) {
			/** @var \Eshop\DB\Cart|null $cart */
			$cart = $cartRepo->many()->where('fk_purchase', $order->purchase->getPK())->first();

			if (!$cart || $cart->currency->getPK() !== $currency->getPK()) {
				continue;
			}

			$items = $order->purchase->getItems();

			foreach ($items as $item) {
				$category = $item->product ? $item->product->primaryCategory : null;
				$sum += $item->amount;

				if (!$category) {
					$rootCategories[null]['amount'] += $item->amount;

					continue;
				}

				$root = $categoryRepo->getRootCategoryOfCategory($category);

				if (!isset($rootCategories[$root->getPK()])) {
					$rootCategories[$root->getPK()] = [
						'name' => $root->name,
						'amount' => 0,
					];
				}

				$rootCategories[$root->getPK()]['amount'] += $item->amount;
			}
		}

		$empty = true;

		foreach ($rootCategories as $key => $category) {
			if ($sum !== 0) {
				$empty = false;
				$rootCategories[$key]['share'] = \round($category['amount'] / (float)$sum * 100);
			} else {
				$rootCategories[$key]['share'] = 0;
			}
		}

		return $empty ? [] : $rootCategories;
	}

	/**
	 * @param array<\Eshop\DB\Order> $orders
	 * @param \Eshop\DB\Currency $currency
	 * @return array<string, array<string, \Eshop\DB\CartItem|\Eshop\DB\Product|float|int|null>>
	 */
	public function getOrdersTopProductsByAmount(array $orders, Currency $currency): array
	{
		/** @var \Eshop\DB\CartRepository $cartRepo */
		$cartRepo = $this->getConnection()->findRepository(Cart::class);

		$data = [];

		foreach ($orders as $order) {
			/** @var \Eshop\DB\Cart|null $cart */
			$cart = $cartRepo->many()->where('fk_purchase', $order->purchase->getPK())->first();

			if (!$cart || $cart->currency->getPK() !== $currency->getPK()) {
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
			return $a['amount'] <=> $b['amount'];
		});

		return \array_slice($data, 0, 5);
	}

	public function getOrdersByUser($user): ?Collection
	{
		$collection = $this->many()
			->where('this.receivedTs IS NOT NULL AND this.completedTs IS NOT NULL AND this.canceledTs IS NULL')
			->orderBy(['this.createdTs']);

		if ($user) {
			if ($user instanceof Merchant) {
				/** @var \Eshop\DB\MerchantRepository $merchantRepo */
				$merchantRepo = $this->getConnection()->findRepository(Merchant::class);
				$customers = $merchantRepo->getMerchantCustomers($user);

				$collection->where('purchase.fk_customer', \array_keys($customers->toArray()));
			} elseif ($user->getAccount()) {
				/** @var \Eshop\DB\CatalogPermission $perm */
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

	/**
	 * @param \Eshop\DB\Order $order
	 * @return array<string, array|bool|\Eshop\DB\Currency|\Eshop\DB\DiscountCoupon|\Eshop\DB\Order|float|string|null>
	 * @throws \StORM\Exception\NotFoundException
	 */
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

			if ($this->shopper->getCatalogPermission() !== 'price') {
				continue;
			}

			if ($this->shopper->getShowVat() && $this->shopper->getShowWithoutVat()) {
				$items[$cartItem->getPK()]['totalPricePref'] = $this->shopper->showPriorityPrices() === 'withVat' ? $cartItem->getPriceVatSum() : $cartItem->getPriceSum();
			} else {
				if ($this->shopper->getShowVat()) {
					$items[$cartItem->getPK()]['totalPricePref'] = $cartItem->getPriceVatSum();
				}

				if ($this->shopper->getShowWithoutVat()) {
					$items[$cartItem->getPK()]['totalPricePref'] = $cartItem->getPriceSum();
				}
			}
		}

		$deliveryPrice = $order->getDeliveryPriceVatSum();
		$paymentPrice = $order->getPaymentPriceVatSum();
		$totalDeliveryPrice = $deliveryPrice + $paymentPrice;
		$totalDeliveryPriceVat = $order->getDeliveryPriceVatSum() + $order->getPaymentPriceVatSum();

		$values = [
			'orderCode' => $order->code,
			'orderState' => $this->getState($order),
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
			'totalPrice' => $this->shopper->getCatalogPermission() === 'price' ? $order->getTotalPrice() : null,
			'totalPriceVat' => $this->shopper->getCatalogPermission() === 'price' ? $order->getTotalPriceVat() : null,
			'currency' => $order->purchase->currency,
			'discountCoupon' => $order->getDiscountCoupon(),
			'order' => $order,
			'withVat' => false,
			'withoutVat' => false,
			'catalogPermission' => $this->shopper->getCatalogPermission(),
			'priorityPrices' => $this->shopper->showPriorityPrices(),
		];

		if ($this->shopper->getCatalogPermission() === 'price') {
			if ($this->shopper->getShowVat() && $this->shopper->getShowWithoutVat()) {
				if ($this->shopper->showPriorityPrices() === 'withVat') {
					$values['totalDeliveryPricePref'] = $totalDeliveryPriceVat;
					$values['paymentPricePref'] = $order->payments->firstValue('priceVat');
					$values['totalPricePref'] = $order->getTotalPriceVat();
					$values['withVat'] = true;
				} else {
					$values['totalDeliveryPricePref'] = $totalDeliveryPrice;
					$values['paymentPricePref'] = $order->payments->firstValue('price');
					$values['totalPricePref'] = $order->getTotalPrice();
					$values['withoutVat'] = true;
				}
			} else {
				if ($this->shopper->getShowVat()) {
					$values['totalDeliveryPricePref'] = $totalDeliveryPriceVat;
					$values['paymentPricePref'] = $order->payments->firstValue('priceVat');
					$values['totalPricePref'] = $order->getTotalPriceVat();
					$values['withVat'] = true;
				}

				if ($this->shopper->getShowWithoutVat()) {
					$values['totalDeliveryPricePref'] = $totalDeliveryPrice;
					$values['paymentPricePref'] = $order->payments->firstValue('price');
					$values['totalPricePref'] = $order->getTotalPrice();
					$values['withoutVat'] = true;
				}
			}
		}

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

		return $this->cache->load('uniqueOrderCount_' . $product->getPK(), function (&$dependencies) use ($product, $from, $to, $cartItemRepository) {
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
				->select(['date' => "DATE_FORMAT(order.createdTs, '%Y-%m')"])
				->where('orderTable.completedTs IS NOT NULL')
				->where('orderTable.createdTs >= :from AND orderTable.createdTs <= :to', ['from' => $fromString, 'to' => $toString])
				->where('this.fk_product', $product->getPK())
				->enum();
		});
	}

	/**
	 * @param \Eshop\DB\Order|string $order
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
			if ($cart->approved === 'no') {
				return false;
			}

			if ($cart->approved === 'waiting') {
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
	 * @param \Eshop\DB\Order $order
	 * @param string $status
	 * @todo
	 */
	public function changeState(Order $order, string $status): void
	{
		unset($order);
		unset($status);
		// in array
//		if (1) {
//		}
//
//		$order->update([$status . 'Ts' => (string)new DateTime()]);
//
//		Arrays::invoke($this->onChangeState);
	}

	/**
	 * @param \Eshop\DB\Order $order
	 * @todo
	 */
	public function sendStateEmail(Order $order): void
	{
		unset($order);

		// @TODO
	}

	public function getFirstPackageByCartItem(CartItem $cartItem): ?Package
	{
		return $this->packageRepository->many()
			->join(['packageItem' => 'eshop_packageitem'], 'this.uuid = packageItem.fk_package')
			->where('packageItem.fk_cartItem', $cartItem->getPK())
			->orderBy(['this.id' => 'ASC'])
			->first();
	}

	public function getFirstPackageItemByCartItem(CartItem $cartItem): ?PackageItem
	{
		return $this->packageItemRepository->many()->where('this.fk_cartItem', $cartItem->getPK())->first();
	}

	public function getLoyaltyProgramPointsGainByOrderAndCustomer(Order $order, Customer $customer): ?float
	{
		if (!$loyaltyProgram = $customer->getValue('loyaltyProgram')) {
			return null;
		}

		$pointsGain = 0.0;

		/** @var \Eshop\DB\CartItem $cartItem */
		foreach ($order->purchase->getItems()->join(['loyaltyProgramProduct' => 'eshop_loyaltyprogramproduct'], 'this.fk_product = loyaltyProgramProduct.fk_product')
					 ->where('loyaltyProgramProduct.fk_loyaltyProgram', $loyaltyProgram)
					 ->select(['pointsGain' => 'loyaltyProgramProduct.points']) as $cartItem) {
			$pointsGain += $cartItem->amount * $cartItem->getValue('pointsGain');
		}

		return $pointsGain;
	}

	public function cancelOrderById(string $orderId): void
	{
		$this->cancelOrder($this->one($orderId, true));
	}

	public function cancelOrder(Order $order): void
	{
		$order->update(['canceledTs' => (string)new DateTime()]);
	}

	public function banOrderById(string $orderId): void
	{
		$this->banOrder($this->one($orderId, true));
	}

	public function banOrder(Order $order): void
	{
		$this->cancelOrder($order);

		$this->bannedEmailRepository->syncOne(['email' => $order->purchase->email]);
	}

	public function getLastOrder(): ?Order
	{
		return $this->many()->orderBy(['this.createdTs' => 'DESC'])->first();
	}

	/**
	 * @param array<\Eshop\DB\Order> $orders
	 * @param \Eshop\DB\DiscountCoupon[] $discountCoupons
	 * @return array<string, float>
	 */
	public function getDiscountCouponsUsage(array $orders, array $discountCoupons): array
	{
		$count = \count($orders);

		$usedCoupons = [];

		foreach ($orders as $order) {
			/** @var \Eshop\DB\Order $order */
			if (!$discountCoupon = $order->getDiscountCoupon()) {
				continue;
			}

			$usedCoupons[$discountCoupon->getPK()] = isset($usedCoupons[$discountCoupon->getPK()]) ? $usedCoupons[$discountCoupon->getPK()]++ : 1;
		}

		foreach ($discountCoupons as $discountCoupon) {
			$usedCoupons[$discountCoupon->getPK()] = isset($usedCoupons[$discountCoupon->getPK()]) ? \round($usedCoupons[$discountCoupon->getPK()] / $count * 100, 2) : 0;
		}

		return $usedCoupons;
	}
}
