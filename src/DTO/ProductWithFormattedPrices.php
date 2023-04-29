<?php

namespace Eshop\DTO;

use Eshop\DB\Product;

class ProductWithFormattedPrices
{
	public function __construct(
		private readonly Product $product,
		private readonly bool $showWithVat,
		private readonly bool $showWithoutVat,
		/** @var 'withVat'|'withoutVat' */
		private readonly string $priorityPrice,
		private readonly bool $canView,
		private readonly string $price,
		private readonly string $priceVat,
		private readonly ?string $priceBefore = null,
		private readonly ?string $priceVatBefore = null
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
}
