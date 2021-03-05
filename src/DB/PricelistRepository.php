<?php

declare(strict_types=1);

namespace Eshop\DB;

use League\Csv\Reader;
use StORM\Collection;

/**
 * @extends \StORM\Repository<\Eshop\DB\Pricelist>
 */
class PricelistRepository extends \StORM\Repository
{
	/**
	 * @return \Storm\Collection<\Eshop\DB\Pricelist>|\Eshop\DB\Pricelist[]
	 */
	public function getPricelists(array $pks, Currency $currency, Country $country): Collection
	{
		$collection = $this->many()
			->where('isActive', true)
			->where('(discount.validFrom IS NULL OR discount.validFrom <= DATE(now())) AND (discount.validTo IS NULL OR discount.validTo >= DATE(now()))')
			->where('this.uuid', \array_values($pks))
			->where('fk_currency', $currency->getPK())
			->where('fk_country', $country->getPK());
		
		return $collection->orderBy(['priority']);
	}
	
	/**
	 * @return \Storm\Collection<\Eshop\DB\Pricelist>|\Eshop\DB\Pricelist[]
	 */
	public function getCustomerPricelists(Customer $customer, Currency $currency, Country $country): Collection
	{
		$collection = $this->many()
			->join(['nxn' => 'user_customer_nxn_eshop_pricelist'], 'fk_pricelist=this.uuid')
			->where('nxn.fk_customer', $customer->getPK())
			->where('isActive', true)
			->where('(discount.validFrom IS NULL OR discount.validFrom <= DATE(now())) AND (discount.validTo IS NULL  OR discount.validTo >= DATE(now()))')
			->where('fk_currency ', $currency->getPK())
			->where('fk_country', $country->getPK());
		
		return $collection->orderBy(['priority']);
	}
	
	public function removeCustomerPricelist(Customer $customer, Pricelist $pricelist): void
	{
		$this->connection->rows(['user_customer_nxn_eshop_pricelist'])
			->where('fk_customer', $customer->getPK())
			->where('fk_pricelist', $pricelist->getPK())
			->delete();
	}
	
	public function getPricelistCustomers(Pricelist $pricelist): array
	{
		return $this->getConnection()->findRepository(Customer::class)->many()
			->join(['nxn' => 'user_customer_nxn_eshop_pricelist'], 'this.uuid=nxn.fk_customer')
			->where('nxn.fk_pricelist', $pricelist->getPK())
			->toArray();
	}
	
	public function getPricelistCustomersCount(Pricelist $pricelist): int
	{
		return $this->many()
			->join(['nxn' => 'user_customer_nxn_eshop_pricelist'], 'this.uuid=nxn.fk_pricelist')
			->where('nxn.fk_pricelist', $pricelist->getPK())
			->count();
	}
	
	public function removeAllCustomersFromPricelist(Pricelist $pricelist): void
	{
		$this->getConnection()->rows(['nxn' => 'user_customer_nxn_eshop_pricelist'])
			->where('nxn.fk_pricelist', $pricelist->getPK())
			->delete();
	}
	
	public function addCustomerToPricelist(Customer $customer, Pricelist $pricelist): void
	{
		$this->getConnection()->createRow('user_customer_nxn_eshop_pricelist', [
			'fk_customer' => $customer->getPK(),
			'fk_pricelist' => $pricelist->getPK(),
		]);
	}
	
	public function copyPrices(Pricelist $from, Pricelist $to, float $modificator, int $roundPrecision, bool $overwrite = false, bool $fillBeforePrices = false, bool $quantityPrices = false)
	{
		$priceRepository = $this->getConnection()->findRepository($quantityPrices ? QuantityPrice::class : Price::class);
		
		/** @var Price[] $originalPrices */
		$originalPrices = $priceRepository->many()->where('fk_pricelist', $from->getPK());
		
		foreach ($originalPrices as $originalPrice) {
			$values = [
				'pricelist' => $to->getPK(),
				'product' => $originalPrice->getValue('product'),
				'price' => \round($originalPrice->price * $modificator, $roundPrecision),
				'priceVat' => \round($originalPrice->priceVat * $modificator, $roundPrecision),
			];
			
			if ($quantityPrices) {
				$values['validFrom'] = $originalPrice->validFrom;
			}
			
			if ($fillBeforePrices && !$quantityPrices) {
				$values += [
					'priceBefore' => $originalPrice->price,
					'priceVatBefore' => $originalPrice->priceVat,
				];
			}
			
			$priceRepository->syncOne($values, $overwrite ? null : []);
		}
	}
	
	public function getArrayForSelect():array
	{
		return $this->many()->toArrayOf('name');
	}

	public function getAllPricelists(): array
	{
		return $this->many()->toArray();
	}
	
	
	public function getDefaultPricelists(): Collection
	{
		$collection = $this->getConnection()->findRepository(CustomerGroup::class)->many();
		// @TODO: refactor to one SQL
		$pricelists = [];
		
		foreach ($collection->where('this.uuid = :unregistred OR defaultAfterRegistration=1', ['unregistred' => CustomerGroupRepository::UNREGISTERED_PK]) as $group) {
			$pricelists = \array_merge($pricelists, $group->defaultPricelists->toArrayOf('uuid', [], true));
		}
	
		return $this->many()->where('this.uuid', $pricelists);
	}
		
		
		public function csvExport(Pricelist $priceList, \League\Csv\Writer $writer, bool $quantityPrices = false)
	{
		$writer->setDelimiter(';');
		
		$values = [
			'product',
			'price',
			'priceVat',
			'priceBefore',
			'priceVatBefore',
		];
		
		if ($quantityPrices) {
			$values[] = 'validFrom';
		}
		
		$writer->insertOne($values);
		
		foreach ($this->getConnection()->findRepository($quantityPrices ? QuantityPrice::class : Price::class)->many()->where('fk_pricelist', $priceList) as $row) {
			$values = [
				$row->product->getFullCode(),
				$row->price,
				$row->priceVat,
			];
			
			if ($quantityPrices) {
				$values[] = $row->validFrom;
			} else {
				$values[] = $row->priceBefore;
				$values[] = $row->priceVatBefore;
			}
			
			$writer->insertOne($values);
		}
	}
	
	public function csvImport(Pricelist $pricelist, Reader $reader, bool $quantityPrices = false)
	{
		$reader->setDelimiter(';');
		$reader->setHeaderOffset(0);
		
		$iterator = $reader->getRecords();
		
		foreach ($iterator as $offset => $value) {
			$fullCode = \explode('.', $value['product']);
			$products = $this->getConnection()->findRepository(Product::class)->many()->where('this.code', $fullCode[0]);
			
			if (isset($fullCode[1])) {
				$products->where('this.subcode', $fullCode[1]);
			}
			
			if (!$product = $products->first()) {
				continue;
			}
			
			$values = [
				'product' => $product->getPK(),
				'pricelist' => $pricelist->getPK(),
				'price' => $value['price'] !== '' ? (float) $value['price'] : null,
				'priceVat' => $value['priceVat'] !== '' ? (float) $value['priceVat'] : null,
			];
			
			if ($quantityPrices) {
				$values['validFrom'] = $value['validFrom'] !== '' ? (int) $value['validFrom'] : null;
			} else {
				$values['priceBefore'] = $value['priceBefore'] !== '' ? (float) $value['priceBefore'] : null;
				$values['priceVatBefore'] = $value['priceVatBefore'] !== '' ? (float) $value['priceVatBefore'] : null;
			}
			
			$this->getConnection()->findRepository($quantityPrices ? QuantityPrice::class : Price::class)->syncOne($values);
		}
	}
	
}
