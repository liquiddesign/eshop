<?php

declare(strict_types=1);

namespace Eshop\DB;

use Carbon\Carbon;
use Common\DB\IGeneralRepository;
use Eshop\Shopper;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\DIConnection;
use StORM\Repository;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Invoice>
 */
class InvoiceRepository extends Repository implements IGeneralRepository
{
	private AddressRepository $addressRepository;
	
	private InvoiceItemRepository $invoiceItemRepository;

	private RelatedTypeRepository $relatedTypeRepository;

	private ProductRepository $productRepository;

	private Shopper $shopper;
	
	public function __construct(
		InvoiceItemRepository $invoiceItemRepository,
		AddressRepository $addressRepository,
		RelatedTypeRepository $relatedTypeRepository,
		DIConnection $connection,
		SchemaManager $schemaManager,
		ProductRepository $productRepository,
		Shopper $shopper
	) {
		parent::__construct($connection, $schemaManager);
		
		$this->addressRepository = $addressRepository;
		$this->invoiceItemRepository = $invoiceItemRepository;
		$this->relatedTypeRepository = $relatedTypeRepository;
		$this->productRepository = $productRepository;
		$this->shopper = $shopper;
	}

	/**
	 * @param bool $includeHidden
	 * @return array<string, string>
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('code');
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many()
			->setGroupBy(['this.uuid'])
			->select(['ordersCodes' => 'GROUP_CONCAT(orders.code)'])
			->orderBy(['this.exposed', 'this.code',]);
	}
	
	/**
	 * @param \Eshop\DB\Order $order
	 * @param array<mixed> $values
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function createFromOrder(Order $order, array $values = []): Invoice
	{
		$this->getConnection()->getLink()->beginTransaction();
		
		$addressValues = $order->purchase->billAddress->toArray([], true, false, false);
		unset($addressValues['id']);

		do {
			$newHash = Random::generate(15, '0-9a-zA-Z');
			$hash = $this->many()->where('hash', $newHash)->first();
		} while ($hash !== null);

		$newValues = [
				'totalPrice' => $order->getTotalPrice(),
				'totalPriceVat' => $order->getTotalPriceVat(),
				'currency' => $order->purchase->currency,
				'address' => $this->addressRepository->createOne($addressValues),
				'customer' => $order->purchase->customer,
				'orders' => [$order->getPK()],
				'hash' => $newHash,
				'totalPriceWithoutDiscount' => $order->getTotalPrice() + $order->getDiscountPrice(),
				'totalPriceVatWithoutDiscount' => $order->getTotalPriceVat() + $order->getDiscountPriceVat(),
			] + $values;

		if (!isset($values['code'])) {
			$newValues['code'] = Strings::webalize($order->code);
		}

		if (!isset($values['exposed'])) {
			$newValues['exposed'] = (string)(new Carbon());
		}

		if (!isset($values['taxDate'])) {
			$days = $this->shopper->getInvoicesAutoTaxDateInDays();

			$newValues['taxDate'] = (string)(new Carbon($newValues['exposed']))->addDays($days);
		}

		if (!isset($values['dueDate'])) {
			$days = $this->shopper->getInvoicesAutoDueDateInDays();

			$newValues['dueDate'] = (string)(new Carbon($newValues['exposed']))->addDays($days);
		}

		if (!isset($values['variableSymbol'])) {
			$newValues['variableSymbol'] = $order->code;
		}

		if (!isset($values['paidDate'])) {
			if ($payment = $order->getPayment()) {
				$newValues['paidDate'] = $payment->paidTs;
			}
		}

		if (!isset($values['canceled'])) {
			if ($order->getState() === Order::STATE_CANCELED) {
				$newValues['canceled'] = $order->canceledTs;
			}
		}

		$invoice = $this->createOne($newValues);

		$cartItemInvoiceItemMap = [];

		foreach ($order->purchase->getItems()->where('fk_upsell IS NULL') as $item) {
			$newItem = $this->invoiceItemRepository->createOne([
				'name' => $item->productName,
				'price' => $item->price,
				'priceVat' => $item->priceVat,
				'priceBefore' => $item->priceBefore,
				'priceVatBefore' => $item->priceVatBefore,
				'vatPct' => $item->vatPct,
				'amount' => $item->amount,
				'realAmount' => $item->realAmount,
				'product' => $item->product,
				'invoice' => $invoice,
				'productCode' => $item->getProduct() ? $item->getProduct()->getFullCode() : $item->productCode,
				'productSubCode' => $item->getProduct() ? $item->getProduct()->subCode : $item->productSubCode,
				'customerDiscountLevel' => $order->purchase->customerDiscountLevel,
			]);

			$cartItemInvoiceItemMap[$item->getPK()] = $newItem->getPK();
		}

		foreach ($order->purchase->getItems()->where('fk_upsell IS NOT NULL') as $item) {
			$this->invoiceItemRepository->createOne([
				'name' => $item->productName,
				'price' => $item->price,
				'priceVat' => $item->priceVat,
				'priceBefore' => $item->priceBefore,
				'priceVatBefore' => $item->priceVatBefore,
				'vatPct' => $item->vatPct,
				'amount' => $item->amount,
				'realAmount' => $item->realAmount,
				'product' => $item->product,
				'invoice' => $invoice,
				'upsell' => $cartItemInvoiceItemMap[$item->getValue('upsell')] ?? null,
				'productCode' => $item->getProduct() ? $item->getProduct()->getFullCode() : $item->productCode,
				'productSubCode' => $item->getProduct() ? $item->getProduct()->subCode : $item->productSubCode,
				'customerDiscountLevel' => $order->purchase->customerDiscountLevel,
			]);
		}
		
		$this->getConnection()->getLink()->commit();
		
		return $invoice;
	}

	/**
	 * @param \Eshop\DB\Invoice $invoice
	 * @return array<\Eshop\DB\InvoiceItem|\Eshop\DB\Related>
	 */
	public function getGroupedItemsWithSets(Invoice $invoice): array
	{
		$topLevelItems = [];
		$grouped = [];

		/** @var \Eshop\DB\InvoiceItem $item */
		foreach ($invoice->items->clear(true) as $item) {
			if (isset($topLevelItems[$item->getFullCode()])) {
				$topLevelItems[$item->getFullCode()]->amount += $item->amount;
			} else {
				$topLevelItems[$item->getFullCode()] = $item;
			}
		}

		/** @var \Eshop\DB\RelatedType $relatedType */
		foreach ($this->relatedTypeRepository->getSetTypes() as $relatedType) {
			/** @var \Eshop\DB\InvoiceItem $item */
			foreach ($invoice->items->clear(true)->where('fk_product IS NOT NULL') as $item) {
				/** @var \Eshop\DB\Related $related */
				foreach ($this->productRepository->getSlaveRelatedProducts($relatedType, $item->getValue('product')) as $related) {
					$related->amount *= $item->amount;

					if (isset($grouped[$related->slave->getFullCode()])) {
						$grouped[$related->slave->getFullCode()]->amount += $related->amount;
					} else {
						$grouped[$related->slave->getFullCode()] = $related;
					}

					unset($topLevelItems[$item->getFullCode()]);
				}
			}
		}

		foreach ($topLevelItems as $item) {
			if (isset($grouped[$item->getFullCode()])) {
				$grouped[$item->getFullCode()]->amount += $item->amount;
			} else {
				$grouped[$item->getFullCode()] = $item;
			}
		}

		return $grouped;
	}

