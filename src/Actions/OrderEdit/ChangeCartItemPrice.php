<?php

declare(strict_types=1);

namespace Eshop\Actions\OrderEdit;

use Eshop\DB\CartItem;
use Eshop\DB\RelatedCartItemRepository;

class ChangeCartItemPrice extends \Base\BaseAction
{
	public function __construct(private readonly RelatedCartItemRepository $relatedCartItemRepository,)
	{
	}

	public function execute(CartItem $cartItem, float $price, float $vatPct, float|null $priceBefore = null,): CartItem
	{
		$priceVat = $price * (100 + $vatPct) / 100;

		if ($priceBefore !== null) {
			$priceVatBefore = $priceBefore * (100 + $vatPct) / 100;
		}

		$cartItem->update([
			'price' => $price,
			'priceVat' => $priceVat,
			'vatPct' => $vatPct,
			'priceBefore' => $priceBefore ?? null,
			'priceVatBefore' => $priceVatBefore ?? null,
		]);

		// Get related cart items
		/** @var array<\Eshop\DB\RelatedCartItem> $relatedCartItems */
		$relatedCartItems = $this->relatedCartItemRepository->many()->where('fk_cartItem', $cartItem->getPK())->toArray();

		if ($relatedCartItems) {
			// Calculate total price of related items
			$relatedItemsTotalPrice = 0;
			$relatedItemsTotalPriceVat = 0;

			foreach ($relatedCartItems as $relatedCartItem) {
				$relatedItemsTotalPrice += $relatedCartItem->price * $relatedCartItem->amount;
				$relatedItemsTotalPriceVat += $relatedCartItem->priceVat * $relatedCartItem->amount;
			}

			// Calculate price modifiers
			$priceModifier = $relatedItemsTotalPrice > 0 ? $price / $relatedItemsTotalPrice : 1;
			$priceVatModifier = $relatedItemsTotalPriceVat > 0 ? $priceVat / $relatedItemsTotalPriceVat : 1;

			// Update each related cart item's price
			foreach ($relatedCartItems as $relatedCartItem) {
				$newPrice = $relatedCartItem->price * $priceModifier;
				$newPriceVat = $relatedCartItem->priceVat * $priceVatModifier;

				$this->relatedCartItemRepository->syncOne([
					'uuid' => $relatedCartItem->getPK(),
					'price' => $newPrice,
					'priceVat' => $newPriceVat,
					'priceBefore' => null,
					'priceVatBefore' => null,
				]);
			}
		}

		return $cartItem;
	}
}
