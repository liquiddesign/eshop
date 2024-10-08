<?php

declare(strict_types=1);

namespace Eshop\DB;

use Admin\DB\Administrator;
use Admin\DB\IGeneralAjaxRepository;
use Base\ShopsConfig;
use Carbon\Carbon;
use Common\DB\IGeneralRepository;
use Eshop\Admin\HelperClasses\MultipleOperationResult;
use Eshop\Admin\SettingsPresenter;
use Eshop\Integration\Integrations;
use Eshop\ShopperUser;
use League\Csv\EncloseField;
use League\Csv\Writer;
use Messages\DB\Template;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Container;
use Nette\Localization\Translator;
use Nette\Mail\Mailer;
use Nette\Utils\Arrays;
use Nette\Utils\DateTime;
use Nette\Utils\Strings;
use Security\DB\Account;
use StORM\Collection;
use StORM\DIConnection;
use StORM\ICollection;
use StORM\SchemaManager;
use Web\DB\SettingRepository;

/**
 * @extends \StORM\Repository<\Eshop\DB\Order>
 */
class OrderRepository extends \StORM\Repository implements IGeneralRepository, IGeneralAjaxRepository
{
	/** @var array<callable(\Eshop\DB\Order): bool> */
	public array $onBeforeOrderOpened = [];

	/** @var array<callable(\Eshop\DB\Order): bool> */
	public array $onBeforeOrderReceived = [];

	/** @var array<callable(\Eshop\DB\Order): void> */
	public array $onOrderOpened = [];

	/** @var array<callable(\Eshop\DB\Order): void> */
	public array $onOrderReceived = [];

	/** @var array<callable(\Eshop\DB\Order): bool> */
	public array $onBeforeOrderCompleted = [];

	/** @var array<callable(\Eshop\DB\Order): void> */
	public array $onOrderCompleted = [];

	/** @var array<callable(\Eshop\DB\Order): bool> */
	public array $onBeforeOrderCanceled = [];

	/** @var array<callable(\Eshop\DB\Order): void> */
	public array $onOrderCanceled = [];

	/** @var array<callable(\Eshop\DB\Order): bool> */
	public array $onBeforeOrderBanned = [];

	/** @var array<callable(\Eshop\DB\Order): void> */
	public array $onOrderBanned = [];

	/** @var array<callable(\Eshop\DB\Order): bool> */
	public array $onBeforeOrderUnBanned = [];

	/** @var array<callable(\Eshop\DB\Order): void> */
	public array $onOrderUnBanned = [];

	/** @var array<callable(\Eshop\DB\Order): bool> */
	public array $onBeforeOrderPaused = [];

	/** @var array<callable(\Eshop\DB\Order): void> */
	public array $onOrderPaused = [];

	/** @var array<callable(\Eshop\DB\Order): bool> */
	public array $onBeforeOrderUnPaused = [];

	/** @var array<callable(\Eshop\DB\Order): void> */
	public array $onOrderUnPaused = [];

	/** @var array<callable(\Eshop\DB\Order, \Eshop\DB\Delivery): void> */
	public array $onOrderDeliveryChanged = [];

	/** @var array<callable(\Eshop\DB\Order, \Eshop\DB\Payment): void> */
	public array $onOrderPaymentChanged = [];

	/** @var array<callable(\Eshop\DB\Payment): void> */
	public array $onOrderPaymentPaid = [];

	/** @var array<callable(\Eshop\DB\Payment): void> */
	public array $onOrderPaymentCanceled = [];

	/** @var array<callable(\Eshop\DB\Order, array<\Eshop\DB\Order>): void> */
	public array $onOrdersMergedAll = [];

	/** @var array<callable(\Eshop\DB\Order, \Eshop\DB\Order): void> */
	public array $onOrdersMergedOne = [];

