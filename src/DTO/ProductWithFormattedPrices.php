<?php

namespace Eshop\DTO;

use Eshop\DB\Customer;
use Eshop\DB\Product;
use Nette\Localization\Translator;

class ProductWithFormattedPrices
{
	public function __construct(
		private readonly Translator $translator,
		private readonly Product $product,
		private readonly bool $showWithVat,
		private readonly bool $showWithoutVat,
		/** @var 'withVat'|'withoutVat' */
		private readonly string $priorityPrice,
		private readonly bool $canView,
		private readonly string $price,
		private readonly string $priceVat,
		private readonly ?string $priceBefore,
		private readonly ?string $priceVatBefore,
		private readonly ?Customer $customer,
		private readonly ?int $discountPercent = null,
	) {
	}

	public function getProduct(): Product
	{
		return $this->product;
	}

	public function canView(): bool
	{
		return $this->canView;
	}

	/**
	 * @return string|null Returns formatted price with currency code, e.g. 100 Kč. If price is not set or user has not sufficient rights, returns null.
	 * If user can see both prices, returns price based on priority.
	 */
	public function getPrimaryPrice(): ?string
	{
		if (!$this->canView) {
			return null;
		}

		if ($this->showWithVat && $this->showWithoutVat) {
			return $this->priorityPrice === 'withVat' ? $this->priceVat : $this->price;
		}

		if ($this->showWithVat) {
			return $this->priceVat;
		}

		if ($this->showWithoutVat) {
			return $this->price;
		}

		return null;
	}

	/**
	 * @return string|null Returns formatted price with currency code, e.g. 100 Kč. If price is not set, user has not sufficient rights or user cant see both prices, returns null.
	 */
	public function getSecondaryPrice(): ?string
	{
		if (!$this->canView) {
			return null;
		}

		if ($this->showWithVat && $this->showWithoutVat) {
			return $this->priorityPrice === 'withVat' ? $this->price : $this->priceVat;
		}

		return null;
	}

	/**
	 * @return string|null Returns formatted price with currency code, e.g. 100 Kč. If price is not set or user has not sufficient rights, returns null.
	 * If user can see both prices, returns price based on priority.
	 */
	public function getPrimaryPriceBefore(): ?string
	{
		if (!$this->canView) {
			return null;
		}

		if ($this->showWithVat && $this->showWithoutVat) {
			return $this->priorityPrice === 'withVat' ? $this->priceVatBefore : $this->priceBefore;
		}

		if ($this->showWithVat) {
			return $this->priceVatBefore;
		}

		if ($this->showWithoutVat) {
			return $this->priceBefore;
		}

		return null;
	}

	/**
	 * @return string|null Returns formatted price with currency code, e.g. 100 Kč. If price is not set, user has not sufficient rights or user cant see both prices, returns null.
	 */
	public function getSecondaryPriceBefore(): ?string
	{
		if (!$this->canView) {
			return null;
		}

		if ($this->showWithVat && $this->showWithoutVat) {
			return $this->priorityPrice === 'withVat' ? $this->priceBefore : $this->priceVatBefore;
		}

		return null;
	}

	public function inStock(): bool
	{
		return $this->product->inStock();
	}

	public function getDisplayAmountText(): ?string
	{
		if ($this->product->displayAmount) {
			return $this->product->displayAmount->label;
		}

		return $this->inStock() ?
			$this->translator->translate('.inStockOnRequest', 'Skladem: na dotaz') :
			$this->translator->translate('.notInStock', 'Není skladem');
	}

	public function getDisplayDeliveryText(): ?string
	{
		if ($this->inStock()) {
			return ($text = $this->product->getDynamicDelivery()) ?
				$text :
				$this->translator->translate('.unknownDelivery', 'Neznámé dodání');
		}

		return null;
	}

	public function getStorageDateText(): ?string
	{
		if ($this->inStock()) {
			return null;
		}

		return $this->product->storageDate ?: $this->translator->translate('.storageUnknown', 'Naskladnění neznámé');
	}

	public function showWatchers(): bool
	{
		return !$this->inStock() && $this->customer;
	}

	public function getDiscountPercent(): int|null
	{
		return $this->discountPercent;
	}
}
