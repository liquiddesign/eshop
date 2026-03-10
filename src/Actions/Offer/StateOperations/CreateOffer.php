<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\ShopsConfig;
use Carbon\Carbon;
use Eshop\Actions\Offer\Code\GenerateOfferCode;
use Eshop\DB\Cart;
use Eshop\DB\Offer;
use Eshop\DB\OfferItemRepository;
use Eshop\DB\OfferLogItem;
use Eshop\DB\OfferLogItemRepository;
use Eshop\DB\OfferRepository;
use Eshop\DB\Purchase;
use StORM\Connection;
use Tracy\Debugger;
use Tracy\ILogger;

class CreateOffer extends \Base\BaseAction
{
	public function __construct(
		private readonly GenerateOfferCode $generateOfferCode,
		private readonly Connection $connection,
		private readonly OfferRepository $offerRepository,
		private readonly OfferLogItemRepository $offerLogItemRepository,
		private readonly OfferItemRepository $offerItemRepository,
		private readonly ShopsConfig $shopsConfig,
	) {
	}

	/**
	 * @throws \Exception
	 */
	public function execute(Purchase $purchase, Cart $cart): Offer
	{
		$maxAttempts = 5;

		$lastException = null;

		for ($i = 0; $i < $maxAttempts; $i++) {
			try {
				$inTransaction = $this->connection->beginTransaction();

				/** @var \Eshop\DB\Offer $offer */
				$offer = $this->offerRepository->createOne([
					'code' => $this->generateOfferCode->execute(),
					'validFromTs' => Carbon::now()->toDateString(),
					'validUntilTs' => Carbon::now()->addDays(14)->toDateString(),
					'customer' => $purchase->customer?->getPK(),
					'merchant' => $purchase->merchant?->getPK(),
					'deliveringMerchant' => $purchase->getValue('deliveringMerchant'),
					'account' => $purchase->getValue('account'),
					'currency' => $cart->currency->getPK(),
					'billAddress' => $purchase->billAddress?->getPK(),
					'deliveryAddress' => $purchase->deliveryAddress?->getPK(),
					'deliveryType' => $purchase->getValue('deliveryType'),
					'paymentType' => $purchase->getValue('paymentType'),
					'fullname' => $purchase->fullname ?? null,
					'email' => $purchase->email ?? null,
					'phone' => $purchase->phone ?? null,
					'ic' => $purchase->ic ?? null,
					'dic' => $purchase->dic ?? null,
					'accountEmail' => $purchase->accountEmail ?? null,
					'shop' => $this->shopsConfig->getSelectedShop()?->getPK(),
				]);

				$this->createOfferItemsFromCart($offer, $cart);

				$this->offerLogItemRepository->createLog(
					$offer,
					OfferLogItem::CREATED,
					null,
					$purchase->merchant,
				);

				if ($inTransaction) {
					$this->connection->commit();
				}

				return $offer;
			} catch (\Exception $e) {
				$lastException = $e;
			}
		}

		Debugger::log($lastException, ILogger::EXCEPTION);

		throw $lastException;
	}

	private function createOfferItemsFromCart(Offer $offer, Cart $cart): void
	{
		$priority = 0;

		foreach ($cart->getItems() as $cartItem) {
			$priority++;

			$this->offerItemRepository->createOne([
				'offer' => $offer->getPK(),
				'product' => $cartItem->product?->getPK(),
				'variant' => $cartItem->variant?->getPK(),
				'variantName' => [
					'cs' => $cartItem->getValue('variantName', 'cs'),
					'en' => $cartItem->getValue('variantName', 'en'),
				],
				'productName' => [
					'cs' => $cartItem->getValue('productName', 'cs'),
					'en' => $cartItem->getValue('productName', 'en'),
				],
				'productCode' => $cartItem->productCode,
				'productSubCode' => $cartItem->productSubCode,
				'productEan' => $cartItem->productEan,
				'amount' => $cartItem->amount,
				'price' => $cartItem->price,
				'priceVat' => $cartItem->priceVat,
				'priceBefore' => $cartItem->priceBefore,
				'priceVatBefore' => $cartItem->priceVatBefore,
				'vatPct' => $cartItem->vatPct,
				'priority' => $priority,
				'productWeight' => $cartItem->productWeight,
			]);
		}
	}
}