	private Cache $cache;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		Storage $storage,
		private readonly ShopperUser $shopperUser,
		private readonly Translator $translator,
		private readonly MerchantRepository $merchantRepository,
		private readonly CatalogPermissionRepository $catalogPermissionRepository,
		private readonly PackageRepository $packageRepository,
		private readonly PackageItemRepository $packageItemRepository,
		private readonly BannedEmailRepository $bannedEmailRepository,
		private readonly Container $container,
		private readonly OrderLogItemRepository $orderLogItemRepository,
		private readonly SettingRepository $settingRepository,
		private readonly Integrations $integrations,
		private readonly ShopsConfig $shopsConfig,
		private readonly PricelistRepository $pricelistRepository,
	) {
		parent::__construct($connection, $schemaManager);

		$this->cache = new Cache($storage);
	}

	public function filterInternalRibbon($value, ICollection $collection): void
	{
		$collection->join(['internalRibbons' => 'eshop_internalribbon_nxn_eshop_order'], 'internalRibbons.fk_order=this.uuid');

		$value === false ? $collection->where('internalRibbons.fk_internalRibbon IS NULL') : $collection->where('internalRibbons.fk_internalRibbon', $value);
	}

	/**
	 * @param \Eshop\DB\Customer|array<string>|null $customer
	 * @param \Eshop\DB\Merchant|null $merchant
	 * @param \Security\DB\Account|null $account
	 * @return \StORM\Collection<\Eshop\DB\Order>
	 */
	public function getFinishedOrders(Customer|null|array $customer = null, ?Merchant $merchant = null, ?Account $account = null): Collection
	{
		$collection = $this->many()->where('this.completedTs IS NOT NULL AND this.canceledTs IS NULL');
		$collection->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid');
		$collection->join(['customer' => 'eshop_customer'], 'customer.uuid = purchase.fk_customer');
		$collection->join(['nxn' => 'eshop_merchant_nxn_eshop_customer'], 'customer.uuid = nxn.fk_customer');

		if ($customer) {
			$collection->where('purchase.fk_customer', $customer instanceof Customer ? $customer->getPK() : $customer);
		} elseif ($merchant) {
			$collection->where('nxn.fk_merchant', $merchant);
		}

		if ($account) {
			$collection->where('purchase.fk_account', $account);
		}

		return $collection;
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\Order> $orders
	 * @return \Eshop\Admin\HelperClasses\MultipleOperationResult<\Eshop\DB\Order>
	 */
	public function recalculateOrderPricesMultiple(Collection $orders): MultipleOperationResult
	{
		$result = new MultipleOperationResult();

		foreach ($orders as $order) {
			try {
				$this->recalculateOrderPrices($order);

				$result->addCompleted($order);
			} catch (\Exception $e) {
				if ($e->getCode() === 1 || $e->getCode() === 2) {
					$result->addIgnored($order);
				} else {
					$result->addFailed($order);
				}
			}
		}

		return $result;
	}

	/**
	 * Recalculate all CartItem in Order with new prices based on Customer in order
	 * WARNING! Does not recalculate with related items!
	 */
	public function recalculateOrderPrices(Order $order, Administrator|null $admin = null): void
	{
		/** @var \Eshop\DB\ProductRepository $productRepository */
		$productRepository = $this->getConnection()->findRepository(Product::class);

		$cartItems = $order->purchase->getItems();
		$customer = $order->purchase->customer;
		$currency = $order->purchase->currency;
		$currencySymbol = $currency->symbol;
		$calculationPrecision = $currency->calculationPrecision;
		$calculationPrecisionModifier = 1;

		for ($i = 0; $i < $calculationPrecision; $i++) {
			$calculationPrecisionModifier *= 0.1;
		}

		$discountCoupon = $order->getDiscountCoupon();

		if (!$customer) {
			throw new \Exception('Přepočet cen lze provádět jen pokud má objednávka přiřazeného registrovaného zákazníka!', 1);
		}

		$customerGroup = $customer->group;
		/** @var array<\Eshop\DB\Pricelist> $priceLists */
		$priceLists = $this->pricelistRepository->getCustomerPricelists($customer, $currency, $this->shopperUser->getCountry(), $discountCoupon)->toArray();

		$visibilityLists = $customer->getVisibilityLists();

		$this->shopsConfig->filterShopsInShopEntityCollection($visibilityLists);

		$visibilityLists = $visibilityLists->where('this.hidden', false)->orderBy(['this.priority' => 'ASC'])->toArray();

		$skipped = true;

		foreach ($cartItems as $cartItem) {
			$cartItemOld = clone $cartItem;

			if (!$cartItem->getValue('product')) {
				continue;
			}

			$product = $productRepository->getProducts($priceLists, $customer, customerGroup: $customerGroup, visibilityLists: $visibilityLists, currency: $currency)
				->where('this.uuid', $cartItem->getValue('product'))
				->first();

			if (!$product) {
				continue;
			}

			$isPriceChange = \abs($cartItemOld->price - $product->getPrice()) > $calculationPrecisionModifier;

			if ($isPriceChange) {
				$cartItem->update([
					'price' => $product->getPrice(),
					'priceVat' => $product->getPriceVat(),
				]);

				$skipped = false;
			}

			if (!$admin || !$isPriceChange) {
				continue;
			}

			$priceChange = " | Cena z $cartItemOld->price $currencySymbol na $cartItem->price $currencySymbol";

			$this->orderLogItemRepository->createLog($order, OrderLogItem::ITEM_EDITED, $cartItem->productName . $priceChange, $admin);
		}

		if ($skipped) {
			throw new \Exception('Přeskočeno, žádné položky nevyžadují úpravu nebo některé položky nebylo možné upravit', 2);
		}
	}

	/**
	 * Merge orders by moving cart items from orders to target order and cancelling old orders. This leave old orders empty.
	 * @TODO better merging with copying
	 * @param \Eshop\DB\Order $targetOrder
	 * @param array<\Eshop\DB\Order> $orders
	 * @param \Admin\DB\Administrator|null $administrator
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function mergeOrders(Order $targetOrder, array $orders, ?Administrator $administrator = null): void
	{
		/** @var \Eshop\DB\Cart $targetCart */
		$targetCart = $targetOrder->purchase->carts->first();

		/** @var \Eshop\DB\Package $targetPackage */
		$targetPackage = $targetOrder->packages->first();

		foreach ($orders as $oldOrder) {
			foreach ($oldOrder->purchase->carts as $oldCart) {
				foreach ($oldCart->items as $item) {
					$item->update(['cart' => $targetCart->getPK()]);
				}
			}

			foreach ($oldOrder->packages as $oldPackage) {
				foreach ($oldPackage->items as $packageItem) {
					$packageItem->update(['package' => $targetPackage->getPK()]);
				}

				$oldPackage->delete();
			}

			$this->cancelOrder($oldOrder);

			$this->orderLogItemRepository->createLog($oldOrder, OrderLogItem::CANCELED, 'Spojeno s obj.: ' . $oldOrder->code, $administrator);
			$this->orderLogItemRepository->createLog($targetOrder, OrderLogItem::MERGED, $oldOrder->code, $administrator);

			Arrays::invoke($this->onOrdersMergedOne, $targetOrder, $oldOrder);
		}

		Arrays::invoke($this->onOrdersMergedAll, $targetOrder, $orders);
	}

	/**
	 * @param \Eshop\DB\Customer|array<string>|null $customer
	 * @param \Eshop\DB\Merchant|null $merchant
	 * @param \Security\DB\Account|null $account
	 * @return \StORM\Collection<\Eshop\DB\Order>
	 */
	public function getNewOrders(Customer|null|array $customer, ?Merchant $merchant = null, ?Account $account = null): Collection
	{
		$collection = $this->many()->where('this.completedTs IS NULL AND this.canceledTs IS NULL');
		$collection->join(['purchase' => 'eshop_purchase'], 'this.fk_purchase = purchase.uuid');
		$collection->join(['customer' => 'eshop_customer'], 'customer.uuid = purchase.fk_customer');
		$collection->join(['nxn' => 'eshop_merchant_nxn_eshop_customer'], 'customer.uuid = nxn.fk_customer');

		if ($customer) {
			$collection->where('purchase.fk_customer', $customer instanceof Customer ? $customer->getPK() : $customer);
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

		if ($this->shopperUser->getEditOrderAfterCreation() && !$order->receivedTs) {
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
			return $this->many()->where('this.canceledTs IS NOT NULL')
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
		$writer->writeSheetRow($sheetName, [
			$order->code,
		]);
		$writer->writeSheetRow($sheetName, []);

		$styles = ['font-style' => 'bold'];

		$writer->writeSheetRow($sheetName, [
			$this->translator->translate('orderEE.productName', 'Název produktu'),
			$this->translator->translate('orderEE.productCode', 'Kód produktu'),
			$this->translator->translate('orderEE.amount', 'Množství'),
			$this->translator->translate('orderEE.pcsPrice', 'Cena za kus'),
			$this->translator->translate('orderEE.sumPrice', 'Mezisoučet'),
			$this->translator->translate('orderEE.note', 'Poznámka'),
		], $styles);

		foreach ($order->purchase->getItems() as $item) {
			$writer->writeSheetRow($sheetName, [
				$item->productName,
				$item->getFullCode(),
				$item->amount,
				\str_replace(',', '.', (string) $this->shopperUser->filterPrice($item->price, $order->purchase->currency->code)),
				\str_replace(',', '.', (string) $this->shopperUser->filterPrice($item->getPriceSum(), $order->purchase->currency->code)),
				$item->note,
			]);
		}

		$writer->writeSheetRow($sheetName, []);
		$writer->writeSheetRow($sheetName, [
			$this->translator->translate('orderEE.totalPrice', 'Celková cena'),
			\str_replace(',', '.', (string) $this->shopperUser->filterPrice($order->getTotalPrice(), $order->purchase->currency->code)),
		]);
	}

	/**
	 * @param array<\Eshop\DB\Order> $orders
	 * @param \XLSXWriter $writer
	 */
	public function excelExportAll(array $orders, \XLSXWriter $writer): void
	{
		$styles = ['font-style' => 'bold'];

		$sheetName = $this->translator->translate('orderEE.orders', 'Objednávky');

		$writer->writeSheetRow($sheetName, [
			$this->translator->translate('orderEE.order', 'Objednávka'),
			$this->translator->translate('orderEE.productName', 'Název produktu'),
			$this->translator->translate('orderEE.productCode', 'Kód produktu'),
			$this->translator->translate('orderEE.amount', 'Množství'),
			$this->translator->translate('orderEE.pcsPrice', 'Cena za kus'),
			$this->translator->translate('orderEE.sumPrice', 'Mezisoučet'),
			$this->translator->translate('orderEE.note', 'Poznámka'),
			$this->translator->translate('orderEE.account', 'Servisní technik'),
		], $styles);

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
					\str_replace(',', '.', (string) $this->shopperUser->filterPrice($item->price, $order->purchase->currency->code)),
					\str_replace(',', '.', (string) $this->shopperUser->filterPrice($item->getPriceSum(), $order->purchase->currency->code)),
					$item->note,
					$order->purchase->account ? $order->purchase->account->fullname : $order->purchase->accountFullname,
				]);
			}
		}

		$writer->writeSheetRow($sheetName, []);
		$writer->writeSheetRow($sheetName, [
			$this->translator->translate('orderEE.totalPrice', 'Celková cena'), \str_replace(',', '.', (string) $this->shopperUser->filterPrice($sumPrice, $order->purchase->currency->code)),
		]);
	}

	/**
	 * @param array<\Eshop\DB\Order> $orders
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

	/**
	 * @param array<string> $orders
	 * @param \League\Csv\Writer $writer
	 * @throws \League\Csv\CannotInsertRecord
	 * @throws \League\Csv\InvalidArgument
	 * @throws \StORM\Exception\NotFoundException
	 */
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

			$codPaymentType = $this->settingRepository->getValuesByName(SettingsPresenter::COD_TYPE);

			$payment = $order->getPayment();
			$cod = false;

			if ($payment && $codPaymentType) {
				$orderPaymentType = $payment->type;

				if ($orderPaymentType && Arrays::contains($codPaymentType, $orderPaymentType->getPK())) {
					$cod = true;
				}
			}

			$sumWeight = $purchase->getSumWeight();

			$writer->insertOne([
				'',
				$order->code,
				$purchase->fullname,
				'',
				'',
				$purchase->email,
				$purchase->phone,
				$cod ? \round($order->getTotalPriceVat()) : '',
				$this->shopperUser->getCurrency(),
				$order->getTotalPriceVat(),
				$sumWeight > 0 ? $sumWeight : 1,
				$purchase->zasilkovnaId,
				$this->shopperUser->getProjectUrl(),
			]);

			$order->update(['zasilkovnaCompleted' => true]);
		}
	}

	public function ediExport(Order $order): string
	{
		$gln = '8590804000006';
		$user = $order->purchase->customer;
		$created = new \Carbon\Carbon($order->createdTs);
		$string = '';
		$string .= 'SYS' . Strings::padRight($gln, 14, ' ') . "ED  96AORDERSP\r\n";
		$string .= 'HDR'
			. Strings::padRight($order->code, 15, ' ')
			. $created->format('Ymd')
			. Strings::padRight($user && $user->ediCompany ? $user->ediCompany : $gln, 17, ' ')
			. Strings::padRight($user && $user->ediBranch ? $user->ediBranch : $gln, 17, ' ')
			. Strings::padRight($user && $user->ediBranch ? $user->ediBranch : $gln, 17, ' ')
			. Strings::padRight($gln, 17, ' ')
			. Strings::padRight(' ', 17, ' ')
			. Strings::padRight(Carbon::parse($order->canceledTs ?? $order->createdTs)->format('Ymd') . '0000', 17, ' ')
			//."!!!".$order->note."!!!************>>"
			. '' . $order->purchase->note . ''
			. "\r\n";
		$line = 1;

		foreach ($order->getGroupedItems() as $i) {
			$string .= 'LIN'
				. Strings::padLeft((string) $line, 6, ' ')
				. Strings::padRight($i->product ? $i->product->getFullCode() : $i->getFullCode(), 25, ' ')
				. Strings::padRight('', 25, ' ')
				. Strings::padLeft(\number_format($i->amount, 3, '.', ''), 12, ' ')
				. Strings::padRight('', 15, ' ')
				. "\r\n";
			$line++;
		}

		return $string;
	}

	public function getCustomerTotalTurnover(Customer $customer, DateTime|Carbon|null $from = null, DateTime|Carbon|null $to = null): float
	{
		$from ??= new \Carbon\Carbon('1970-01-01');
		$to ??= new \Carbon\Carbon();

		$orders = $this->getOrdersByUserInRange($customer, $from, $to);

		$total = 0.0;

		$vat = false;

		if ($this->shopperUser->getShowPrice() === 'withVat') {
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
			} else {
				$collection->where('purchase.fk_customer', $user->getPK());
			}
		}

		return $collection;
	}

	/**
	 * @param array<\Eshop\DB\Order> $orders
	 * @param \Eshop\DB\Currency $currency
	 * @return array<string, array<string, float>>
	 * @throws \Exception
	 */
	public function getGroupedOrdersPrices(array $orders, Currency $currency): array
	{
		$data = [];

		foreach ($orders as $order) {
			if (!$order->getValue('purchaseCart') || $order->getValue('cartCurrency') !== $currency->getPK()) {
				continue;
			}

			$totalPrice = null;
			$totalPriceVat = null;

			$price = $order->totalPriceComputed ?: ($totalPrice = $order->getTotalPrice());
			$priceVat = $order->totalPriceVatComputed ?: ($totalPriceVat = $order->getTotalPriceVat());

			$orderCreatedYearMonth = (new Carbon($order->createdTs))->format('Y-m');

			$data[$orderCreatedYearMonth] = isset($data[$orderCreatedYearMonth]) ? [
				'price' => $price + $data[$orderCreatedYearMonth]['price'],
				'priceVat' => $priceVat + $data[$orderCreatedYearMonth]['priceVat'],
			] : [
				'price' => $price,
				'priceVat' => $priceVat,
			];

			if ($order->totalPriceComputedTs || !$totalPrice || !$totalPriceVat) {
				continue;
			}

			$order->update([
				'totalPriceComputed' => $totalPrice,
				'totalPriceVatComputed' => $totalPriceVat,
				'totalPriceComputedTs' => Carbon::now()->toDateTimeString(),
			]);
		}

		\ksort($data);

		return $data;
	}

	public function computeOrdersTotalPrice(?Carbon $from = null, ?Carbon $to = null): void
	{
		/** @var \StORM\Collection<\Eshop\DB\Order> $orders */
		$orders = $this->many()->where('this.totalPriceComputedTs IS NULL');

		if ($from) {
			$orders->where('this.createdTs >= :from', ['from' => $from->toDateTimeString()]);
		}

		if ($to) {
			$orders->where('this.createdTs <= :to', ['to' => $to->toDateTimeString()]);
		}

		while ($order = $orders->fetch()) {
			$price = $order->getTotalPrice();
			$priceVat = $order->getTotalPriceVat();

			$order->update([
				'totalPriceComputed' => $price,
				'totalPriceVatComputed' => $priceVat,
				'totalPriceComputedTs' => Carbon::now()->toDateTimeString(),
			]);
		}
	}

	public function clearOrdersTotalPrice(): void
	{
		$this->many()->update([
			'totalPriceComputed' => null,
			'totalPriceVatComputed' => null,
			'totalPriceComputedTs' => null,
		]);
	}

	/**
	 * @param array<\Eshop\DB\Order> $orders
	 * @return array<float>
	 */
	public function getSumOrderPrice(array $orders): array
	{
		$total = 0;
		$totalVat = 0;
		$count = \count($orders);

		if ($count === 0) {
			return [0, 0];
		}

		foreach ($orders as $order) {
			/** @var \Eshop\DB\Order $order */
			$total += $order->totalPriceComputed ?: $order->getTotalPrice();
			$totalVat += $order->totalPriceVatComputed ?: $order->getTotalPriceVat();
		}

		return [$total, $totalVat];
	}

	/**
	 * @param array<\Eshop\DB\Order> $orders
	 * @return array<float>
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
			$total += $order->totalPriceComputed ?: $order->getTotalPrice();
			$totalVat += $order->totalPriceVatComputed ?: $order->getTotalPriceVat();
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

		$rootCategories = [];

		$rootCategories[null] = [
			'name' => $this->translator->translate('.notAssigned', 'Nepřiřazeno'),
			'amount' => 0,
		];

		$sum = 0;

		$purchases = [];

		/** @var \Eshop\DB\Order $order */
		foreach ($orders as $order) {
			$purchases[] = $order->getValue('purchase');
		}

		$categoryType = $this->shopperUser->getMainCategoryType();

		$repository = $this->connection->findRepository(CartItem::class);
		$items = $repository->many()
			->join(['cart' => 'eshop_cart'], 'this.fk_cart = cart.uuid')
			->join(['purchase' => 'eshop_purchase'], 'cart.fk_purchase = purchase.uuid')
			->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid')
			->join(
				['productPrimaryCategory' => '(SELECT * FROM eshop_productprimarycategory)'],
				'this.uuid=productPrimaryCategory.fk_product AND productPrimaryCategory.fk_categoryType = :productPrimaryCategory_shopCategoryType',
				['productPrimaryCategory_shopCategoryType' => $categoryType],
			)
			->select(['purchasePK' => 'purchase.uuid', 'primaryCategory' => 'productPrimaryCategory.fk_category'])
			->where('purchase.uuid', $purchases)
			->toArray();

		$itemsByPurchase = [];

		$loadedRootCategories = [];

		foreach ($categoryRepo->many()->toArray() as $category) {
			$loadedRootCategories[$category->getPK()] = Arrays::first($categoryRepo->getBranch($category));
		}

		/** @var \Eshop\DB\CartItem $item */
		foreach ($items as $item) {
			$itemsByPurchase[$item->getValue('purchasePK')][$item->getPK()] = $item;
		}

		foreach ($orders as $order) {
			if (!$order->getValue('purchaseCart') || $order->getValue('cartCurrency') !== $currency->getPK()) {
				continue;
			}

			$items = $itemsByPurchase[$order->getValue('purchase')] ?? [];

			foreach ($items as $item) {
				$category = $item->getValue('primaryCategory');
				$sum += $item->amount;

				if (!$category) {
					$rootCategories[null]['amount'] += $item->amount;

					continue;
				}

				$root = $loadedRootCategories[$category];

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
				$rootCategories[$key]['share'] = \round($category['amount'] / (float) $sum * 100);
			} else {
				$rootCategories[$key]['share'] = 0;
			}
		}

		return $empty ? [] : $rootCategories;
	}

	/**
	 * @deprecated Use OrderEditService
	 */
	public function changeOrderCartItemAmount(PackageItem $packageItem, CartItem $cartItemOld, int $amount): void
	{
		$cartItem = clone $cartItemOld;

		foreach ($packageItem->relatedPackageItems as $relatedPackageItem) {
			$relatedCartItem = $relatedPackageItem->cartItem;

			$relatedCartItem->update(['amount' => $relatedCartItem->amount / $cartItemOld->amount * $amount]);
		}

		$packageItem->update(['amount' => $amount]);
		$cartItem->update(['amount' => $amount]);
	}

	/**
	 * @param array<\Eshop\DB\Order> $orders
	 * @param \Eshop\DB\Currency $currency
	 * @return array<string, array<string, \Eshop\DB\CartItem|\Eshop\DB\Product|float|int|null>>
	 */
	public function getOrdersTopProductsByAmount(array $orders, Currency $currency): array
	{
		$data = [];

		$purchases = [];

		foreach ($orders as $order) {
			$purchases[] = $order->getValue('purchase');
		}

		$repository = $this->connection->findRepository(CartItem::class);
		$items = $repository->many()
			->join(['cart' => 'eshop_cart'], 'this.fk_cart = cart.uuid')
			->join(['purchase' => 'eshop_purchase'], 'cart.fk_purchase = purchase.uuid')
			->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid')
			->select(['purchasePK' => 'purchase.uuid'])
			->where('purchase.uuid', $purchases)
			->toArray();

		$itemsByPurchase = [];

		/** @var \Eshop\DB\CartItem $item */
		foreach ($items as $item) {
			$itemsByPurchase[$item->getValue('purchasePK')][$item->getPK()] = $item;
		}

		foreach ($orders as $order) {
			if (!$order->getValue('purchaseCart') || $order->getValue('cartCurrency') !== $currency->getPK()) {
				continue;
			}

			$items = $itemsByPurchase[$order->getValue('purchase')] ?? [];

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

		return \array_slice(\array_reverse($data), 0, 10);
	}

	public function getOrdersByUser($user): Collection
	{
		$collection = $this->many()
			->where('this.receivedTs IS NOT NULL AND this.completedTs IS NOT NULL AND this.canceledTs IS NULL')
			->join(['cart' => 'eshop_cart'], 'this.fk_purchase = cart.fk_purchase')
			->select(['purchaseCart' => 'cart.uuid', 'cartCurrency' => 'cart.fk_currency'])
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
			$items[$cartItem->getPK()]['code'] = $cartItem->getProduct()?->code ?: $cartItem->productCode;
			$items[$cartItem->getPK()]['supplierCode'] = $cartItem->getProduct()?->supplierCode;
			$items[$cartItem->getPK()]['ean'] = $cartItem->getProduct()?->ean;
			$items[$cartItem->getPK()]['externalCode'] = $cartItem->getProduct()?->externalCode;
			$items[$cartItem->getPK()]['totalPrice'] = $cartItem->getPriceSum();
			$items[$cartItem->getPK()]['totalPriceVat'] = $cartItem->getPriceVatSum();

			if ($this->shopperUser->getCatalogPermission() !== 'price') {
				continue;
			}

			if ($this->shopperUser->getShowVat() && $this->shopperUser->getShowWithoutVat()) {
				$items[$cartItem->getPK()]['totalPricePref'] = $this->shopperUser->getMainPriceType() === 'withVat' ? $cartItem->getPriceVatSum() : $cartItem->getPriceSum();
			} else {
				if ($this->shopperUser->getShowVat()) {
					$items[$cartItem->getPK()]['totalPricePref'] = $cartItem->getPriceVatSum();
				}

				if ($this->shopperUser->getShowWithoutVat()) {
					$items[$cartItem->getPK()]['totalPricePref'] = $cartItem->getPriceSum();
				}
			}
		}

		$deliveryPrice = $order->getDeliveryPriceSum();
		$paymentPrice = $order->getPaymentPriceSum();
		$totalDeliveryPrice = $deliveryPrice + $paymentPrice;
		$totalDeliveryPriceVat = $order->getDeliveryPriceVatSum() + $order->getPaymentPriceVatSum();

		$values = [
			'orderCode' => $order->code,
			'orderState' => $this->getState($order),
			'currencyCode' => $order->purchase->currency->code,
			'desiredShippingDate' => $purchase->desiredShippingDate,
			'desiredDeliveryDate' => $purchase->desiredDeliveryDate,
			'internalOrderCode' => $purchase->internalOrderCode,
			'phone' => $purchase->phone,
			'email' => $purchase->email,
			'items' => $items,
			'note' => $purchase->note,
			'deliveryType' => $purchase->deliveryType ? $purchase->deliveryType->name : null,
			'deliveryInfo' => $purchase->deliveryType ? $purchase->deliveryType->instructions : null,
			'deliveryPrice' => $order->getDeliveries()->firstValue('price'),
			'totalDeliveryPrice' => $totalDeliveryPrice,
			'totalDeliveryPriceVat' => $totalDeliveryPriceVat,
			'deliveryPriceVat' => $order->getDeliveries()->firstValue('priceVat'),
			'paymentType' => $purchase->paymentType ? $purchase->paymentType->name : null,
			'paymentInfo' => $purchase->paymentType ? $purchase->paymentType->instructions : null,
			'paymentPrice' => $order->payments->firstValue('price'),
			'paymentPriceVat' => $order->payments->firstValue('priceVat'),
			'billName' => $purchase->fullname,
			'billingAddress' => $purchase->billAddress ? $purchase->billAddress->jsonSerialize() : [],
			'deliveryAddress' => $purchase->deliveryAddress ? $purchase->deliveryAddress->jsonSerialize() : ($purchase->billAddress ? $purchase->billAddress->jsonSerialize() : []),
			'totalPrice' => $this->shopperUser->getCatalogPermission() === 'price' ? $order->getTotalPrice() : null,
			'totalPriceVat' => $this->shopperUser->getCatalogPermission() === 'price' ? $order->getTotalPriceVat() : null,
			'currency' => $order->purchase->currency,
			'discountCoupon' => $order->getDiscountCoupon(),
			'discountPrice' => $order->getDiscountPrice(),
			'discountPriceVat' => $order->getDiscountPriceVat(),
			'order' => $order,
			'withVat' => false,
			'withoutVat' => false,
			'catalogPermission' => $this->shopperUser->getCatalogPermission(),
			'priorityPrices' => $this->shopperUser->showPriorityPrices(),
			'accountFullname' => $purchase->accountFullname,
		];

		if ($this->shopperUser->getCatalogPermission() === 'price') {
			if ($this->shopperUser->getShowVat() && $this->shopperUser->getShowWithoutVat()) {
				if ($this->shopperUser->showPriorityPrices() === 'withVat') {
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
				if ($this->shopperUser->getShowVat()) {
					$values['totalDeliveryPricePref'] = $totalDeliveryPriceVat;
					$values['paymentPricePref'] = $order->payments->firstValue('priceVat');
					$values['totalPricePref'] = $order->getTotalPriceVat();
					$values['withVat'] = true;
				}

				if ($this->shopperUser->getShowWithoutVat()) {
					$values['totalDeliveryPricePref'] = $totalDeliveryPrice;
					$values['paymentPricePref'] = $order->payments->firstValue('price');
					$values['totalPricePref'] = $order->getTotalPrice();
					$values['withoutVat'] = true;
				}
			}
		}

		/** @var \Eshop\Services\QrPaymentGenerator|null $qrPaymentGenerator */
		$qrPaymentGenerator = $this->integrations->getService(Integrations::QR_PAYMENT_GENERATOR);

		if ($qrPaymentGenerator) {
			try {
				$values['qrPaymentCode'] = $qrPaymentGenerator->generateQrPaymentForOrder($order);
			} catch (\Exception $e) {
			}
		}

		return $values;
	}

	/**
	 * @param $product
	 * @param \DateTime|\Carbon\Carbon $from
	 * @param \DateTime|\Carbon\Carbon $to
	 * @throws \StORM\Exception\NotFoundException
	 * @throws \Throwable
	 */
	public function getProductUniqueOrderCountInDateRange($product, $from, $to): int
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

		/** @var array<\Eshop\DB\Cart> $carts */
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

	public function openOrder(Order $order, ?Administrator $administrator = null): void
	{
		if (Arrays::contains(Arrays::invoke($this->onBeforeOrderOpened, $order), false)) {
			return;
		}

		$order->update([
			'receivedTs' => null,
			'completedTs' => null,
			'canceledTs' => null,
		]);

		Arrays::invoke($this->onOrderOpened, $order);

		$this->orderLogItemRepository->createLog($order, OrderLogItem::OPENED, null, $administrator);
	}

	public function receiveOrder(Order $order, ?Administrator $administrator = null): void
	{
		if (Arrays::contains(Arrays::invoke($this->onBeforeOrderReceived, $order), false)) {
			return;
		}

		$order->update([
			'receivedTs' => (string) new \Carbon\Carbon(),
			'completedTs' => null,
			'canceledTs' => null,
		]);

		Arrays::invoke($this->onOrderReceived, $order);

		$this->orderLogItemRepository->createLog($order, OrderLogItem::RECEIVED, null, $administrator);
	}

	public function completeOrder(Order $order, ?Administrator $administrator = null): void
	{
		if (Arrays::contains(Arrays::invoke($this->onBeforeOrderCompleted, $order), false)) {
			return;
		}

		if ($order->canceledTs === null) {
			foreach ($order->purchase->getItems() as $item) {
				if (!$item->product) {
					continue;
				}

				$item->product->update(['buyCount' => $item->product->buyCount + $item->amount]);
			}
			
			// loyalty program is computed in scripts
		}

		$order->update(['completedTs' => (string) new \Carbon\Carbon(), 'canceledTs' => null]);

		Arrays::invoke($this->onOrderCompleted, $order);

		$this->orderLogItemRepository->createLog($order, OrderLogItem::COMPLETED, null, $administrator);
	}

	public function receiveAndCompleteOrder(Order $order, ?Administrator $administrator = null): void
	{
		$this->receiveOrder($order, $administrator);
		$this->completeOrder($order, $administrator);
	}

	public function cancelOrder(Order $order, ?Administrator $administrator = null): void
	{
		if (Arrays::contains(Arrays::invoke($this->onBeforeOrderCanceled, $order), false)) {
			return;
		}

		$order->update([
			'receivedTs' => $order->receivedTs ?: (string) new \Carbon\Carbon(),
			'canceledTs' => (string) new \Carbon\Carbon(),
			'loyaltyProgramComputedTs' => null,
		]);

		Arrays::invoke($this->onOrderCanceled, $order);

		$this->orderLogItemRepository->createLog($order, OrderLogItem::CANCELED, null, $administrator);
	}

	public function banOrder(Order $order, ?Administrator $administrator = null): void
	{
		if (Arrays::contains(Arrays::invoke($this->onBeforeOrderBanned, $order), false)) {
			return;
		}

		$order->update([
			'bannedTs' => (string) new \Carbon\Carbon(),
		]);

		$this->bannedEmailRepository->syncOne(['email' => $order->purchase->email]);

		Arrays::invoke($this->onOrderBanned, $order);

		$this->orderLogItemRepository->createLog($order, OrderLogItem::BAN, null, $administrator);
	}

	public function unBanOrder(Order $order, ?Administrator $administrator = null): void
	{
		if (Arrays::contains(Arrays::invoke($this->onBeforeOrderUnBanned, $order), false)) {
			return;
		}

		$order->update([
			'bannedTs' => null,
		]);

		Arrays::invoke($this->onOrderUnBanned, $order);

		$this->orderLogItemRepository->createLog($order, OrderLogItem::UN_BAN, null, $administrator);
	}

	public function pauseOrder(Order $order, ?Administrator $administrator = null): void
	{
		if (Arrays::contains(Arrays::invoke($this->onBeforeOrderPaused, $order), false)) {
			return;
		}

		$order->update([
			'pausedTs' => (string) new \Carbon\Carbon(),
		]);

		Arrays::invoke($this->onOrderPaused, $order);

		$this->orderLogItemRepository->createLog($order, OrderLogItem::PAUSE, null, $administrator);
	}

	public function unPauseOrder(Order $order, ?Administrator $administrator = null): void
	{
		if (Arrays::contains(Arrays::invoke($this->onBeforeOrderUnPaused, $order), false)) {
			return;
		}

		$order->update([
			'pausedTs' => null,
		]);

		Arrays::invoke($this->onOrderUnPaused, $order);

		$this->orderLogItemRepository->createLog($order, OrderLogItem::UN_PAUSE, null, $administrator);
	}

	public function getLastOrder(): ?Order
	{
		return $this->many()
			->where('this.fk_shop = :s OR this.fk_shop IS NULL', ['s' => $this->shopsConfig->getSelectedShop()?->getPK()])
			->orderBy(['this.createdTs' => 'DESC'])->first();
	}

	/**
	 * @param array<\Eshop\DB\Order> $orders
	 * @param array<\Eshop\DB\DiscountCoupon> $discountCoupons
	 * @return array<array<string, float>>
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

		$percentageUsage = [];
		$countUsage = [];

		foreach ($discountCoupons as $discountCoupon) {
			$percentageUsage[$discountCoupon->getPK()] = isset($usedCoupons[$discountCoupon->getPK()]) ? \round($usedCoupons[$discountCoupon->getPK()] / $count * 100, 2) : 0;
			$countUsage[$discountCoupon->getPK()] = $usedCoupons[$discountCoupon->getPK()] ?? 0;
		}

		return [$percentageUsage, $countUsage];
	}
	
	public function changePayment(string $payment, bool $paid, bool $email = false, ?Administrator $admin = null, ?Carbon $paidTs = null, ?string $externalId = null): void
	{
		$paymentRepository = $this->connection->findRepository(Payment::class);
		$orderLogItemRepository = $this->connection->findRepository(OrderLogItem::class);
		$templateRepository = $this->connection->findRepository(Template::class);
		$mailer = $this->container->getByType(Mailer::class);
		
		/** @var \Eshop\DB\Payment $payment */
		$payment = $paymentRepository->one($payment, true);
		
		$paidTs = $paidTs ?: Carbon::now();
		
		$values = [
			'externalId' => $externalId,
			'paidTs' => $paid ? $paidTs->toDateTimeString() : null,
			'paidPrice' => $paid ? $payment->order->getTotalPrice() : 0,
			'paidPriceVat' => $paid ? $payment->order->getTotalPriceVat() : 0,
		];

		$payment->update($values);
		
		if ($paid) {
			$orderLogItemRepository->createLog($payment->order, OrderLogItem::PAYED, $payment->getTypeName(), $admin);
			
			if ($email) {
				try {
					$mail = $templateRepository->createMessage('order.payed', ['orderCode' => $payment->order->code], $payment->order->purchase->email);
					$mailer->send($mail);
					
					$orderLogItemRepository->createLog($payment->order, OrderLogItem::EMAIL_SENT, OrderLogItem::PAYED, $admin);
				} catch (\Throwable $e) {
				}
			}

			Arrays::invoke($this->onOrderPaymentPaid, $payment);
		} else {
			$orderLogItemRepository->createLog($payment->order, OrderLogItem::PAYED_CANCELED, null, $admin);

			Arrays::invoke($this->onOrderPaymentCanceled, $payment);
		}
	}

	/**
	 * @param \League\Csv\Writer $writer
	 * @param \StORM\Collection<\Eshop\DB\Order> $orders
	 * @throws \League\Csv\CannotInsertRecord
	 * @throws \League\Csv\InvalidArgument
	 */
	public function csvExportTargito(Writer $writer, Collection $orders): void
	{
		/** @var \Eshop\Services\DPD|null $dpd */
		$dpd = $this->integrations->getService(Integrations::DPD);

		$writer->setDelimiter(',');

		$writer->insertOne([
			'email',
			'order_id',
			'created_date',
			'item_id',
			'item_price',
			'item_price_vat',
			'item_quantity',
			'billing_fullname',
			'billing_street',
			'billing_postcode',
			'billing_city',
			'is_cancelled',
			'delivery_state',
			'discount_code',
		]);

		$purchases = [];
		$deliveryTypes = [];
		$customers = [];
		$addresses = [];
		$paymentTypes = [];

		/** @var \Eshop\DB\Order $order */
		foreach ($orders as $order) {
			$purchases[] = $order->getValue('purchase');
		}

		$repository = $this->connection->findRepository(CartItem::class);
		$items = $repository->many()
			->join(['cart' => 'eshop_cart'], 'this.fk_cart = cart.uuid')
			->join(['purchase' => 'eshop_purchase'], 'cart.fk_purchase = purchase.uuid')
			->select(['purchasePK' => 'purchase.uuid'])
			->where('purchase.uuid', $purchases)
			->toArray();

		$itemsByPurchase = [];

		/** @var \Eshop\DB\CartItem $item */
		foreach ($items as $item) {
			$itemsByPurchase[$item->getValue('purchasePK')][$item->getPK()] = $item;
		}

		$repository = $this->connection->findRepository(Purchase::class);
		$purchases = $repository->many()->where('this.uuid', $purchases)->toArray();

		/** @var \Eshop\DB\Order $order */
		foreach ($orders as $order) {
			/** @var \Eshop\DB\Purchase $purchase */
			$purchase = $purchases[$order->getValue('purchase')];

			if ($object = $purchase->getValue('customer')) {
				$customers[] = $object;
			}

			if ($object = $purchase->getValue('deliveryType')) {
				$deliveryTypes[] = $object;
			}

			if ($object = $purchase->getValue('paymentType')) {
				$paymentTypes[] = $object;
			}

			if (!$object = $purchase->getValue('billAddress')) {
				continue;
			}

			$addresses[] = $object;
		}

		$repository = $this->connection->findRepository(DeliveryType::class);
		$deliveryTypes = $repository->many()->where('this.uuid', $deliveryTypes)->toArray();
		$repository = $this->connection->findRepository(Customer::class);
		$customers = $repository->many()->where('this.uuid', $customers)->toArray();
		$repository = $this->connection->findRepository(Address::class);
		$addresses = $repository->many()->where('this.uuid', $addresses)->toArray();
		$repository = $this->connection->findRepository(PaymentType::class);
		$paymentTypes = $repository->many()->where('this.uuid', $paymentTypes)->toArray();

		/** @var \Eshop\DB\Order $order */
		foreach ($orders as $order) {
			/** @var \Eshop\DB\Purchase $purchase */
			$purchase = $purchases[$order->getValue('purchase')];
			$isCancelled = $this->getState($order) === Order::STATE_CANCELED ? '1' : '0';
			// phpcs:ignore
			$deliveryStatus = $dpd?->getIsOrderDelivered($order) ? '1' : '0';

			$email = isset($customers[$purchase->getValue('customer')]) ? $customers[$purchase->getValue('customer')]->email : $purchase->email;
			$billAddress = $addresses[$purchase->getValue('billAddress')] ?? null;

			$discountCode = $order->getDiscountCoupon() ? $order->getDiscountCoupon()->code : null;

			foreach ($itemsByPurchase[$purchase->getPK()] ?? [] as $item) {
				$writer->insertOne([
					$email,
					$order->code,
					$order->createdTs,
					$item->getFullCode(),
					$item->price,
					$item->priceVat,
					$item->amount,
					$billAddress ? $billAddress->name : null,
					$billAddress ? $billAddress->street : null,
					$billAddress ? $billAddress->zipcode : null,
					$billAddress ? $billAddress->city : null,
					$isCancelled,
					$deliveryStatus,
					$discountCode,
				]);
			}

			if ($deliveryType = ($deliveryTypes[$purchase->getValue('deliveryType')] ?? null)) {
				$writer->insertOne([
					$email,
					$order->code,
					$order->createdTs,
					'doprava-' . $deliveryType->code,
					$order->getDeliveryPriceSum(),
					$order->getDeliveryPriceVatSum(),
					1,
					$billAddress ? $billAddress->name : null,
					$billAddress ? $billAddress->street : null,
					$billAddress ? $billAddress->zipcode : null,
					$billAddress ? $billAddress->city : null,
					$isCancelled,
					$deliveryStatus,
					$discountCode,
				]);
			}

			if (!$paymentType = ($paymentTypes[$purchase->getValue('paymentType')] ?? null)) {
				continue;
			}

			$writer->insertOne([
				$email,
				$order->code,
				$order->createdTs,
				'platba-' . $paymentType->code,
				$order->getPaymentPriceSum(),
				$order->getPaymentPriceVatSum(),
				1,
				$billAddress ? $billAddress->name : null,
				$billAddress ? $billAddress->street : null,
				$billAddress ? $billAddress->zipcode : null,
				$billAddress ? $billAddress->city : null,
				$isCancelled,
				$deliveryStatus,
				$discountCode,
			]);
		}
	}

	/**
	 * @param \Eshop\DB\Order $order
	 * @return array<array<\Eshop\DB\CartItem>>
	 */
	public function getUpsellGroupedByCartItems(Order $order): array
	{
		/** @var \Eshop\DB\CartItemRepository $cartItemRepository */
		$cartItemRepository = $this->getConnection()->findRepository(CartItem::class);

		$upsells = [];

		foreach ($order->purchase->getItems()->where('this.fk_upsell IS NULL') as $cartItem) {
			$upsells[$cartItem->getPK()] = $cartItemRepository->many()->where('this.fk_upsell', $cartItem->getPK())->toArray();
		}

		return $upsells;
	}

	/**
	 * @param \Eshop\DB\Order $order
	 * @return array<\Eshop\DB\CartItem|\Eshop\DB\Related>
	 */
	public function getGroupedItemsWithSets(Order $order): array
	{
		$topLevelItems = [];
		$grouped = [];

		/** @var array<string, int> $topLevelItemsAmounts */
		$topLevelItemsAmounts = [];

		/** @var array<string, int> $groupedAmounts */
		$groupedAmounts = [];

		/** @var \Eshop\DB\CartItem $item */
		foreach ($order->purchase->getItems() as $item) {
			if (isset($topLevelItems[$item->getFullCode()])) {
				$topLevelItemsAmounts[$item->getFullCode()] = (int) $topLevelItemsAmounts[$item->getFullCode()] + $item->amount;
			} else {
				$topLevelItemsAmounts[$item->getFullCode()] = $item->amount;
				$topLevelItems[$item->getFullCode()] = $item;
			}
		}

			/** @var \Eshop\DB\CartItem $item */
		foreach ($order->purchase->getItems()->where('fk_product IS NOT NULL') as $item) {
			/** @var \Eshop\DB\RelatedCartItem $related */
			foreach ($item->relatedCartItems as $related) {
				if (isset($grouped[$related->getFullCode()])) {
					$groupedAmounts[$related->getFullCode()] = (int) $groupedAmounts[$related->getFullCode()] + $related->amount;
				} else {
					$groupedAmounts[$related->getFullCode()] = $related->amount;
					$grouped[$related->getFullCode()] = $related;
				}

				unset($topLevelItems[$item->getFullCode()]);
				unset($topLevelItemsAmounts[$item->getFullCode()]);
			}
		}

		foreach ($topLevelItems as $item) {
			if (isset($grouped[$item->getFullCode()])) {
				$groupedAmounts[$item->getFullCode()] = (int) $groupedAmounts[$item->getFullCode()] + $item->amount;
			} else {
				$groupedAmounts[$item->getFullCode()] = $item->amount;
				$grouped[$item->getFullCode()] = $item;
			}
		}

		foreach ($grouped as $item) {
			$item->amount = $groupedAmounts[$item->getFullCode()];
		}

		return $grouped;
	}

	/**
	 * @inheritDoc
	 */
	public function getAjaxArrayForSelect(bool $includeHidden = true, ?string $q = null, ?int $page = null): array
	{
		return $this->getCollection($includeHidden)
			->where('this.code LIKE :like', ['like' => "%$q%"])
			->setPage($page ?? 1, 5)
			->toArrayOf('code');
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('code');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many()->orderBy(['this.createdTs' => 'DESC']);
	}
}
