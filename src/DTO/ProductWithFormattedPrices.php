<?php

namespace Eshop\DTO;

use Eshop\DB\Customer;
use Eshop\DB\Product;
use Nette\Localization\Translator;

class ProductWithFormattedPrices
{
	public function __construct(
		/** @codingStandardsIgnoreStart */
		private Translator $translator,
		private Product $product,
		private bool $showWithVat,
		private bool $showWithoutVat,
		/** @var 'withVat'|'withoutVat' */
		private string $priorityPrice,
		private bool $canView,
		private string $price,
		private string $priceVat,
		private ?string $priceBefore = null,
		private ?string $priceVatBefore = null,
		private ?Customer $customer = null,
		/** @codingStandardsIgnoreEnd */
	) {
		// Nothing here
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
	 * @return 'withVat'|'withoutVat'|null
	 */
	public function getPrimaryPriceType(): ?string
	{
		if (!$this->canView) {
			return null;
		}

		if ($this->showWithVat && $this->showWithoutVat) {
			return $this->priorityPrice;
		}

		if ($this->showWithVat) {
			return 'withVat';
		}

		if ($this->showWithoutVat) {
			return 'withoutVat';
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
	 * @return 'withVat'|'withoutVat'|null
	 */
	public function getSecondaryPriceType(): ?string
	{
		if (!$this->canView) {
			return null;
		}

		if ($this->showWithVat && $this->showWithoutVat) {
			return $this->priorityPrice === 'withVat' ? 'withoutVat' : 'withVat';
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
}
