<?php

namespace Eshop\DTO;

use Eshop\DB\Customer;
use Eshop\DB\Product;
use Nette\Localization\Translator;

class ProductWithFormattedPrices
{
	public function __construct(
		protected readonly Translator $translator,
		protected readonly Product $product,
		protected readonly bool $showWithVat,
		protected readonly bool $showWithoutVat,
		/** @var 'withVat'|'withoutVat' */
		protected readonly string $priorityPrice,
		protected readonly bool $canView,
		protected readonly string $price,
		protected readonly string $priceVat,
		protected readonly float $priceNumeric,
		protected readonly float $priceVatNumeric,
		protected readonly ?string $priceBefore,
		protected readonly ?string $priceVatBefore,
		protected readonly ?Customer $customer,
		protected readonly ?int $discountPercent = null,
		protected readonly int $amount = 1,
		protected readonly ?string $priceSum = null,
		protected readonly ?string $priceVatSum = null,
		protected readonly ?string $priceBeforeSum = null,
		protected readonly ?string $priceVatBeforeSum = null,
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

	public function getAmount(): int
	{
		return $this->amount;
	}

	public function getPrimaryPriceSum(): string|null
	{
		if (!$this->canView) {
			return null;
		}

		if ($this->showWithVat && $this->showWithoutVat) {
			return $this->priorityPrice === 'withVat' ? $this->priceVatSum : $this->priceSum;
		}

		if ($this->showWithVat) {
			return $this->priceVatSum;
		}

		if ($this->showWithoutVat) {
			return $this->priceSum;
		}

		return null;
	}

	/**
	 * @return float|null Returns numeric price. If price is not set or user has not sufficient rights, returns null.
	 *  If user can see both prices, returns price based on priority.
	 */
	public function getPrimaryPriceNumeric(): float|null
	{
		if (!$this->canView) {
			return null;
		}

		if ($this->showWithVat && $this->showWithoutVat) {
			return $this->priorityPrice === 'withVat' ? $this->priceVatNumeric : $this->priceNumeric;
		}

		if ($this->showWithVat) {
			return $this->priceVatNumeric;
		}

		if ($this->showWithoutVat) {
			return $this->priceNumeric;
		}

		return null;
	}

	public function isPrimaryPriceWithVat(): bool|null
	{
		if (!$this->canView) {
			return null;
		}

		if ($this->showWithVat && $this->showWithoutVat) {
			return $this->priorityPrice === 'withVat';
		}

		if ($this->showWithVat) {
			return true;
		}

		if ($this->showWithoutVat) {
			return false;
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

	public function isSecondaryPriceWithVat(): bool|null
	{
		if (!$this->canView) {
			return null;
		}

		if ($this->showWithVat && $this->showWithoutVat) {
			return $this->priorityPrice !== 'withVat';
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
	 * @return string|null Returns formatted price with currency code, e.g. 100 Kč. If price is not set or user has not sufficient rights, returns null.
	 * If user can see both prices, returns price based on priority.
	 */
	public function getPrimaryPriceBeforeSum(): ?string
	{
		if (!$this->canView) {
			return null;
		}

		if ($this->showWithVat && $this->showWithoutVat) {
			return $this->priorityPrice === 'withVat' ? $this->priceVatBeforeSum : $this->priceBeforeSum;
		}

		if ($this->showWithVat) {
			return $this->priceVatBeforeSum;
		}

		if ($this->showWithoutVat) {
			return $this->priceBeforeSum;
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
