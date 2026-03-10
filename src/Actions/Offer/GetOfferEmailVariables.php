<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer;

use Base\BaseAction;
use Eshop\Actions\Customer\GetCurrentContextCatalogPermissionByCustomer;
use Eshop\DB\Offer;

class GetOfferEmailVariables extends BaseAction
{
	public function __construct(private readonly GetCurrentContextCatalogPermissionByCustomer $getEmailBlocksSetting)
	{
	}

	/**
	 * @return array<string, mixed>
	 */
	public function execute(Offer $offer): array
	{
		$customer = $offer->customer;

		if ($customer && $offer->account) {
			$customer->setAccount($offer->account);
		}

		$currentContextCatalogPermissions = $this->getEmailBlocksSetting->execute($customer, $offer->shop);

		$items = [];

		/** @var \Eshop\DB\OfferItem $offerItem */
		foreach ($offer->getItems() as $offerItem) {
			$items[$offerItem->getPK()] = $offerItem->toArray();
			$items[$offerItem->getPK()]['fullCode'] = $offerItem->getFullCode();
			$items[$offerItem->getPK()]['code'] = $offerItem->getProduct()?->code ?: $offerItem->productCode;
			$items[$offerItem->getPK()]['supplierCode'] = $offerItem->getProduct()?->supplierCode;
			$items[$offerItem->getPK()]['ean'] = $offerItem->getProduct()?->getEan();
			$items[$offerItem->getPK()]['externalCode'] = $offerItem->getProduct()?->externalCode;

			if ($currentContextCatalogPermissions->catalogPermission !== 'price') {
				continue;
			}

			$items[$offerItem->getPK()]['price'] = $currentContextCatalogPermissions->showPricesWithoutVat ? $offerItem->price : null;
			$items[$offerItem->getPK()]['priceVat'] = $currentContextCatalogPermissions->showPricesWithVat ? $offerItem->priceVat : null;
			$items[$offerItem->getPK()]['totalPrice'] = $currentContextCatalogPermissions->showPricesWithoutVat ? $offerItem->getPriceSum() : null;
			$items[$offerItem->getPK()]['totalPriceVat'] = $currentContextCatalogPermissions->showPricesWithVat ? $offerItem->getPriceVatSum() : null;

			if ($currentContextCatalogPermissions->showPricesWithVat && $currentContextCatalogPermissions->showPricesWithoutVat) {
				$items[$offerItem->getPK()]['pricePref'] = $currentContextCatalogPermissions->priorityPrice === 'withVat' ? $offerItem->priceVat : $offerItem->price;
				$items[$offerItem->getPK()]['totalPricePref'] = $currentContextCatalogPermissions->priorityPrice === 'withVat' ? $offerItem->getPriceVatSum() : $offerItem->getPriceSum();
			} else {
				if ($currentContextCatalogPermissions->showPricesWithVat) {
					$items[$offerItem->getPK()]['pricePref'] = $offerItem->priceVat;
					$items[$offerItem->getPK()]['totalPricePref'] = $offerItem->getPriceVatSum();
				}

				if ($currentContextCatalogPermissions->showPricesWithoutVat) {
					$items[$offerItem->getPK()]['pricePref'] = $offerItem->price;
					$items[$offerItem->getPK()]['totalPricePref'] = $offerItem->getPriceSum();
				}
			}
		}

		$deliveryPrice = $offer->deliveryPrice ?? 0.0;
		$paymentPrice = $offer->paymentPrice ?? 0.0;
		$totalDeliveryPrice = $deliveryPrice + $paymentPrice;

		$values = [
			'offer' => $offer,
			'currencyCode' => $offer->currency?->code,
			'phone' => $offer->phone,
			'email' => $offer->email,
			'items' => $items,
			'deliveryType' => $offer->deliveryType?->name,
			'deliveryInfo' => $offer->deliveryType?->instructions,
			'deliveryPrice' => $deliveryPrice,
			'totalDeliveryPrice' => $totalDeliveryPrice,
			'totalDeliveryPriceVat' => $totalDeliveryPrice,
			'deliveryPriceVat' => $deliveryPrice,
			'paymentType' => $offer->paymentType?->name,
			'paymentInfo' => $offer->paymentType?->instructions,
			'paymentPrice' => $paymentPrice,
			'paymentPriceVat' => $paymentPrice,
			'billName' => $offer->fullname,
			'billingAddress' => $offer->billAddress ? $offer->billAddress->jsonSerialize() : [],
			'deliveryAddress' => $offer->deliveryAddress ? $offer->deliveryAddress->jsonSerialize() : ($offer->billAddress ? $offer->billAddress->jsonSerialize() : []),
			'totalPrice' => $currentContextCatalogPermissions->showPricesWithoutVat ? $offer->getTotalPrice() : null,
			'totalPriceVat' => $currentContextCatalogPermissions->showPricesWithVat ? $offer->getTotalPriceVat() : null,
			'currency' => $offer->currency,
			'withVat' => false,
			'withoutVat' => false,
			'catalogPermission' => $currentContextCatalogPermissions->catalogPermission,
			'priorityPrices' => $currentContextCatalogPermissions->priorityPrice,
			'accountFullname' => $offer->fullname,
			'displayedTransactionEmailBlocks' => $currentContextCatalogPermissions->getDisplayedTransactionEmailBlocks(),
			'discountPrice' => null,
			'discountPriceVat' => null,
			'additionalEmailText' => $currentContextCatalogPermissions->additionalEmailText,
			'_customerEntity' => $customer,
		];

		if ($currentContextCatalogPermissions->catalogPermission === 'price') {
			if ($currentContextCatalogPermissions->showPricesWithVat && $currentContextCatalogPermissions->showPricesWithoutVat) {
				$values['totalDeliveryPricePref'] = $totalDeliveryPrice;
				$values['paymentPricePref'] = $paymentPrice;

				$values['totalPricePref'] = $currentContextCatalogPermissions->priorityPrice === 'withVat' ? $offer->getTotalPriceVat() : $offer->getTotalPrice();

				$values['withVat'] = true;
				$values['withoutVat'] = true;
			} else {
				if ($currentContextCatalogPermissions->showPricesWithVat) {
					$values['totalDeliveryPricePref'] = $totalDeliveryPrice;
					$values['paymentPricePref'] = $paymentPrice;
					$values['totalPricePref'] = $offer->getTotalPriceVat();
					$values['withVat'] = true;
				}

				if ($currentContextCatalogPermissions->showPricesWithoutVat) {
					$values['totalDeliveryPricePref'] = $totalDeliveryPrice;
					$values['paymentPricePref'] = $paymentPrice;
					$values['totalPricePref'] = $offer->getTotalPrice();
					$values['withoutVat'] = true;
				}
			}
		}

		return $values;
	}
}
