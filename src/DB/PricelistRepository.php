<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use League\Csv\Reader;
use Nette\Utils\Arrays;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Pricelist>
 */
class PricelistRepository extends \StORM\Repository implements IGeneralRepository
{
	private PriceRepository $priceRepository;

	public function __construct(DIConnection $connection, SchemaManager $schemaManager, PriceRepository $priceRepository)
	{
		parent::__construct($connection, $schemaManager);

		$this->priceRepository = $priceRepository;
	}

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
			->join(['nxn' => 'eshop_customer_nxn_eshop_pricelist'], 'fk_pricelist=this.uuid')
			->where('nxn.fk_customer', $customer->getPK())
			->where('isActive', true)
			->where('(discount.validFrom IS NULL OR discount.validFrom <= DATE(now())) AND (discount.validTo IS NULL  OR discount.validTo >= DATE(now()))')
			->where('fk_currency ', $currency->getPK())
			->where('fk_country', $country->getPK());

		return $collection->orderBy(['priority']);
	}

	/**
	 * @return \Storm\Collection<\Eshop\DB\Pricelist>|\Eshop\DB\Pricelist[]
	 */
	public function getMerchantPricelists(Merchant $merchant, Currency $currency, Country $country): Collection
	{
		$collection = $this->many()
			->join(['nxn' => 'eshop_merchant_nxn_eshop_pricelist'], 'fk_pricelist=this.uuid')
			->where('nxn.fk_merchant', $merchant->getPK())
			->where('isActive', true)
			->where('(discount.validFrom IS NULL OR discount.validFrom <= DATE(now())) AND (discount.validTo IS NULL  OR discount.validTo >= DATE(now()))')
			->where('fk_currency ', $currency->getPK())
			->where('fk_country', $country->getPK());

		return $collection->orderBy(['priority']);
	}

	public function removeCustomerPricelist(Customer $customer, Pricelist $pricelist): void
	{
		$this->connection->rows(['eshop_customer_nxn_eshop_pricelist'])
			->where('fk_customer', $customer->getPK())
			->where('fk_pricelist', $pricelist->getPK())
			->delete();
	}

	public function getPricelistCustomers(Pricelist $pricelist): array
	{
		return $this->getConnection()->findRepository(Customer::class)->many()
			->join(['nxn' => 'eshop_customer_nxn_eshop_pricelist'], 'this.uuid=nxn.fk_customer')
			->where('nxn.fk_pricelist', $pricelist->getPK())
			->toArray();
	}

	public function getPricelistCustomersCount(Pricelist $pricelist): int
	{
		return $this->many()
			->join(['nxn' => 'eshop_customer_nxn_eshop_pricelist'], 'this.uuid=nxn.fk_pricelist')
			->where('nxn.fk_pricelist', $pricelist->getPK())
			->count();
	}

	public function removeAllCustomersFromPricelist(Pricelist $pricelist): void
	{
		$this->getConnection()->rows(['nxn' => 'eshop_customer_nxn_eshop_pricelist'])
			->where('nxn.fk_pricelist', $pricelist->getPK())
			->delete();
	}

	public function addCustomerToPricelist(Customer $customer, Pricelist $pricelist): void
	{
		$this->getConnection()->createRow('eshop_customer_nxn_eshop_pricelist', [
			'fk_customer' => $customer->getPK(),
			'fk_pricelist' => $pricelist->getPK(),
		]);
	}

	public function copyPrices(
		Pricelist $from,
		Pricelist $to,
		float $modificator,
		int $roundPrecision,
		bool $overwrite = false,
		bool $fillBeforePrices = false,
		bool $quantityPrices = false
	)
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

	public function copyPricesArray(
		array $ids,
		Pricelist $to,
		float $modificator,
		int $roundPrecision,
		bool $overwrite = false,
		bool $fillBeforePrices = false,
		bool $quantityPrices = false
	)
	{
		$priceRepository = $this->getConnection()->findRepository($quantityPrices ? QuantityPrice::class : Price::class);

		/** @var Price[] $originalPrices */
		$originalPrices = $priceRepository->many()->where('uuid', $ids);

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

	public function getArrayForSelect(bool $includeHidden = true): array
	{
		return $this->getCollection($includeHidden)->toArrayOf('name');
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

		foreach (
			$collection->where('this.uuid = :unregistred OR defaultAfterRegistration=1',
				['unregistred' => CustomerGroupRepository::UNREGISTERED_PK]) as $group
		) {
			$pricelists = \array_merge($pricelists, $group->defaultPricelists->toArrayOf('uuid', [], true));
		}

		return $this->many()->where('this.uuid', $pricelists)->orderBy(['this.priority']);
	}

	public function csvExport(
		Pricelist $priceList,
		\League\Csv\Writer $writer,
		bool $quantityPrices = false,
		bool $showVat = true
	)
	{
		$writer->setDelimiter(';');
		$writer->insertOne([
			'product',
			'price',
			'priceVat',
			'priceBefore',
			'priceVatBefore'
		]);

		foreach (
			$this->getConnection()->findRepository($quantityPrices ? QuantityPrice::class : Price::class)->many()->where('fk_pricelist',
				$priceList) as $row
		) {
			$values = [
				$row->product->getFullCode(),
				$row->price
			];

			$values[] = $showVat ? $row->priceVat : 0;

			if ($quantityPrices) {
				$values[] = $row->validFrom;
			} else {
				$values[] = $row->priceBefore;

				if ($showVat) {
					$values[] = $showVat ? $row->priceVatBefore : null;
				}
			}

			$writer->insertOne($values);
		}
	}

	public function csvImport(Pricelist $pricelist, Reader $reader, bool $quantityPrices = false)
	{
		$reader->setDelimiter(';');
		$reader->setHeaderOffset(0);

		$iterator = $reader->getRecords([
			'product',
			'price',
			'priceVat',
			'priceBefore',
			'priceVatBefore'
		]);

		foreach ($iterator as $offset => $value) {
			$fullCode = \explode('.', $value['product']);
			$products = $this->getConnection()->findRepository(Product::class)->many()->where('this.code',
				$fullCode[0]);

			if (isset($fullCode[1])) {
				$products->where('this.subcode', $fullCode[1]);
			}

			if (!$product = $products->first()) {
				continue;
			}

			$values = [
				'product' => $product->getPK(),
				'pricelist' => $pricelist->getPK(),
				'price' => $value['price'] !== '' ? \floatval(\str_replace(',', '.', \str_replace('.', '', $value['price']))) : 0,
				'priceVat' => $value['priceVat'] !== '' ? \floatval(\str_replace(',', '.', \str_replace('.', '', $value['priceVat']))) : 0,
			];

			if ($quantityPrices) {
				$values['validFrom'] = $value['validFrom'] !== '' ? (int)$value['validFrom'] : null;
			} else {
				$values['priceBefore'] = $value['priceBefore'] !== '' ? \floatval(\str_replace(',', '.', \str_replace('.', '', $value['priceBefore']))) : null;
				$values['priceVatBefore'] = $value['priceVatBefore'] !== '' ? \floatval(\str_replace(',', '.', \str_replace('.', '', $value['priceVatBefore']))) : null;
			}

			$this->getConnection()->findRepository($quantityPrices ? QuantityPrice::class : Price::class)->syncOne($values);
		}
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('isActive', true);
		}

		return $collection->orderBy(['priority', "name"]);
	}

	/**
	 * @param Pricelist[] $pricelists
	 * @return Currency|null
	 */
	public function checkSameCurrency(array $pricelists): ?Currency
	{
		if (\count($pricelists) == 0) {
			return null;
		}

		/** @var Currency $currency */
		$currency = Arrays::first($pricelists)->currency;

		foreach ($pricelists as $pricelist) {
			if ($pricelist->currency->getPK() != $currency->getPK()) {
				return null;
			}
		}

		return $currency;
	}

	/**
	 * @param Pricelist[] $pricelists
	 * @return array
	 */
	public function getTopPriorityPricelists(array $pricelists): array
	{
		$topPriority = PHP_INT_MAX;
		$result = [];

		foreach ($pricelists as $pricelist) {
			if ($pricelist->priority <= $topPriority) {
				$topPriority = $pricelist->priority;
				$result[$topPriority][$pricelist->getPK()] = $pricelist;
			}
		}

		return $result[$topPriority] ?? [];
	}

	/**
	 * Expecting pricelists with same currency!
	 * @param Pricelist[] $sourcePricelists
	 * @param Pricelist $targetPricelist
	 * @param string $aggregateFunction
	 * @param float $percentageChange
	 * @param int $roundingAccuracy
	 * @param bool $overwriteExisting
	 * @param bool $usePriority
	 */
	public function aggregatePricelists(
		array $sourcePricelists,
		Pricelist $targetPricelist,
		string $aggregateFunction,
		float $percentageChange = 100.0,
		int $roundingAccuracy = 2,
		bool $overwriteExisting = true,
		bool $usePriority = true
	)
	{
		$aggregateFunctions = ['min', 'max', 'avg', 'med'];

		if (!Arrays::contains($aggregateFunctions, $aggregateFunction)) {
			throw new \Exception("Unknown aggregate function '$aggregateFunction'!");
		}

		if ($usePriority) {
			$sourcePricelists = $this->getTopPriorityPricelists($sourcePricelists);
		}

		$prices = [];

		foreach ($sourcePricelists as $sourcePricelist) {
			/** @var Price[] $localPrices */
			$localPrices = $this->priceRepository->many()->where('fk_pricelist', $sourcePricelist->getPK())->toArray();

			foreach ($localPrices as $localPrice) {
				if (isset($prices[$localPrice->product->getPK()])) {
					$currentPrice = $prices[$localPrice->product->getPK()];

					if ($aggregateFunction == 'min') {
						if ($localPrice->price < $currentPrice['price']) {
							$currentPrice['price'] = $localPrice->price;
						}

						if ($localPrice->priceVat < $currentPrice['priceVat']) {
							$currentPrice['priceVat'] = $localPrice->priceVat;
						}
					} elseif ($aggregateFunction == 'max') {
						if ($localPrice->price > $currentPrice['price']) {
							$currentPrice['price'] = $localPrice->price;
						}

						if ($localPrice->priceVat > $currentPrice['priceVat']) {
							$currentPrice['priceVat'] = $localPrice->priceVat;
						}
					} elseif ($aggregateFunction == 'avg') {
						$currentPrice['price'] += $localPrice->price;
						$currentPrice['priceVat'] += $localPrice->priceVat;
						$currentPrice['count']++;
					} elseif ($aggregateFunction == 'med') {
						$currentPrice['priceArray'][] = $localPrice->price;
						$currentPrice['priceVatArray'][] = $localPrice->priceVat;
						$currentPrice['count']++;
					}

					$prices[$localPrice->product->getPK()] = $currentPrice;
				} else {
					$prices[$localPrice->product->getPK()] = [
						'price' => $localPrice->price,
						'priceVat' => $localPrice->priceVat,
						'count' => 1,
						'priceArray' => [$localPrice->price],
						'priceVatArray' => [$localPrice->priceVat]
					];
				}
			}
		}

		foreach ($prices as $productKey => $priceArray) {
			$existingPrice = $this->priceRepository->many()
				->where('fk_pricelist', $targetPricelist->getPK())
				->where('fk_product', $productKey)
				->first();

			if ($existingPrice && !$overwriteExisting) {
				continue;
			}

			$newValues = [
				'product' => $productKey,
				'pricelist' => $targetPricelist->getPK()
			];

			if ($aggregateFunction == 'min' || $aggregateFunction == 'max') {
				$newValues['price'] = \round($priceArray['price'] * ($percentageChange / 100.0), $roundingAccuracy);
				$newValues['priceVat'] = \round($priceArray['priceVat'] * ($percentageChange / 100.0), $roundingAccuracy);
			} elseif ($aggregateFunction == 'avg') {
				$newValues['price'] = \round(((float)$priceArray['price'] / $priceArray['count']) * ($percentageChange / 100.0), $roundingAccuracy);
				$newValues['priceVat'] = \round(((float)$priceArray['priceVat'] / $priceArray['count']) * ($percentageChange / 100.0), $roundingAccuracy);
			} elseif ($aggregateFunction == 'med') {
				if (\count($priceArray['priceArray']) == 1) {
					$newValues['price'] = $priceArray['priceArray'][0];
					$newValues['priceVat'] = $priceArray['priceVatArray'][0];
				} else {
					\sort($priceArray['priceArray']);
					\sort($priceArray['priceVatArray']);

					if (\fmod($priceArray['count'], 2) !== 0.00) {
						$middle = ($priceArray['count'] / 2) - 1;

						$newValues['price'] = $priceArray['priceArray'][$middle];
						$newValues['priceVat'] = $priceArray['priceVatArray'][$middle];
					} else {
						$middle1 = ($priceArray['count'] / 2.0) - 1;
						$middle2 = ($priceArray['count'] / 2.0) + 1 - 1;

						$newValues['price'] = \round((($priceArray['priceArray'][$middle1] + $priceArray['priceArray'][$middle2]) / 2.0) * ($percentageChange / 100.0), $roundingAccuracy);
						$newValues['priceVat'] = \round((($priceArray['priceVatArray'][$middle1] + $priceArray['priceVatArray'][$middle2]) / 2.0) * ($percentageChange / 100.0), $roundingAccuracy);
					}
				}
			}

			if ($existingPrice) {
				$existingPrice->update($newValues);
			} else {
				$this->priceRepository->createOne($newValues);
			}
		}
	}
}
