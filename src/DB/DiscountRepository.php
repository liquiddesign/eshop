<?php

declare(strict_types=1);

namespace Eshop\DB;

use Carbon\Carbon;
use Common\DB\IGeneralRepository;
use League\Csv\Writer;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Discount>
 */
class DiscountRepository extends \StORM\Repository implements IGeneralRepository
{
	private DiscountCouponRepository $discountCouponRepository;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, DiscountCouponRepository $discountCouponRepository)
	{
		parent::__construct($connection, $schemaManager);

		$this->discountCouponRepository = $discountCouponRepository;
	}

	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		unset($includeHidden);

		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		return $this->many()->select(['fullName' => "
		IF(
			this.name$mutationSuffix IS NOT NULL AND this.internalName$mutationSuffix IS NOT NULL,
			CONCAT(
				this.name$mutationSuffix,
				' (',
				this.internalName$mutationSuffix ,
				')'
			),
			IF (this.name$mutationSuffix IS NOT NULL, this.name$mutationSuffix, this.internalName$mutationSuffix)
		)"])->orderBy(['name'])->toArrayOf('fullName');
	}
	
	public function isTagAssignedToDiscount(Discount $discount, Tag $tag): bool
	{
		return $this->getConnection()->rows(['eshop_discount_nxn_eshop_tag'])
				->where('fk_discount', $discount->getPK())
				->where('fk_tag', $tag->getPK())
				->count() === 1;
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		unset($includeHidden);

		return $this->many()->orderBy(['validTo', 'validFrom', 'name']);
	}
	
	public function getActiveDiscounts(): Collection
	{
		return $this->many()
			->where('IF (validFrom IS NOT NULL AND validTo IS NOT NULL,
			validFrom <= NOW() AND NOW() <= validTo,
			IF(validFrom IS NOT NULL OR validTo IS NOT NULL,
			(validFrom IS NOT NULL AND validFrom <= NOW()) OR (validTo IS NOT NULL AND NOW() <= validTo),
			TRUE)
			)')->orderBy(['validTo', 'validFrom']);
	}

	public function getValidCoupon(string $code, ?Currency $currency = null, ?Customer $customer = null): ?DiscountCoupon
	{
		return $this->getValidCoupons($currency, $customer)->where('code', $code)->first();
	}

	/**
	 * @param \Eshop\DB\Currency|null $currency
	 * @param \Eshop\DB\Customer|null $customer
	 * @return \StORM\Collection<\Eshop\DB\DiscountCoupon>
	 */
	public function getValidCoupons(?Currency $currency = null, ?Customer $customer = null): Collection
	{
		$activeDiscounts = $this->getActiveDiscounts()->toArray();

		$collection = $this->discountCouponRepository->many()
			->where('discount.uuid', \array_keys($activeDiscounts))
			->where('this.usageLimit IS NULL OR (this.usagesCount < this.usageLimit)');

		if ($currency) {
			$collection->where('fk_currency', $currency->getPK());
		}

		if ($customer) {
			$collection->where('fk_exclusiveCustomer IS NULL OR fk_exclusiveCustomer = :customer', ['customer' => $customer]);
		} else {
			$collection->where('fk_exclusiveCustomer IS NULL');
		}

		return $collection;
	}

	public function csvExportTargitoCoupons(Writer $writer, ?string $origin = null): void
	{
		$writer->setDelimiter(',');

		$writer->insertOne([
			'id',
			'origin',
			'name',
			'code',
			'valid_from',
			'valid_to',
			'usage_date',
			'valid_to_formatted',
		]);

		foreach ($this->getValidCoupons()->where('this.targitoExport', true) as $coupon) {
			$writer->insertOne([
				\str_replace('-', '', Strings::webalize($coupon->discount->name)),
				$origin,
				$coupon->label,
				$coupon->code,
				$coupon->discount->validFrom ? Carbon::parse($coupon->discount->validFrom)->toDateString() : null,
				$coupon->discount->validTo ? Carbon::parse($coupon->discount->validTo)->toDateString() : null,
				$coupon->lastUsageTs ? Carbon::parse($coupon->lastUsageTs)->toDateString() : null,
				$coupon->discount->validTo ? Carbon::parse($coupon->discount->validTo)->format('d.m.Y') : null,
			]);
		}
	}
}
