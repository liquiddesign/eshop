<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\PaymentType>
 */
class PaymentTypeRepository extends \StORM\Repository implements IGeneralRepository
{
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$mutationSuffix = $this->getConnection()->getMutationSuffix();

		return $this->getCollection($includeHidden)
			->select(['fullName' => "IF(this.systemicLock > 0, CONCAT(name$mutationSuffix, ' (', code, ', systémový)'), CONCAT(name$mutationSuffix, ' (', code, ')'))"])
			->toArrayOf('fullName');
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();
		
		if (!$includeHidden) {
			$collection->where('hidden', false);
		}
		
		return $collection->orderBy(['priority DESC', "name$suffix"]);
	}
	
	public function getPaymentTypes(Currency $currency, ?Customer $customer, ?CustomerGroup $customerGroup): Collection
	{
		$allowedPayments = $customer ? $customer->exclusivePaymentTypes->toArrayOf('uuid', [], true) : null;
		
		$collection = $this->many()
			->join(['prices' => 'eshop_paymenttypeprice'], 'prices.fk_paymentType=this.uuid AND prices.fk_currency=:currency', ['currency' => $currency])
			->where('hidden', false)
			->orderBy(['priority']);
		
		$collection->select(['price' => 'IFNULL(prices.price,0)', 'priceVat' => 'IFNULL(prices.priceVat,0)', 'priceBefore' => 'NULL', 'priceBeforeVat' => 'NULL']);
		
		if ($allowedPayments) {
			$collection->where('this.uuid', $allowedPayments);
		} elseif ($customerGroup) {
			$collection->where('fk_exclusive IS NULL OR fk_exclusive = :customerGroup', ['customerGroup' => $customerGroup]);
		}
		
		return $collection;
	}
}
