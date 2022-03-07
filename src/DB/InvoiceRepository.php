<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\DIConnection;
use StORM\Repository;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Invoice>
 */
class InvoiceRepository extends Repository
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
	 * @param \Eshop\DB\Order $order
	 * @param array<mixed> $values
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function createFromOrder(Order $order, array $values = []): Invoice
	{
		$this->getConnection()->getLink()->beginTransaction();
		
		$addressValues = $order->purchase->billAddress->toArray([], true, false, false);
		unset($addressValues['id']);
		
		$invoice = $this->createOne([
				'totalPrice' => $order->getTotalPrice(),
				'totalPriceVat' => $order->getTotalPriceVat(),
				'currency' => $order->purchase->currency,
				'address' => $this->addressRepository->createOne($addressValues),
				'customer' => $order->purchase->customer,
			] + $values);
		
		foreach ($order->purchase->getItems() as $item) {
			$this->invoiceItemRepository->createOne([
				'name' => $item->productName,
				'price' => $item->price,
				'priceVat' => $item->priceVat,
				'vatPct' => $item->vatPct,
				'amount' => $item->amount,
				'product' => $item->product,
				'invoice' => $invoice,
			]);
		}
		
		$this->getConnection()->getLink()->commit();
		
		return $invoice;
	}
}
