<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Nette\Utils\Random;
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
	
	public function __construct(
		InvoiceItemRepository $invoiceItemRepository,
		AddressRepository $addressRepository,
		RelatedTypeRepository $relatedTypeRepository,
		DIConnection $connection,
		SchemaManager $schemaManager,
		ProductRepository $productRepository
	) {
		parent::__construct($connection, $schemaManager);
		
		$this->addressRepository = $addressRepository;
		$this->invoiceItemRepository = $invoiceItemRepository;
		$this->relatedTypeRepository = $relatedTypeRepository;
		$this->productRepository = $productRepository;
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

		if (!isset($values['variableSymbol'])) {
			$newValues['variableSymbol'] = $order->code;
		}

		if (!isset($values['paymentType'])) {
			$newValues['paymentType'] = $order->purchase->getValue('paymentType');
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
					if (isset($grouped[$related->slave->getFullCode()])) {
						$grouped[$related->slave->getFullCode()]->amount += $related->amount;
					} else {
						$grouped[$related->slave->getFullCode()] = $related;
						unset($topLevelItems[$item->getFullCode()]);
					}
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
}
