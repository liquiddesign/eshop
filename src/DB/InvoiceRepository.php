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
	
	public function __construct(InvoiceItemRepository $invoiceItemRepository, AddressRepository $addressRepository, DIConnection $connection, SchemaManager $schemaManager)
	{
		parent::__construct($connection, $schemaManager);
		
		$this->addressRepository = $addressRepository;
		$this->invoiceItemRepository = $invoiceItemRepository;
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
				'vatPct' => $item->vatPct,
				'amount' => $item->amount,
				'realAmount' => $item->realAmount,
				'product' => $item->product,
				'invoice' => $invoice,
			]);

			$cartItemInvoiceItemMap[$item->getPK()] = $newItem->getPK();
		}

		foreach ($order->purchase->getItems()->where('fk_upsell IS NOT NULL') as $item) {
			$this->invoiceItemRepository->createOne([
				'name' => $item->productName,
				'price' => $item->price,
				'priceVat' => $item->priceVat,
				'vatPct' => $item->vatPct,
				'amount' => $item->amount,
				'realAmount' => $item->realAmount,
				'product' => $item->product,
				'invoice' => $invoice,
				'upsell' => $cartItemInvoiceItemMap[$item->getValue('upsell')] ?? null,
			]);
		}
		
		$this->getConnection()->getLink()->commit();
		
		return $invoice;
	}
}
