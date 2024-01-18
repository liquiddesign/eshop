<?php

declare(strict_types=1);

namespace Eshop\DB;

use Base\ShopsConfig;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\DeliveryDiscount>
 */
class DeliveryDiscountRepository extends \StORM\Repository
{
	public function __construct(DIConnection $connection, SchemaManager $schemaManager, protected readonly ShopsConfig $shopsConfig)
	{
		parent::__construct($connection, $schemaManager);
	}

	public function getDeliveryDiscount(Currency $currency): ?DeliveryDiscount
	{
		$collection = $this->many()
			->where('fk_currency', $currency)
			->where('discount.validFrom IS NULL OR discount.validFrom <= now()')
			->where('discount.validTo IS NULL OR discount.validTo >= now()')
			->orderBy(['discountPriceFrom' => 'ASC']);

		if ($shop = $this->shopsConfig->getSelectedShop()) {
			$collection->where('discount.fk_shop = :shop OR discount.fk_shop IS NULL', ['shop' => $shop->getPK()]);
		}

		return $collection->first();
	}

	public function getActiveDeliveryDiscount(Currency $currency, float $sumPrice, ?float $cartWeight = null): ?DeliveryDiscount
	{
		$collection = $this->many()
			->where('fk_currency', $currency)
			->where('discountPriceFrom <= :sumPrice', ['sumPrice' => $sumPrice])
			->where('discount.validFrom IS NULL OR discount.validFrom <= now()')
			->where('discount.validTo IS NULL OR discount.validTo >= now()')
			->orderBy(['discountPriceFrom' => 'DESC']);

		if ($cartWeight) {
			$collection->where('(this.weightFrom IS NULL OR this.weightFrom <= :weight) AND (this.weightTo IS NULL OR this.weightTo >= :weight)', ['weight' => $cartWeight]);
		}

		if ($shop = $this->shopsConfig->getSelectedShop()) {
			$collection->where('discount.fk_shop = :shop OR discount.fk_shop IS NULL', ['shop' => $shop->getPK()]);
		}

		return $collection->first();
	}

	public function getNextDeliveryDiscount(Currency $currency, float $sumPrice, ?float $cartWeight = null): ?DeliveryDiscount
	{
		$collection = $this->many()
			->where('fk_currency', $currency)
			->where('discountPriceFrom > :sumPrice', ['sumPrice' => $sumPrice])
			->where('discount.validFrom IS NULL OR discount.validFrom <= now()')
			->where('discount.validTo IS NULL OR discount.validTo >= now()')
			->orderBy(['discountPriceFrom']);

		if ($cartWeight) {
			$collection->where('(this.weightFrom IS NULL OR this.weightFrom <= :weight) AND (this.weightTo IS NULL OR this.weightTo >= :weight)', ['weight' => $cartWeight]);
		}

		if ($shop = $this->shopsConfig->getSelectedShop()) {
			$collection->where('discount.fk_shop = :shop OR discount.fk_shop IS NULL', ['shop' => $shop->getPK()]);
		}

		return $collection->first();
	}
}