	/**
	 * @param \Eshop\DB\InvoiceItem $invoiceItem
	 * @param array<\Eshop\DB\Related> $setProducts
	 * @return array<\Eshop\DB\Related>
	 * @throws \Exception
	 */
	public function getSetItemsWithDiscount(InvoiceItem $invoiceItem, array $setProducts): array
	{
		if (\count($setProducts) === 0) {
			return [];
		}

		$setPrice = $invoiceItem->getPriceSum();
		$setPriceVat = $invoiceItem->getPriceVatSum();
		$setProductsPrice = 0;
		$setProductsPriceVat = 0;

		// Load products
		$productsPKs = [];

		foreach ($setProducts as $related) {
			$productsPKs[] = $related->getValue('slave');
		}

		// Load prices
		/** @var array<\Eshop\DB\Product> $setProductsWithPrice */
		$setProductsWithPrice = $this->productRepository->getProducts()->where('this.uuid', $productsPKs)->toArray();

		foreach ($setProducts as $related) {
			if (!isset($setProductsWithPrice[$related->getValue('slave')])) {
				continue;
			}

			$productWithPrice = $setProductsWithPrice[$related->getValue('slave')];

			$setProductsPrice += $productWithPrice->getPrice() * $related->amount * $invoiceItem->amount;
			$setProductsPriceVat += $productWithPrice->getPriceVat() * $related->amount * $invoiceItem->amount;
		}

		// Calculate prices
		$discountMultiplier = $setPrice / $setProductsPrice;
		$discountMultiplierVat = $setPriceVat / $setProductsPriceVat;

		$discountPercentage = (1 - $discountMultiplier) * 100;
		$discountPercentageVat = (1 - $discountMultiplierVat) * 100;

		$customerDiscountMultiplier = $invoiceItem->customerDiscountLevel ? 100 / (100 - $invoiceItem->customerDiscountLevel) : 1;

		foreach ($setProducts as $related) {
			if (!isset($setProductsWithPrice[$related->getValue('slave')])) {
				continue;
			}

			$related->setValue('priceBefore', $setProductsWithPrice[$related->getValue('slave')]->getPrice() * $customerDiscountMultiplier);
			$related->setValue('priceVatBefore', $setProductsWithPrice[$related->getValue('slave')]->getPriceVat() * $customerDiscountMultiplier);
			$related->setValue('price', $setProductsWithPrice[$related->getValue('slave')]->getPrice() * $discountMultiplier);
			$related->setValue('priceVat', $setProductsWithPrice[$related->getValue('slave')]->getPriceVat() * $discountMultiplierVat);
			$related->setValue('totalPriceBefore', $setProductsWithPrice[$related->getValue('slave')]->getPrice() * $related->amount * $invoiceItem->amount * $customerDiscountMultiplier);
			$related->setValue('totalPriceVatBefore', $setProductsWithPrice[$related->getValue('slave')]->getPriceVat() * $related->amount * $invoiceItem->amount * $customerDiscountMultiplier);
			$related->setValue('totalPrice', $setProductsWithPrice[$related->getValue('slave')]->getPrice() * $related->amount * $invoiceItem->amount * $discountMultiplier);
			$related->setValue('totalPriceVat', $setProductsWithPrice[$related->getValue('slave')]->getPriceVat() * $related->amount * $invoiceItem->amount * $discountMultiplierVat);
			$related->setValue('discountPercentage', $discountPercentage);
			$related->setValue('discountPercentageVat', $discountPercentageVat);
		}

		return $setProducts;
	}
}
