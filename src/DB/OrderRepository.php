<?php

declare(strict_types=1);

namespace Eshop\DB;

use League\Csv\Writer;
use Nette\Utils\DateTime;
use StORM\Collection;
use StORM\Entity;

/**
 * @extends \StORM\Repository<\Eshop\DB\Order>
 */
class OrderRepository extends \StORM\Repository
{
	/**
	 * @param string $customerId
	 * @return \StORM\Collection|\Eshop\DB\Order[]
	 */
	public function getFinishedOrdersByCustomer(string $customerId): Collection
	{
		return $this->many()->where('this.fk_customer', $customerId)->where('this.completedTs IS NOT NULL OR this.canceledTs IS NOT NULL');
	}

	/**
	 * @param string $customerId
	 * @return \StORM\Collection|\Eshop\DB\Order[]
	 */
	public function getNewOrdersByCustomer(string $customerId): Collection
	{
		return $this->many()->where('this.fk_customer', $customerId)->where('this.completedTs IS NULL AND this.canceledTs IS NULL');
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
				$item->note
			]);
		}
	}

	public function ediExport(Order $order): string
	{
		$gln = '8590804000006';
		$user = $order->customer;
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
				. \str_pad($i->product->ean, 25, " ", STR_PAD_RIGHT)
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
	 * @return array
	 * @throws \Exception
	 *
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
			'name' => 'Nepřiřazeno',
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
	public function getOrdersByUserInRange($user, DateTime $from, DateTime $to): Collection
	{
		$from->setTime(0, 0);
		$to->setTime(23, 59, 59);
		$fromString = $from->format('Y-m-d\TH:i:s');
		$toString = $to->format('Y-m-d\TH:i:s');

		$collection = $this->many()
			->select(["date" => "DATE_FORMAT(createdTs, '%Y-%m')"])
			->where('completedTs IS NOT NULL')
			->where('createdTs >= :from AND createdTs <= :to', ['from' => $fromString, 'to' => $toString])
			->orderBy(["date"]);

		if ($user) {
			if ($user instanceof Merchant) {
				/** @var MerchantRepository $merchantRepo */
				$merchantRepo = $this->getConnection()->findRepository(Merchant::class);
				$customers = $merchantRepo->getMerchantCustomers($user);
			}

			$collection->where('fk_customer', isset($customers) ? \array_values($customers) : $user->getPK());
		}

		return $collection;

	}
	
	public function getEmailVariables(Order $order): array
	{
		/** @var \Eshop\DB\Purchase $purchase */
		$purchase = $order->purchase;
		$items = [];
		
		/** @var \Eshop\DB\CartItem $cartItem */
		foreach ($purchase->getItems() as $cartItem) {
			$items[$cartItem->getPK()] = $cartItem->toArray();
			$items[$cartItem->getPK()]['fullCode'] = $cartItem->getFullCode();
			$items[$cartItem->getPK()]['totalPrice'] =  $cartItem->getPriceSum();
			$items[$cartItem->getPK()]['totalPriceVat'] = $cartItem->getPriceVatSum();
		}
		
		$deliveryPrice = (float) $order->deliveries->firstValue('price');
		$paymentPrice = (float) $order->payments->firstValue('price');
		$totalDeliveryPrice = $deliveryPrice + $paymentPrice;
		
		$values = [
			'orderCode' => $order->code,
			'currencyCode' => $order->currency->code,
			'desiredShippingDate' => $purchase->desiredShippingDate,
			'internalOrderCode' => $purchase->internalOrderCode,
			'phone' => $purchase->phone,
			'email' => $purchase->email,
			'items' => $items,
			'note' => $purchase->note,
			'deliveryType' => $purchase->deliveryType->name,
			'deliveryPrice' => $order->deliveries->firstValue('price'),
			'totalDeliveryPrice' => $totalDeliveryPrice,
			'deliveryPriceVat' => $order->deliveries->firstValue('priceVat'),
			'paymentType' => $purchase->paymentType->name,
			'paymentPrice' => $order->payments->firstValue('price'),
			'paymentPriceVat' => $order->payments->firstValue('priceVat'),
			'billName' => $purchase->fullname,
			'billingAddress' => $purchase->billAddress->toArray(),
			'deliveryAddress' => $purchase->deliveryAddress ? $purchase->deliveryAddress->toArray() : $purchase->billAddress->toArray(),
			'totalPrice' => $order->getTotalPrice(),
			'totalPriceVat' => $order->getTotalPriceVat(),
		];
		
		return $values;
	}

}
