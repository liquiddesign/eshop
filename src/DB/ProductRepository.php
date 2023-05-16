<?php

declare(strict_types=1);

namespace Eshop\DB;

use Admin\DB\IGeneralAjaxRepository;
use Base\DB\Shop;
use Base\ShopsConfig;
use Common\DB\IGeneralRepository;
use Eshop\Admin\SettingsPresenter;
use Eshop\Controls\ProductFilter;
use Eshop\ShopperUser;
use InvalidArgumentException;
use League\Csv\EncloseField;
use League\Csv\Reader;
use League\Csv\Writer;
use Nette\Application\LinkGenerator;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Caching\Storages\DevNullStorage;
use Nette\Http\Request;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\DIConnection;
use StORM\Expression;
use StORM\ICollection;
use StORM\Repository;
use StORM\SchemaManager;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\PageRepository;
use Web\DB\SettingRepository;

/**
 * @extends \StORM\Repository<\Eshop\DB\Product>
 */
class ProductRepository extends Repository implements IGeneralRepository, IGeneralAjaxRepository
{
	/** @var array<callable(\Eshop\DB\Product, \Eshop\DB\SupplierProduct): void> */
	public array $onDummyProductCreated = [];

	private Cache $cache;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		private readonly AttributeRepository $attributeRepository,
		private readonly PageRepository $pageRepository,
		private readonly DeliveryDiscountRepository $deliveryDiscountRepository,
		private readonly LoyaltyProgramDiscountLevelRepository $loyaltyProgramDiscountLevelRepository,
		private readonly OrderRepository $orderRepository,
		private readonly RelatedRepository $relatedRepository,
		private readonly LinkGenerator $linkGenerator,
		private readonly Request $request,
		Storage $storage,
		private readonly SupplierProductRepository $supplierProductRepository,
		private readonly RelatedTypeRepository $relatedTypeRepository,
		private readonly QuantityPriceRepository $quantityPriceRepository,
		private readonly AttributeValueRepository $attributeValueRepository,
		private readonly CustomerGroupRepository $customerGroupRepository,
		private readonly SettingRepository $settingRepository,
		private readonly AttributeAssignRepository $attributeAssignRepository,
		private readonly ShopperUser $shopperUser,
		private readonly VisibilityListItemRepository $visibilityListItemRepository,
		private readonly VisibilityListRepository $visibilityListRepository,
		private readonly ShopsConfig $shopsConfig,
	) {
		parent::__construct($connection, $schemaManager);

		$this->cache = new Cache($storage);
	}
	
	/**
	 * @param string $productUuid
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getProduct(string $productUuid): mixed
	{
		return $this->getProducts()->where('this.uuid', $productUuid)->first(true);
	}
	
	public function getProductsAsCustomer(?Customer $customer, bool $selects = true): Collection
	{
		$priceLists = $customer ? $customer->pricelists : $this->customerGroupRepository->getUnregisteredGroup()->defaultPricelists;
		
		return $this->getProducts($this->getValidPricelists($priceLists)->toArray(), $customer, $selects, $customer === null ? $this->customerGroupRepository->getUnregisteredGroup() : null);
	}
	
	public function getProductsAsGroup(CustomerGroup $customerGroup, bool $selects = true): Collection
	{
		return $this->getProducts(
			$this->getValidPricelists($customerGroup->defaultPricelists)->toArray(),
			null,
			$selects,
			$customerGroup,
			visibilityLists: $this->getValidVisibilityLists($customerGroup->defaultVisibilityLists)->toArray(),
		);
	}

	public function getDiscountPct(?Customer $customer = null, ?CustomerGroup $customerGroup = null, ?DiscountCoupon $discountCoupon = null): int
	{
		$discountCoupon ??= $this->shopperUser->getCheckoutManager()->getDiscountCoupon();
		$customer = $customerGroup ? $customer : ($customer ?: $this->shopperUser->getCustomer());
		$customerGroup ??= $this->shopperUser->getCustomerGroup();
		$customerDiscount = $customer ? $this->getBestDiscountLevel($customer) : ($customerGroup ? $customerGroup->defaultDiscountLevelPct : 0);

		return \max($discountCoupon && $discountCoupon->discountPct ? (int) $discountCoupon->discountPct : 0, $customerDiscount);
	}

	/**
	 * @param array<\Eshop\DB\Pricelist>|null $pricelists
	 * @param \Eshop\DB\Customer|null $customer Used only when $customerGroup is not null
	 * @param bool $selects
	 * @param \Eshop\DB\CustomerGroup|null $customerGroup
	 * @param array<\Eshop\DB\VisibilityList>|null $visibilityLists
	 * @return \StORM\Collection<\Eshop\DB\Product>
	 */
	public function getProducts(
		?array $pricelists = null,
		?Customer $customer = null,
		bool $selects = true,
		?CustomerGroup $customerGroup = null,
		?array $visibilityLists = null
	): Collection {
		$discountCoupon = $this->shopperUser->getCheckoutManager()->getDiscountCoupon();
		
		$currency = $this->shopperUser->getCurrency();
		$convertRatio = null;
		
		if ($currency->isConversionEnabled()) {
			$convertRatio = $currency->convertRatio;
		}
		
		$pricelists ??= $this->shopperUser->getPricelists()->toArray();
		$pricelists = \array_values($pricelists);
		$customer = $customerGroup ? $customer : ($customer ?: $this->shopperUser->getCustomer());

		$customerGroup ??= $this->shopperUser->getCustomerGroup();
		
		$discountLevelPct = $this->getDiscountPct($customer, $customerGroup, $discountCoupon);
		$maxProductDiscountLevel = $customer ? $customer->maxDiscountProductPct : ($customerGroup ? $customerGroup->defaultMaxDiscountProductPct : 100);
		$vatRates = $this->shopperUser->getVatRates();
		$prec = $currency->calculationPrecision;

		$shopCategoryType = $this->shopsConfig->getSelectedShop() ?
			$this->settingRepository->getValueByName(SettingsPresenter::MAIN_CATEGORY_TYPE . '_' . $this->shopsConfig->getSelectedShop()->getPK()) :
			null;
		
		$generalPricelistIds = [];
		
		/** @var \Eshop\DB\Pricelist $pricelist */
		foreach ($pricelists as $pricelist) {
			if ($pricelist->allowDiscountLevel) {
				$generalPricelistIds[] = $pricelist->getPK();
			}
			
			if ($pricelist->getValue('currency') === $currency->getPK() || !$convertRatio) {
				continue;
			}
		}
		
		if (!$pricelists) {
			Debugger::barDump('No PriceLists');

			return $this->many()->where('1=0');
		}

		$suffix = $this->getConnection()->getMutationSuffix();
		$sep = '|';
		$priorityLpad = '3';
		$priceLpad = (string) ($prec + 9);
		$priceSelects = $priceWhere = [];
		$collection = $this->many()->setSmartJoin(false);
		
		/** @var \Eshop\DB\Pricelist $pricelist */
		foreach ($pricelists as $id => $pricelist) {
			if ($selects) {
				$price = $this->sqlHandlePrice("prices$id", 'price', $discountLevelPct, $maxProductDiscountLevel, $generalPricelistIds, $prec, $convertRatio);
				$priceVat = $this->sqlHandlePrice("prices$id", 'priceVat', $discountLevelPct, $maxProductDiscountLevel, $generalPricelistIds, $prec, $convertRatio);
				$priceBefore = $this->sqlHandlePrice("prices$id", 'priceBefore', 0, 0, [], $prec, $convertRatio);
				$priceVatBefore = $this->sqlHandlePrice("prices$id", 'priceVatBefore', 0, 0, [], $prec, $convertRatio);
				$priceSelects[] = "IF(prices$id.price IS NULL,'X',CONCAT_WS('$sep',LPAD(" . $pricelist->priority .
					",$priorityLpad,'0'),LPAD(CAST($price AS DECIMAL($priceLpad,$prec)), $priceLpad, '0'),$priceVat,IFNULL($priceBefore,0),IFNULL($priceVatBefore,0),prices$id.fk_pricelist))";
			}
		}

		$visibilityLists ??= $this->shopperUser->getVisibilityLists();

		if (!$visibilityLists) {
			Debugger::barDump('No VisibilityLists');
		}

		$this->joinVisibilityListItemToProductCollection($collection, $visibilityLists);

		if ($selects) {
			$collection->select([
				'fk_visibilityListItem' => 'visibilityListItem.uuid',
				'hidden' => 'visibilityListItem.hidden',
				'hiddenInMenu' => 'visibilityListItem.hiddenInMenu',
				'unavailable' => 'visibilityListItem.unavailable',
				'recommended' => 'visibilityListItem.recommended',
				'priority' => 'visibilityListItem.priority',
			]);

			$defaultDisplayAmount = $this->settingRepository->getValueByName(SettingsPresenter::DEFAULT_DISPLAY_AMOUNT);
			$defaultUnavailableDisplayAmount = $this->settingRepository->getValueByName(SettingsPresenter::DEFAULT_UNAVAILABLE_DISPLAY_AMOUNT);

			if ($defaultDisplayAmount && $defaultUnavailableDisplayAmount) {
				$collection->select(['fk_displayAmount' => "IF(this.unavailable = '0', '$defaultDisplayAmount', '$defaultUnavailableDisplayAmount')"]);
			} elseif ($defaultDisplayAmount) {
				$collection->select(['fk_displayAmount' => "IF(this.unavailable = '0', '$defaultDisplayAmount', this.fk_displayAmount)"]);
			} elseif ($defaultUnavailableDisplayAmount) {
				$collection->select(['fk_displayAmount' => "IF(this.unavailable = '1', '$defaultUnavailableDisplayAmount', this.fk_displayAmount)"]);
			}
			
			$expression = \count($pricelists) > 1 ? 'LEAST(' . \implode(',', $priceSelects) . ')' : $priceSelects[0];
			
			$priceSelect = $this->sqlExplode($expression, $sep, 2);
			$priceVatSelect = $this->sqlExplode($expression, $sep, 3);
			
			$collection->select(['price' => $priceSelect]);
			$collection->select(['priceVat' => $priceVatSelect]);
			
			$beforeSelect = $this->sqlExplode($expression, $sep, 4);
			$beforeVatSelect = $this->sqlExplode($expression, $sep, 5);
			$pricelistId = $this->sqlExplode($expression, $sep, 6);
			
			$allowLevelDiscounts = \implode(',', \array_map(function ($value) {
				return "'$value'";
			}, $generalPricelistIds));
			
			$sqlDiscountLevel = "100/(100-IF($discountLevelPct > LEAST(this.discountLevelPct, $maxProductDiscountLevel),$discountLevelPct,LEAST(this.discountLevelPct, $maxProductDiscountLevel)))";
			$sqlComputeBefore = "($beforeSelect) > 0 OR ($discountLevelPct = 0 AND LEAST(this.discountLevelPct, $maxProductDiscountLevel) = 0) OR (($pricelistId) NOT IN ($allowLevelDiscounts))";
			
			$collection->select(['priceBefore' => \count($generalPricelistIds) ?
				"IF($sqlComputeBefore, $beforeSelect,$sqlDiscountLevel  * ($priceSelect))" :
				$beforeSelect,
			]);
			
			$collection->select(['priceVatBefore' => \count($generalPricelistIds) ?
				"IF($sqlComputeBefore, $beforeVatSelect,$sqlDiscountLevel * ($priceVatSelect))" :
				$beforeVatSelect,
			]);
			
			$collection->select(['pricelist' => $this->sqlExplode($expression, $sep, 6)]);
			$collection->select(['currencyCode' => "'" . $currency->code . "'"]);
			
			if (!$this->shopperUser->getShowZeroPrices()) {
				if ($this->shopperUser->getShowVat()) {
					$collection->where($this->sqlExplode($expression, $sep, 3) . ' > 0');
				}
				
				if ($this->shopperUser->getShowWithoutVat()) {
					$collection->where($this->sqlExplode($expression, $sep, 2) . ' > 0');
				}
			}
			
			$collection->select([
				'vatPct' => "IF(vatRate = 'standard'," . ($vatRates['standard'] ?? 0) . ",IF(vatRate = 'reduced-high'," .
					($vatRates['reduced-high'] ?? 0) . ",IF(vatRate = 'reduced-low'," . ($vatRates['reduced-low'] ?? 0) . ',0)))',
			]);
			
			$subSelect = $this->getConnection()
				->rows(
					['eshop_attributevalue'],
					[
						"GROUP_CONCAT(
							CONCAT_WS(
								'$sep',
								eshop_attributevalue.uuid,
								eshop_attributevalue.fk_attribute,
								IFNULL(eshop_attributevalue.label$suffix, ''),
								IFNULL(eshop_attributevalue.metaValue, ''),
								IFNULL(eshop_attribute.name$suffix, ''),
								IFNULL(eshop_attributevalue.imageFileName, ''),
								IFNULL(eshop_attributevalue.number, ''),
								IFNULL(eshop_attributevalue.note$suffix, ''),
								IFNULL(eshop_attribute.note$suffix, ''),
								IFNULL(eshop_attribute.priority, ''),
								IFNULL(eshop_attributevalue.priority, '')
							)
						SEPARATOR \";\")",
					],
				)
				->join(['eshop_attributeassign'], 'eshop_attributeassign.fk_value = eshop_attributevalue.uuid')
				->join(['eshop_attribute'], 'eshop_attribute.uuid = eshop_attributevalue.fk_attribute')
				->where('eshop_attribute.showProduct=1')
				->where('eshop_attributeassign.fk_product=this.uuid')
				->orderBy(['eshop_attribute.priority' => 'ASC', 'eshop_attributevalue.priority' => 'ASC']);
			$collection->select(['parameters' => $subSelect]);
			
			$subSelect = $this->getConnection()->rows(['eshop_ribbon'], ['GROUP_CONCAT(uuid)'])
				->join(['nxn' => 'eshop_product_nxn_eshop_ribbon'], 'eshop_ribbon.uuid = nxn.fk_ribbon')
				->where('nxn.fk_product=this.uuid');

			if ($this->shopsConfig->getSelectedShop()) {
				$subSelect->where(
					'eshop_ribbon.fk_shop = :eshop_ribbon_shop OR eshop_ribbon.fk_shop IS NULL',
					['eshop_ribbon_shop' => $this->shopsConfig->getSelectedShop()->getPK()]
				);
			}

			$collection->select(['ribbonsIds' => $subSelect], $subSelect->getVars());
			
			$collection->join(['productPrimaryCategory' => 'eshop_productprimarycategory'], 'this.uuid=productPrimaryCategory.fk_product');
			$collection->join(['primaryCategory' => 'eshop_category'], 'productPrimaryCategory.fk_category=primaryCategory.uuid');

			if ($shopCategoryType) {
				$collection->where(
					'productPrimaryCategory.fk_categoryType = :productPrimaryCategory_shopCategoryType OR productPrimaryCategory.fk_categoryType IS NULL',
					['productPrimaryCategory_shopCategoryType' => $shopCategoryType],
				);
			}

			$collection->join(['productContent' => 'eshop_productcontent'], 'this.uuid = productContent.fk_product');

			if ($shop = $this->shopsConfig->getSelectedShop()) {
				$collection->where('productContent.fk_shop', $shop->getPK());
			}

			$collection->select([
				'fallbackImage' => 'primaryCategory.productFallbackImageFileName',
				'primaryCategory' => 'primaryCategory.uuid',
				'primaryCategoryPath' => 'primaryCategory.path',
				'perex' => "COALESCE(NULLIF(productContent.perex$suffix, ''), NULLIF(primaryCategory.defaultProductPerex$suffix, ''))",
				'content' => "COALESCE(NULLIF(productContent.content$suffix, ''), NULLIF(primaryCategory.defaultProductContent$suffix, ''))",
				'originalContent' => "productContent.content$suffix",
			]);
			
			if ($customer) {
				$subSelect = $this->getConnection()->rows(['eshop_watcher'], ['uuid'])
					->where('eshop_watcher.fk_customer= :test')
					->where('eshop_watcher.fk_product=this.uuid');
				$collection->select(['fk_watcher' => $subSelect], ['test' => $customer->getPK()]);
			}
		}
		
		$this->setProductsConditions($collection, true, $pricelists);

		return $collection;
	}

	/**
	 * @param \League\Csv\Writer $writer
	 * @param \StORM\Collection<\Eshop\DB\VisibilityListItem> $objects
	 * @throws \League\Csv\CannotInsertRecord
	 * @throws \League\Csv\Exception
	 * @throws \League\Csv\InvalidArgument
	 */
	public function csvExportVisibilityListItem(Writer $writer, Collection $objects): void
	{
		$writer->setDelimiter(';');

		$writer->insertOne([
			'product',
			'list',
			'hidden',
			'hiddenInMenu',
			'unavailable',
			'recommended',
			'priority',
		]);

		$productsToBeFetched = [];
		$visibilityListsToBeFetched = [];

		foreach ($objects as $item) {
			$productsToBeFetched[] = $item->getValue('product');
			$visibilityListsToBeFetched[] = $item->getValue('visibilityList');
		}

		$products = $this->many()->where('this.uuid', $productsToBeFetched)->toArray();
		$visibilityLists = $this->visibilityListRepository->many()->where('this.uuid', $visibilityListsToBeFetched)->toArray();

		foreach ($objects as $item) {
			$writer->insertOne([
				$products[$item->getValue('product')]->getFullCode(),
				$visibilityLists[$item->getValue('visibilityList')]->code,
				$item->hidden ? '1' : '0',
				$item->hiddenInMenu ? '1' : '0',
				$item->unavailable ? '1' : '0',
				$item->recommended ? '1' : '0',
				$item->priority,
			]);
		}
	}

	/**
	 * @param \League\Csv\Reader $reader
	 * @param string $delimiter
	 * @return array{imported: int, skipped: int}
	 * @throws \League\Csv\Exception
	 * @throws \League\Csv\InvalidArgument
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function csvImportVisibilityListItem(Reader $reader, string $delimiter = ';'): array
	{
		$reader->setDelimiter($delimiter);
		$reader->setHeaderOffset(0);

		$iterator = $reader->getRecords([
			'product',
			'list',
			'hidden',
			'hiddenInMenu',
			'unavailable',
			'recommended',
			'priority',
		]);

		$visibilityLists = $this->visibilityListRepository->many()->toArrayOf('uuid');
		$productsByCode = $this->many()->setIndex('code')->toArrayOf('uuid');
		$productsByFullCode = $this->many()->select(['fullCode' => 'CONCAT(code,".",subCode)'])->setIndex('fullCode')->toArrayOf('uuid');

		$imported = 0;
		$skipped = 0;

		foreach ($iterator as $value) {
			$product = $productsByCode[$value['product']] ?? null;

			if (!$product) {
				$product = $productsByFullCode[$value['product']] ?? null;
			}

			if (!$product) {
				$skipped++;

				continue;
			}

			$visibilityList = $visibilityLists[$value['list']] ?? null;

			if (!$visibilityList) {
				$skipped++;

				continue;
			}

			$values = [];

			foreach (['hidden', 'hiddenInMenu', 'unavailable', 'recommended'] as $key) {
				if ($value[$key]) {
					$values[$key] = $value[$key] === '1';
				}
			}

			$values['priority'] = (int) $value['priority'];
			$values['product'] = $product;
			$values['visibilityList'] = $visibilityList;

			$this->visibilityListItemRepository->syncOne($values);

			$imported++;
		}

		return ['skipped' => $skipped, 'imported' => $imported];
	}

	/**
	 * Always hydrate visibilityListItem property and other properties
	 * @param \Eshop\DB\VisibilityListItem|null $visibilityListItem
	 * @param array<\Eshop\DB\VisibilityList>|null $visibilistyLists
	 */
	public function hydrateProductWithVisibilityListItemProperties(Product $product, ?VisibilityListItem $visibilityListItem = null, ?array $visibilistyLists = null): void
	{
		if (!$visibilityListItem) {
			if (!$visibilistyLists) {
				$visibilistyLists = $this->shopperUser->getVisibilityLists();
			}

			$visibilityListItem = $this->visibilityListItemRepository->many()
				->where('this.fk_product', $product->getPK())
				->where('this.fk_visibilityList', \array_keys($visibilistyLists))
				->where('visibilityList.hidden', false)
				->orderBy(['visibilityList.priority'])
				->first();
		}

		if (!$visibilityListItem) {
			return;
		}

		$product->visibilityListItem = $visibilityListItem;
		$product->setValue('hidden', $visibilityListItem->hidden);
		$product->setValue('hiddenInMenu', $visibilityListItem->hiddenInMenu);
		$product->setValue('recommended', $visibilityListItem->recommended);
		$product->setValue('priority', $visibilityListItem->priority);
	}

	public function hydrateProductWithContent(Product $product, Shop|null $shop = null): void
	{
		$shop ??= $this->shopsConfig->getSelectedShop();
		$contentsCollection = $product->contents;

		if ($shop) {
			$contentsCollection->where('this.fk_shop', $shop->getPK());
		}

		$productContent = $contentsCollection->first();

		$product->setValue('content', $productContent?->content);
		$product->setValue('perex', $productContent?->perex);
	}
	
	public function getQuantityPrice(Product $product, int $amount, string $property): ?float
	{
		$customer = $this->shopperUser->getCustomer();
		$discountLevelPct = $customer ? $this->getBestDiscountLevel($customer) : 0;
		
		/** @var float|null $price */
		$price = $this->quantityPriceRepository->many()
			->where('this.fk_product', $product->getPK())
			->where('this.fk_pricelist', $product->getValue('pricelist'))
			->where('validFrom <= :amount', ['amount' => $amount])
			->orderBy(['validFrom' => 'DESC'])
			->firstValue($property);
		
		$price = $price ? (float) $price : null;
		
		return $discountLevelPct > 0 ? (100 - $discountLevelPct) / 100 * $price : $price;
	}
	
	/**
	 * @param \StORM\ICollection $collection
	 * @param bool $includeHidden
	 * @param array<\Eshop\DB\Pricelist>|null $pricelists
	 */
	public function setProductsConditions(ICollection $collection, bool $includeHidden = true, ?array $pricelists = null): void
	{
		$pricelists = $pricelists ?: \array_values($this->shopperUser->getPricelists()->toArray());
		$priceWhere = new Expression();
		
		foreach ($pricelists as $id => $pricelist) {
			$collection->join(["prices$id" => 'eshop_price'], "prices$id.fk_product=this.uuid AND prices$id.fk_pricelist = '" . $pricelist->getPK() . "'");
			
			$priceZeroWhere = null;
			
			if (!$this->shopperUser->getShowZeroPrices()) {
				if ($this->shopperUser->getShowVat() && $this->shopperUser->getShowWithoutVat()) {
					$priceZeroWhere = " AND prices$id.price > 0 AND prices$id.priceVat > 0";
				}
				
				if ($this->shopperUser->getShowVat()) {
					$priceZeroWhere = " AND prices$id.priceVat > 0";
				}
				
				if ($this->shopperUser->getShowWithoutVat()) {
					$priceZeroWhere = " AND prices$id.price > 0";
				}
			}
			
			$priceWhere->add('OR', "prices$id.price IS NOT NULL" . ($priceZeroWhere ?: ''));
		}
		
		if (!$includeHidden) {
			$collection->where('visibilityListItem.hidden = 0');
		}
		
		if (!$sql = $priceWhere->getSql()) {
			return;
		}
		
		$collection->where($sql);
	}
	
	/**
	 * @param string $groupBy
	 * @param array<string, mixed> $filters
	 * @return array<string, string>
	 */
	public function getCountGroupedBy(string $groupBy, $filters): array
	{
		$index = $this->shopperUser->getPriceCacheIndex($groupBy, $filters);
		$cache = $index ? $this->cache : new Cache(new DevNullStorage());
		$productRepository = $this;
		
		return $cache->load($index, static function (&$dependencies) use ($groupBy, $filters, $productRepository) {
			$dependencies = [
				Cache::TAGS => ['categories', 'products', 'pricelists'],
			];
			$rows = $productRepository->many();
			$rows->setSelect(['count' => "COUNT($groupBy)"])
				->setIndex($groupBy)
				->setGroupBy([$groupBy]);
			$productRepository->setProductsConditions($rows, false);
			$productRepository->filter($rows, $filters);
			
			return $rows->toArrayOf('count');
		});
	}
	
	public function getRealLoyaltyDiscountLevel(Customer $customer): ?LoyaltyProgramDiscountLevel
	{
		$loyaltyProgram = $customer->loyaltyProgram;
		
		if ($loyaltyProgram === null || $loyaltyProgram->isActive() === false) {
			return null;
		}
		
		$customerTurnover = $this->orderRepository->getCustomerTotalTurnover($customer, $loyaltyProgram->turnoverFrom ? new \Carbon\Carbon($loyaltyProgram->turnoverFrom) : null, new \Carbon\Carbon());
		
		return $this->loyaltyProgramDiscountLevelRepository->many()
			->where('this.fk_loyaltyProgram', $loyaltyProgram->getPK())
			->where('this.priceThreshold <= :turnover', ['turnover' => (string) $customerTurnover])
			->orderBy(['this.discountLevel' => 'DESC'])
			->first();
	}
	
	public function getBestDiscountLevel(Customer $customer): int
	{
		$loyaltyProgram = $customer->loyaltyProgram;
		
		if ($loyaltyProgram === null || $loyaltyProgram->isActive() === false) {
			return $customer->discountLevelPct;
		}
		
		$loyaltyProgramDiscountLevel = $customer->loyaltyProgramDiscountLevel;
		
		return $loyaltyProgramDiscountLevel ? \max($loyaltyProgramDiscountLevel->discountLevel, $customer->discountLevelPct) : $customer->discountLevelPct;
	}
	
	public function getCurrentDiscountThreshold(Customer $customer): ?float
	{
		$loyaltyProgram = $customer->loyaltyProgram;
		
		if ($loyaltyProgram === null || $loyaltyProgram->isActive() === false || !$customer->loyaltyProgramDiscountLevel) {
			return null;
		}
		
		return $customer->loyaltyProgramDiscountLevel->priceThreshold;
	}
	
	/**
	 * Get next best discount level - only works if customer has loyalty program
	 * @param \Eshop\DB\Customer $customer
	 * @return array<int|float>|null Returns array of 2 elements - NextDiscountLevel and NextDiscountThreshold [0 => int, 1 => float]
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getNextBestDiscountLevel(Customer $customer): ?array
	{
		$loyaltyProgram = $customer->loyaltyProgram;
		
		if ($loyaltyProgram === null || $loyaltyProgram->isActive() === false) {
			return null;
		}
		
		$currentBestDiscountLevel = $customer->loyaltyProgramDiscountLevel;
		
		if (!$currentBestDiscountLevel) {
			return null;
		}
		
		/** @var \Eshop\DB\LoyaltyProgramDiscountLevel|null $discountLevel */
		$discountLevel = $this->loyaltyProgramDiscountLevelRepository->many()
			->where('this.fk_loyaltyProgram', $loyaltyProgram->getPK())
			->where('this.priceThreshold > :turnover', ['turnover' => $currentBestDiscountLevel->priceThreshold])
			->orderBy(['this.discountLevel' => 'ASC'])
			->first();
		
		if ($discountLevel) {
			return [
				\max($currentBestDiscountLevel->discountLevel, $discountLevel->discountLevel),
				$discountLevel->priceThreshold,
			];
		}
		
		return null;
	}
	
	/**
	 * Get default SELECT modifier array for new collection
	 * @return array<string>
	 */
	public function getDefaultSelect(?string $mutation = null, ?array $fallbackColumns = null): array
	{
		$selects = parent::getDefaultSelect($mutation, $fallbackColumns);
		unset($selects['fk_watcher']);
		
		return $selects;
	}
	
	public function filterCategory($path, ICollection $collection): void
	{
		if ($path === false) {
			$collection->where('categories.uuid IS NULL');
			
			return;
		}
		
		$id = $this->getConnection()->findRepository(Category::class)->many()->where('path', $path)->firstValue('uuid');
		
		if (!$id) {
			$collection->where('1=0');
		} else {
			$subSelect = $this->getConnection()->rows(['eshop_product_nxn_eshop_category'], ['fk_product'])
				->join(['eshop_category'], 'eshop_category.uuid=eshop_product_nxn_eshop_category.fk_category')
				->where('eshop_category.path LIKE :path', ['path' => "$path%"]);
			$collection->where('this.fk_primaryCategory = :category OR this.uuid IN (' . $subSelect->getSql() . ')', ['category' => $id] + $subSelect->getVars());
		}
	}
	
	public function filterPriceFrom($value, ICollection $collection): void
	{
		$no = \count($this->shopperUser->getPricelists()->toArray());
		$expression = new Expression();
		
		for ($i = 0; $i !== $no; $i++) {
			$expression->add('OR', "prices$i.price >= :priceFrom");
		}
		
		$collection->where($expression->getSql(), ['priceFrom' => (float) $value]);
	}
	
	public function filterPriceTo($value, ICollection $collection): void
	{
		$no = \count($this->shopperUser->getPricelists()->toArray());
		$expression = new Expression();
		
		for ($i = 0; $i !== $no; $i++) {
			$expression->add('OR', "prices$i.price <= :priceTo");
		}
		
		$collection->where($expression->getSql(), ['priceTo' => (float) $value]);
	}
	
	public function filterPriceVatFrom($value, ICollection $collection): void
	{
		$no = \count($this->shopperUser->getPricelists()->toArray());
		$expression = new Expression();
		
		for ($i = 0; $i !== $no; $i++) {
			$expression->add('OR', "prices$i.priceVat >= :priceVatFrom");
		}
		
		$collection->where($expression->getSql(), ['priceVatFrom' => (float) $value]);
	}
	
	public function filterPriceVatTo($value, ICollection $collection): void
	{
		$no = \count($this->shopperUser->getPricelists()->toArray());
		$expression = new Expression();
		
		for ($i = 0; $i !== $no; $i++) {
			$expression->add('OR', "prices$i.priceVat <= :priceVatTo");
		}
		
		$collection->where($expression->getSql(), ['priceVatTo' => (float) $value]);
	}
	
	public function filterRibbon($value, ICollection $collection): void
	{
		$collection->join(['ribbons' => 'eshop_product_nxn_eshop_ribbon'], 'ribbons.fk_product=this.uuid');
		
		$value === false ? $collection->where('ribbons.fk_ribbon IS NULL') : $collection->where('ribbons.fk_ribbon', $value);
	}
	
	public function filterInternalRibbon($value, ICollection $collection): void
	{
		$collection->join(['internalRibbons' => 'eshop_product_nxn_eshop_internalribbon'], 'internalRibbons.fk_product=this.uuid');
		
		$value === false ? $collection->where('internalRibbons.fk_internalribbon IS NULL') : $collection->where('internalRibbons.fk_internalribbon', $value);
	}
	
	public function filterPricelist($value, ICollection $collection): void
	{
		$collection->join(['prices' => 'eshop_price'], 'prices.fk_product=this.uuid');
		
		$value === false ? $collection->where('prices.fk_pricelist IS NULL') : $collection->where('prices.fk_pricelist', $value);
	}
	
	public function filterProducer($value, ICollection $collection): void
	{
		$value === false ? $collection->where('this.fk_producer IS NULL') : $collection->where('this.fk_producer', $value);
	}
	
	public function filterProducers($value, ICollection $collection): void
	{
		$collection->where('this.fk_producer', $value);
	}
	
	public function filterHidden(?bool $hidden, ICollection $collection): void
	{
		if ($hidden !== null) {
			/** @var array<mixed> $joins */
			$joins = $collection->getModifiers()['JOIN'];

			$visibilityListItemJoined = false;

			foreach ($joins as $join) {
				if (Arrays::contains(\array_keys($join[1]), 'visibilityListItem')) {
					$visibilityListItemJoined = true;

					break;
				}
			}

			if (!$visibilityListItemJoined) {
				$visibilityLists = $this->shopperUser->getVisibilityLists();

				if (!$visibilityLists) {
					Debugger::barDump('No VisibilityLists');
				}

				$this->joinVisibilityListItemToProductCollection($collection, $visibilityLists);
			}

			$collection->where('visibilityListItem.hidden', $hidden ? '1' : '0');
		}
	}

	public function filterHiddenInMenu(?bool $hiddenInMenu, ICollection $collection): void
	{
		if ($hiddenInMenu !== null) {
			/** @var array<mixed> $joins */
			$joins = $collection->getModifiers()['JOIN'];

			$visibilityListItemJoined = false;

			foreach ($joins as $join) {
				if (Arrays::contains(\array_keys($join[1]), 'visibilityListItem')) {
					$visibilityListItemJoined = true;

					break;
				}
			}

			if (!$visibilityListItemJoined) {
				$visibilityLists = $this->shopperUser->getVisibilityLists();

				if (!$visibilityLists) {
					Debugger::barDump('No VisibilityLists');
				}

				$this->joinVisibilityListItemToProductCollection($collection, $visibilityLists);
			}

			$collection->where('visibilityListItem.hiddenInMenu', $hiddenInMenu ? '1' : '0');
		}
	}

	public function filterUnavailable(?bool $unavailable, ICollection $collection): void
	{
		if ($unavailable !== null) {
			/** @var array<mixed> $joins */
			$joins = $collection->getModifiers()['JOIN'];

			$visibilityListItemJoined = false;

			foreach ($joins as $join) {
				if (Arrays::contains(\array_keys($join[1]), 'visibilityListItem')) {
					$visibilityListItemJoined = true;

					break;
				}
			}

			if (!$visibilityListItemJoined) {
				$visibilityLists = $this->shopperUser->getVisibilityLists();

				if (!$visibilityLists) {
					Debugger::barDump('No VisibilityLists');
				}

				$this->joinVisibilityListItemToProductCollection($collection, $visibilityLists);
			}

			$collection->where('visibilityListItem.unavailable', $unavailable ? '1' : '0');
		}
	}

	public function filterRecommended(?bool $recommended, ICollection $collection): void
	{
		if ($recommended !== null) {
			/** @var array<mixed> $joins */
			$joins = $collection->getModifiers()['JOIN'];

			$visibilityListItemJoined = false;

			foreach ($joins as $join) {
				if (Arrays::contains(\array_keys($join[1]), 'visibilityListItem')) {
					$visibilityListItemJoined = true;

					break;
				}
			}

			if (!$visibilityListItemJoined) {
				$visibilityLists = $this->shopperUser->getVisibilityLists();

				if (!$visibilityLists) {
					Debugger::barDump('No VisibilityLists');
				}

				$this->joinVisibilityListItemToProductCollection($collection, $visibilityLists);
			}

			$collection->where('visibilityListItem.recommended', $recommended ? '1' : '0');
		}
	}
	
	public function filterRelated($values, ICollection $collection): void
	{
		$collection->whereNot('this.uuid', $values['uuid'])->where('this.fk_primaryCategory = :category', ['category' => $values['category']]);
	}
	
	public function filterAvailability($values, ICollection $collection): void
	{
		$collection->where('this.fk_displayAmount', $values);
	}

	public function filterUuids($values, ICollection $collection): void
	{
		$collection->where('this.uuid', $values);
	}
	
	public function filterDelivery($values, ICollection $collection): void
	{
		$collection->where('this.fk_displayDelivery', $values);
	}
	
	public function filterQ($value, ICollection $collection): ICollection
	{
		$langSuffix = $this->getConnection()->getMutationSuffix();
		
		$collection->select(
			[
				'rel0' => "MATCH(this.name$langSuffix) AGAINST (:q1)",
				'rel1' => "MATCH(this.name$langSuffix, this.perex$langSuffix, this.content$langSuffix) AGAINST (:q1)",
			],
			['q1' => $value],
		);
		
		$orConditions = [
			"IF(this.subCode, CONCAT(this.code,'.',this.subCode), this.code) LIKE :qlikeq",
			'this.externalCode LIKE :qlike',
			'this.ean LIKE :qlike',
			"this.name$langSuffix LIKE :qlike COLLATE utf8_general_ci",
			"this.name$langSuffix LIKE :qlikeq COLLATE utf8_general_ci",
			"MATCH(this.name$langSuffix) AGAINST (:q)",
			"MATCH(this.name$langSuffix, this.perex$langSuffix, this.content$langSuffix) AGAINST(:q)",
		];
		
		$collection->where(\implode(' OR ', $orConditions), ['q' => $value, 'qlike' => $value . '%', 'qlikeq' => '%' . $value . '%']);
		
		return $collection->orderBy([
			"this.name$langSuffix LIKE :qlike" => 'DESC',
			"this.name$langSuffix LIKE :qlikeq" => 'DESC',
			'rel0' => 'DESC',
			'this.code LIKE :qlike' => 'DESC',
			'this.ean LIKE :qlike' => 'DESC',
		]);
	}
	
	public function filterCrossSellFilter($value, ICollection $collection): void
	{
		[$path, $currentProduct] = $value;
		
		$collection->where('this.uuid != :currentProduct', ['currentProduct' => "$currentProduct"]);
		
		$sql = '';
		
		foreach (\str_split($path, 4) as $path) {
			$sql .= " categories.path LIKE '%$path' OR";
		}
		
		$collection->where(Strings::substring($sql, 0, -2));
	}
	
	public function filterInStock($value, ICollection $collection): void
	{
		if ($value) {
			$collection
				->join(['eshop_displayamount'], 'eshop_displayamount.uuid=this.fk_displayAmount')
				->where('fk_displayAmount IS NULL OR eshop_displayamount.isSold = 0');
		}
	}
	
	public function filterDisplayAmount($value, ICollection $collection): void
	{
		if ($value) {
			$collection->where('this.fk_displayAmount', $value);
		}
	}
	
	public function filterQuery($value, Collection $collection): void
	{
		$this->filterQ($value, $collection);
	}
	
	/**
	 * @deprecated
	 */
	public function filterRelatedSlave($value, ICollection $collection): void
	{
		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_slave');
		$collection->where('related.fk_type', $value[0]);
		$collection->where('related.fk_master', $value[1]);
	}
	
	/**
	 * @deprecated
	 */
	public function filterToners($value, ICollection $collection): void
	{
		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_master');
		$collection->where('related.fk_slave', $value);
		$collection->where('related.fk_type = "tonerForPrinter"');
	}
	
	/**
	 * @deprecated
	 */
	public function filterCompatiblePrinters($value, ICollection $collection): void
	{
		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_slave');
		$collection->where('related.fk_master', $value);
		$collection->where('related.fk_type = "tonerForPrinter"');
	}
	
	public function filterRelatedTypeMaster(array $value, ICollection $collection): void
	{
		if (!isset($value[0]) || !isset($value[1])) {
			Debugger::log('filterRelatedTypeMaster: missing values', ILogger::WARNING);

			return;
		}

		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_slave', [], 'LEFT');
		$collection->where('related.fk_master', $value[0]);
		$collection->where('related.fk_type', $value[1]);
	}
	
	public function filterRelatedTypeSlave(array $value, ICollection $collection): void
	{
		if (!isset($value[0]) || !isset($value[1])) {
			Debugger::log('filterRelatedTypeMaster: missing values', ILogger::WARNING);

			return;
		}

		$collection->join(['related' => 'eshop_related'], 'this.uuid = related.fk_master', [], 'LEFT');
		$collection->where('related.fk_slave', $value[0]);
		$collection->where('related.fk_type', $value[1]);
	}
	
	public function filterSimilarProducts($value, ICollection $collection): void
	{
		$collection->join(['relation' => 'eshop_related'], 'this.uuid=relation.fk_master OR this.uuid=relation.fk_slave')
			->join(['type' => 'eshop_relatedtype'], 'relation.fk_type=type.uuid')
			->where('type.similar', true)
			->where('this.uuid != :currentRelationProduct', ['currentRelationProduct' => $value]);
	}
	
	public function filterAttributeValue($value, ICollection $collection): void
	{
		$collection
			->join(['attributeAssign' => 'eshop_attributeassign'], 'this.uuid = attributeAssign.fk_product')
			->where('attributeAssign.fk_value', $value);
	}
	
	public function filterAttributes($attributes, ICollection $collection): void
	{
		//@TODO performance
		
		foreach ($attributes as $attributeKey => $selectedAttributeValues) {
			if (\count($selectedAttributeValues) === 0) {
				continue;
			}
			
			if (Arrays::contains(\array_keys(ProductFilter::SYSTEMIC_ATTRIBUTES), $attributeKey)) {
				$funcName = 'filter' . Strings::firstUpper($attributeKey);
				
				$this->$funcName($selectedAttributeValues, $collection);
				
				continue;
			}
			
			/** @var \Eshop\DB\Attribute $attribute */
			$attribute = $this->attributeRepository->one($attributeKey);

			if ($attribute->filterType === 'and') {
				foreach ($selectedAttributeValues as $attributeValue) {
					$subSelect = $this->getConnection()->rows(['eshop_attributevalue']);

					$subSelect->setBinderName("__var$attributeKey" . \str_replace('-', '_', Strings::webalize($attributeValue)));

					$subSelect->join(['eshop_attributeassign'], 'eshop_attributeassign.fk_value = eshop_attributevalue.uuid')
						->join(['eshop_attribute'], 'eshop_attribute.uuid = eshop_attributevalue.fk_attribute')
						->where('eshop_attributeassign.fk_product=this.uuid')
						->where('eshop_attributevalue.fk_attribute', $attributeKey)
						->where($attribute->showRange ? 'eshop_attributevalue.fk_attributevaluerange' : 'eshop_attributevalue.uuid', $attributeValue);

					$collection->where('EXISTS (' . $subSelect->getSql() . ')', $subSelect->getVars());
				}
			} else {
				if ($attribute->showRange) {
					$selectedAttributeValues = $this->getConnection()->rows(['eshop_attributevalue'])
						->where('eshop_attributevalue.fk_attributevaluerange', $selectedAttributeValues)
						->where('eshop_attributevalue.fk_attribute', $attribute->getPK())
						->toArrayOf('uuid');
				}

				$subSelect = $this->getConnection()->rows(['eshop_attributevalue']);

				$subSelect->setBinderName("__var$attributeKey");

				$subSelect->join(['eshop_attributeassign'], 'eshop_attributeassign.fk_value = eshop_attributevalue.uuid')
					->join(['eshop_attribute'], 'eshop_attribute.uuid = eshop_attributevalue.fk_attribute')
					->where('eshop_attributeassign.fk_product=this.uuid');

				$exp = new Expression();

				foreach ($selectedAttributeValues as $attributeValue) {
					$exp->add('OR', 'eshop_attributevalue.uuid = %s', [$attributeValue]);
				}

				$subSelect->where('eshop_attributevalue.fk_attribute = :attributeKey AND ' . $exp->getSql(), $exp->getVars() + ['attributeKey' => $attributeKey]);

				$collection->where('EXISTS (' . $subSelect->getSql() . ')', $subSelect->getVars());
			}
		}
	}
	
	/**
	 * @inheritDoc
	 */
	public function getArrayForSelect(bool $includeHidden = true): array
	{
		unset($includeHidden);
		
		$suffix = $this->getConnection()->getMutationSuffix();
		
		return $this->many()->orderBy(["name$suffix"])->toArrayOf('name');
	}
	
	/**
	 * @inheritDoc
	 */
	public function getAjaxArrayForSelect(bool $includeHidden = true, ?string $q = null, ?int $page = null): array
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		
		return $this->getCollection($includeHidden)
			->where("this.name$suffix LIKE :q OR this.code = :exact OR this.ean = :exact", ['q' => "%$q%", 'exact' => $q,])
			->setPage($page ?? 1, 5)
			->toArrayOf('name');
	}
	
	public function getProductByCodeOrEAN(string $expression): ?Product
	{
		return $this->many()->where('code = :q OR CONCAT(code,".",subCode) = :q OR ean = :q', ['q' => $expression])->first();
	}
	
	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();
		
		if (!$includeHidden) {
			$collection->where('this.hidden', false);
		}

		$this->joinVisibilityListItemToProductCollection($collection);
		
		return $collection->orderBy(['visibilityListItem.priority', "this.name$suffix"]);
	}

	/**
	 * @param \StORM\ICollection $collection
	 * @param array<\Eshop\DB\VisibilityList>|null $visibilityLists
	 */
	public function joinVisibilityListItemToProductCollection(ICollection $collection, array|null $visibilityLists = null): void
	{
		if (!$visibilityLists) {
			$visibilityLists = $this->shopperUser->getVisibilityLists();

			if (!$visibilityLists) {
				Debugger::barDump('No VisibilityLists');
			}
		}

		$visibilityLists = \implode(',', \array_map(function ($val) {
			return "'$val'";
		}, $visibilityLists));

		$collection->join(['visibilityListItem' => 'eshop_visibilitylistitem'], 'visibilityListItem.fk_product = this.uuid AND visibilityListItem.fk_visibilityList = (
				SELECT fk_visibilityList
					FROM eshop_visibilitylistitem
					JOIN eshop_visibilityList ON eshop_visibilityList.uuid = eshop_visibilitylistitem.fk_visibilityList
					WHERE fk_product = this.uuid AND ' . ($visibilityLists ? 'eshop_visibilityList.uuid IN (' . $visibilityLists . ')' : '1=0') . '
					ORDER BY eshop_visibilityList.priority ASC
					LIMIT 1
				)
			');
	}
	
	/**
	 * @param \Eshop\DB\Product|string $product
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getSimilarProductsByProduct(Product|string $product): ?Collection
	{
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return null;
			}
		}
		
		/** @var \Eshop\DB\RelatedRepository $relatedRepo */
		$relatedRepo = $this->getConnection()->findRepository(Related::class);
		
		return $relatedRepo->getCollection()
			->join(['type' => 'eshop_relatedtype'], 'this.fk_type=type.uuid')
			->where('fk_master = :q OR fk_slave = :q', ['q' => $product->getPK()])
			->where('type.similar', true)
			->where('this.uuid != :currentRelationProduct', ['currentRelationProduct' => $product->getPK()]);
	}
	
	/**
	 * @param \Eshop\DB\Product|string $product
	 * @param bool $showAll
	 * @return array<array<string, array<\Eshop\DB\AttributeValue>|\StORM\Entity>>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getActiveProductAttributes(Product|string $product, bool $showAll = false, bool $showOnlyRecommendedAttributes = false): array
	{
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return [];
			}
		}
		
		/** @var \Eshop\DB\AttributeRepository $attributeRepository */
		$attributeRepository = $this->getConnection()->findRepository(Attribute::class);
		
		/** @var \Eshop\DB\AttributeValueRepository $attributeValueRepository */
		$attributeValueRepository = $this->getConnection()->findRepository(AttributeValue::class);
		
		$productCategory = $product->getPrimaryCategory();
		
		if (!$productCategory) {
			return [];
		}
		
		$attributes = $attributeRepository->getAttributesByCategory($productCategory->path, $showAll);

		if ($showOnlyRecommendedAttributes) {
			$attributes->where('this.recommended', true);
		}

		$attributes = $attributes->toArray();

		$attributesList = [];
		
		foreach ($attributes as $attributeKey => $attribute) {
			$attributeArray = ['attribute' => $attribute];
			
			$collection = $attributeValueRepository->many()
				->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_value')
				->join(['attribute' => 'eshop_attribute'], 'attribute.uuid = this.fk_attribute')
				->where('this.fk_attribute', $attributeKey)
				->where('assign.fk_product', $product->getPK());
			
			if (!$showAll) {
				$collection->where('attribute.showProduct', true);
			}
			
			/** @var array<\Eshop\DB\AttributeValue> $attributeValues */
			$attributeValues = $collection->toArray();
			
			$attributeArray['values'] = $attributeValues;
			
			if (\count($attributeArray['values']) === 0) {
				continue;
			}
			
			foreach ($attributeArray['values'] as $attributeValueKey => $attributeValue) {
				$attributeArray['values'][$attributeValueKey]->setValue('page', $this->pageRepository->getPageByTypeAndParams('product_list', null, ['attributeValue' => $attributeValue->getPK()]));
			}
			
			$attributesList[$attributeKey] = $attributeArray;
		}
		
		return $attributesList;
	}
	
	public function isProductInCategory(Product|string $product, Category|string $category): bool
	{
		/** @var \Eshop\DB\CategoryRepository $categoryRepo */
		$categoryRepo = $this->getConnection()->findRepository(Category::class);
		
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return false;
			}
		}
		
		if (!$category instanceof Category) {
			if (!$category = $categoryRepo->one($category)) {
				return false;
			}
		}
		
		if (!$primaryCategory = $product->getPrimaryCategory($category->type)) {
			return false;
		}
		
		return $categoryRepo->getRootCategoryOfCategory($primaryCategory)->getPK() === $category->getPK();
	}
	
	public function getSlaveProductsCountByRelationAndMaster($relation, $product, bool $onlyVisible = false): int
	{
		$result = $onlyVisible ? $this->getSlaveProductsByRelationAndMasterVisible($relation, $product) : $this->getSlaveProductsByRelationAndMaster($relation, $product);
		
		return $result ? $result->enum() : 0;
	}
	
	/**
	 * @param string|\Eshop\DB\RelatedType $relatedType
	 * @param string|\Eshop\DB\Product $product
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getSlaveProductsByRelationAndMaster($relatedType, $product): ?ICollection
	{
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return null;
			}
		}
		
		if (!$relatedType instanceof RelatedType) {
			if (!$relatedType = $this->relatedTypeRepository->one($relatedType)) {
				return null;
			}
		}

		return $this->many()->join(['related' => 'eshop_related'], 'this.uuid = related.fk_slave')
			->where('related.hidden', false)
			->where('related.fk_master', $product->getPK())
			->where('related.fk_type', $relatedType->getPK())
			->orderBy(['related.priority']);
	}
	
	/**
	 * @param string|\Eshop\DB\RelatedType $relatedType
	 * @param string|\Eshop\DB\Product $product
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getSlaveProductsByRelationAndMasterVisible($relatedType, $product): ?ICollection
	{
		$collection = $this->getSlaveProductsByRelationAndMaster($relatedType, $product);
		
		return $collection ? $this->getSlaveProductsByRelationAndMaster($relatedType, $product)
			->where('this.hidden', 0)
			->where('related.hidden', 0)->orderBy(['this.priority']) : null;
	}
	
	/**
	 * @param string|\Eshop\DB\RelatedType $relatedType
	 * @param string|\Eshop\DB\Product $product
	 * @return \StORM\Collection<\Eshop\DB\Related>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getMasterRelatedProducts($relatedType, $product): Collection
	{
		return $this->getRelatedProducts($relatedType, $product, 'slave');
	}
	
	/**
	 * @param string|\Eshop\DB\RelatedType $relatedType
	 * @param string|\Eshop\DB\Product $product
	 * @return \StORM\Collection<\Eshop\DB\Related>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getSlaveRelatedProducts($relatedType, $product): Collection
	{
		return $this->getRelatedProducts($relatedType, $product, 'master');
	}
	
	/**
	 * @param string|\Eshop\DB\RelatedType $relatedType
	 * @param string|\Eshop\DB\Product $product
	 * @param string $relatedSide
	 * @return \StORM\Collection<\Eshop\DB\Related>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getRelatedProducts($relatedType, $product, string $relatedSide): Collection
	{
		$validRelatedTypes = ['master', 'slave'];
		
		if (!Arrays::contains($validRelatedTypes, $relatedSide)) {
			throw new \InvalidArgumentException('Invalid relation side! Valid values: [' . \implode(',', $validRelatedTypes) . ']');
		}
		
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				throw new \InvalidArgumentException('Product not found!');
			}
		}
		
		if (!$relatedType instanceof RelatedType) {
			if (!$relatedType = $this->relatedTypeRepository->one($relatedType)) {
				throw new \InvalidArgumentException('RelatedType not found!');
			}
		}
		
		return $this->relatedRepository->many()
			->where('this.hidden', false)
			->where("this.fk_$relatedSide", $product->getPK())
			->where('this.fk_type', $relatedType->getPK())
			->orderBy(['this.priority']);
	}
	
	/**
	 * @param string|\Eshop\DB\RelatedType $relatedType
	 * @param string|\Eshop\DB\Product $product
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getMasterProductsByRelationAndSlave($relatedType, $product): ?ICollection
	{
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return null;
			}
		}
		
		if (!$relatedType instanceof RelatedType) {
			if (!$relatedType = $this->relatedTypeRepository->one($relatedType)) {
				return null;
			}
		}
		
		return $this->many()->join(['related' => 'eshop_related'], 'this.uuid = related.fk_master')
			->where('related.hidden', false)
			->where('related.fk_slave', $product->getPK())
			->where('related.fk_type', $relatedType->getPK())
			->orderBy(['related.priority']);
	}
	
	/**
	 * @param \Eshop\DB\Product|string|null $product
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function get($product): ?Product
	{
		if (!$product) {
			return null;
		}
		
		if (!$product instanceof Product) {
			if (!$product = $this->one($product)) {
				return null;
			}
		}
		
		return $product;
	}
	
	/**
	 * @param array<\Eshop\DB\CartItem> $cartItems
	 * @return array<string, array<string, object>>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getCartItemsRelations(array $cartItems, bool $useCombinedName = true, bool $onlyShowCart = true, ?RelatedType $relatedType = null): array
	{
		$upsells = [];
		
		foreach ($cartItems as $cartItem) {
			$upsells[$cartItem->getPK()] = $this->getCartItemRelations($cartItem, $useCombinedName, $onlyShowCart, $relatedType);
		}
		
		return $upsells;
	}
	
	/**
	 * @param \Eshop\DB\CartItem $cartItem
	 * @param bool $useCombinedName
	 * @return array<string, \Eshop\DB\Product>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getCartItemRelations(CartItem $cartItem, bool $useCombinedName = true, bool $onlyShowCart = true, ?RelatedType $relatedType = null): array
	{
		if (!$cartItem->getValue('product')) {
			return [];
		}
		
		$itemRelationsForCart = [];
		
		$collection = $this->relatedRepository->getCollection()
			->join(['relatedType' => 'eshop_relatedtype'], 'this.fk_type = relatedType.uuid')
			->where('this.fk_master', $cartItem->getValue('product'))
			->whereNot('this.fk_slave', $cartItem->getValue('product'))
			->orderBy(['this.priority']);
		
		if ($onlyShowCart) {
			$collection->where('relatedType.showCart', true);
		}
		
		if ($relatedType) {
			$collection->where('relatedType.uuid', $relatedType->getPK());
		}
		
		/** @var \Eshop\DB\Related $related */
		foreach ($collection as $related) {
			if (isset($itemRelationsForCart[$related->getValue('slave')])) {
				continue;
			}
			
			/** @var \Eshop\DB\Product|\stdClass|null $slaveProduct */
			$slaveProduct = $this->getProducts()
				->where('this.uuid', $related->getValue('slave'))
				->first();
			
			if (!$slaveProduct) {
				continue;
			}
			
			$slaveProduct->shortName = $slaveProduct->name;
			$slaveProduct->name = $useCombinedName ? $cartItem->productName . ' - ' . $slaveProduct->name : $slaveProduct->name;
			$slaveProduct->amount = $cartItem->amount * $related->amount;
			
			if ($related->masterPct !== null) {
				$slaveProduct->price = $cartItem->price * $related->masterPct / 100;
				$slaveProduct->priceVat = $cartItem->priceVat * $related->masterPct / 100;
			} elseif ($related->discountPct !== null) {
				$slaveProduct->price = $slaveProduct->getPrice() - ($slaveProduct->getPrice() * $related->discountPct / 100);
				$slaveProduct->priceVat = $slaveProduct->getPriceVat() - ($slaveProduct->getPriceVat() * $related->discountPct / 100);
			}
			
			$itemRelationsForCart[$slaveProduct->getPK()] = $slaveProduct;
		}
		
		return $itemRelationsForCart;
	}
	
	public function getRecyclingFeeBySuppliersPriority(Product $product): ?float
	{
		$mergedProducts = $product->getAllMergedProducts();

		$tempMasterProduct = $product->masterProduct;

		while ($tempMasterProduct) {
			$mergedProducts[$tempMasterProduct->getPK()] = $tempMasterProduct;

			$tempMasterProduct = $tempMasterProduct->masterProduct;
		}
		
		$fee = $this->supplierProductRepository->many()
			->join(['supplier' => 'eshop_supplier'], 'this.fk_supplier = supplier.uuid')
			->where('this.fk_product', \array_keys($mergedProducts))
			->where('this.recyclingFee IS NOT NULL')
			->orderBy(['supplier.importPriority'])
			->firstValue('recyclingFee');
		
		return $fee ? (float) $fee : null;
	}
	
	public function getCopyrightFeeBySuppliersPriority(Product $product): ?float
	{
		$mergedProducts = $product->getAllMergedProducts();

		$tempMasterProduct = $product->masterProduct;

		while ($tempMasterProduct) {
			$mergedProducts[$tempMasterProduct->getPK()] = $tempMasterProduct;

			$tempMasterProduct = $tempMasterProduct->masterProduct;
		}
		
		$fee = $this->supplierProductRepository->many()
			->join(['supplier' => 'eshop_supplier'], 'this.fk_supplier = supplier.uuid')
			->where('this.fk_product', \array_keys($mergedProducts))
			->where('this.copyrightFee IS NOT NULL')
			->orderBy(['supplier.importPriority'])
			->firstValue('copyrightFee');
		
		return $fee ? (float) $fee : null;
	}

	/**
	 * @return array<mixed>
	 */
	public function getGroupedMergedProducts(?Collection $products = null): array
	{
		$allProducts = $this->many()->select(['fkMasterProduct' => 'this.fk_masterProduct'])->fetchArray(\stdClass::class);
		$products = ($products ?: $this->many())->setSelect(['this.uuid'], [], true)->fetchArray(\stdClass::class);

		$productsMap = [];
		$result = [];

		foreach ($allProducts as $productPK => $masterProduct) {
			if (!$masterProduct->fkMasterProduct) {
				continue;
			}

			$productsMap[$masterProduct->fkMasterProduct][] = (string) $productPK;
		}

		foreach (\array_keys($products) as $productPK) {
			$result[$productPK] = $this->doGetGroupedMergedProducts($productsMap, (string) $productPK);
		}

		return $result;
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\Product> $products
	 * @param \League\Csv\Writer $writer
	 * @param array $columns
	 * @param array $attributes
	 * @param string $delimiter
	 * @param array|null $header
	 * @param array $supplierCodes
	 * @param callable|null $getSupplierCode
	 * @throws \League\Csv\CannotInsertRecord
	 * @throws \League\Csv\Exception
	 * @throws \League\Csv\InvalidArgument
	 * @throws \Nette\Application\UI\InvalidLinkException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function csvExport(
		Collection $products,
		Writer $writer,
		array $columns = [],
		array $attributes = [],
		string $delimiter = ';',
		?array $header = null,
		array $supplierCodes = [],
		?callable $getSupplierCode = null
	): void {
		$writer->setDelimiter($delimiter);
		
		EncloseField::addTo($writer, "\t\22");

		$completeHeaders = \array_merge($header, $supplierCodes);

		if ($completeHeaders) {
			$writer->insertOne($completeHeaders);
		}
		
		$mutationSuffix = $this->getConnection()->getMutationSuffix();
		$availableShops = $this->shopsConfig->getAvailableShops();
		$selectedShop = $this->shopsConfig->getSelectedShop();

		$products->setGroupBy(['this.uuid'])
			->join(['priceTable' => 'eshop_price'], 'this.uuid = priceTable.fk_product')
			->select([
				'priceMin' => 'MIN(priceTable.price)',
				'priceMax' => 'MAX(priceTable.price)',
			])
//			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_product')
//			->join(['attributeValue' => 'eshop_attributevalue'], 'assign.fk_value = attributeValue.uuid')
			->join(['producer' => 'eshop_producer'], 'this.fk_producer = producer.uuid')
			->join(['storeAmount' => 'eshop_amount'], 'storeAmount.fk_product = this.uuid')
			->join(['store' => 'eshop_store'], 'storeAmount.fk_store = store.uuid')
			->join(['categoryAssign' => 'eshop_product_nxn_eshop_category'], 'this.uuid = categoryAssign.fk_product')
			->join(['category' => 'eshop_category'], 'categoryAssign.fk_category = category.uuid')
			->join(['masterProduct' => 'eshop_product'], 'this.fk_masterProduct = masterProduct.uuid')
			->join(['productContent' => 'eshop_productcontent'], 'this.uuid = productContent.fk_product')
			->select([
//				'attributes' => "GROUP_CONCAT(DISTINCT CONCAT(attributeValue.fk_attribute, '^', CONCAT(COALESCE(attributeValue.label$mutationSuffix), '°', attributeValue.code)) SEPARATOR \"~\")",
				'producerCodeName' => "CONCAT(COALESCE(producer.name$mutationSuffix, ''), '#', COALESCE(producer.code, ''))",
				'amounts' => "GROUP_CONCAT(DISTINCT CONCAT(storeAmount.inStock, '#', store.code) SEPARATOR ':')",
				'groupedCategories' => "GROUP_CONCAT(DISTINCT CONCAT(category.name$mutationSuffix, '#',
                IF(category.code IS NULL OR category.code = '', category.uuid, category.code)) ORDER BY LENGTH(category.path) SEPARATOR ':')",
				'masterProductCode' => 'masterProduct.code',
			]);

		if ($selectedShop) {
			$products->where('productContent.fk_shop', $selectedShop->getPK());
		}

		$attributesByProductPK = [];

		foreach ($this->attributeAssignRepository->many()
					 ->join(['attributeValue' => 'eshop_attributevalue'], 'this.fk_value = attributeValue.uuid')
					 ->select([
						 'fk_attribute' => 'attributeValue.fk_attribute',
						 'label' => "attributeValue.label$mutationSuffix",
						 'code' => 'attributeValue.code',
					 ])
					 ->fetchArray(\stdClass::class) as $item) {
			// phpcs:ignore
			$attributesByProductPK[$item->fk_product][$item->fk_attribute][] = $item;
		}

		$supplierProductsArray = $this->supplierProductRepository->many()
			->join(['supplier' => 'eshop_supplier'], 'this.fk_supplier = supplier.uuid')
			->setSelect([
				'fkProduct' => 'this.fk_product',
				'supplier.importPriority',
				'this.recyclingFee',
			], [], true)
			->orderBy(['this.fk_product', 'supplier.importPriority'])
			->fetchArray(\stdClass::class);

		$supplierProductsArrayGroupedByProductPK = [];

		foreach ($supplierProductsArray as $supplierProduct) {
			$supplierProductsArrayGroupedByProductPK[$supplierProduct->fkProduct][] = $supplierProduct;
		}

		unset($supplierProductsArray);

		$mergedProductsMap = $this->getGroupedMergedProducts();
		$productsXCode = $this->many()->setSelect(['this.code'], [], true)->toArrayOf('code');
		$lazyLoadedProducts = [];

		while ($product = $products->fetch()) {
			/** @var \Eshop\DB\Product|\stdClass $product */
			$row = [];
			
			foreach (\array_keys($columns) as $columnKey) {
				if ($columnKey === 'producer') {
					$row[] = $product->producerCodeName;
				} elseif ($columnKey === 'storeAmount') {
					$row[] = $product->amounts;
				} elseif ($columnKey === 'categories') {
					$row[] = $product->groupedCategories;
				} elseif ($columnKey === 'adminUrl') {
					$row[] = $this->linkGenerator->link('Eshop:Admin:Product:edit', [$product]);
				} elseif ($columnKey === 'frontUrl') {
					$page = $this->pageRepository->getPageByTypeAndParams('product_detail', null, ['product' => $product->getPK()]);
					$row[] = $page ? $this->request->getUrl()->getBaseUrl() . $page->getUrl($this->getConnection()->getMutation()) : null;
				} elseif ($columnKey === 'mergedProducts') {
					$codes = [];

					foreach ($mergedProductsMap[$product->getPK()] ?? [] as $mergedProduct) {
						$codes[] = $productsXCode[$mergedProduct] ?? null;
					}

					$row[] = \implode(':', $codes);
				} elseif ($columnKey === 'masterProduct') {
					$row[] = $product->getValue('masterProductCode');
				} elseif ($columnKey === 'recyclingFee') {
					$allMergedProducts = \array_merge([$product], ($mergedProductsMap[$product->getPK()] ?? []));

					$minSupplierPriority = \PHP_INT_MAX;
					$recyclingFee = null;

					foreach ($allMergedProducts as $allMergedProduct) {
						if (\is_string($allMergedProduct)) {
							if (!isset($lazyLoadedProducts[$allMergedProduct])) {
								$lazyLoadedProducts[$allMergedProduct] = $this->one($allMergedProduct, true);
							}

							$allMergedProduct = $lazyLoadedProducts[$allMergedProduct];
						}

						foreach ($supplierProductsArrayGroupedByProductPK[$allMergedProduct->getPK()] ?? [] as $item) {
							$importPriority = $item->importPriority ?: 0;

							if ($importPriority >= $minSupplierPriority && $recyclingFee !== null) {
								continue;
							}

							$minSupplierPriority = $importPriority;
							$recyclingFee = $item->recyclingFee;
						}
					}

					$row[] = $recyclingFee;
				} else {
					$row[] = $product->getValue($columnKey) === false ? '0' : $product->getValue($columnKey);
				}
			}

			foreach (\array_keys($attributes) as $attributePK) {
				if (!isset($attributesByProductPK[$product->getPK()][$attributePK])) {
					$row[] = null;

					continue;
				}

				$tmp = '';

				foreach ($attributesByProductPK[$product->getPK()][$attributePK] as $attributeAssignObject) {
					$tmp .= "$attributeAssignObject->label#$attributeAssignObject->code:";
				}

				$row[] = Strings::substring($tmp, 0, -1);
			}

			foreach ($supplierCodes as $supplierCode) {
				$row[] = $getSupplierCode ? $getSupplierCode($product, $supplierCode) : $this->getSupplierCode($product, $supplierCode);
			}
			
			$writer->insertOne($row);
		}

		$products->__destruct();
	}
	
	public function isProductDeliveryFreeVat(Product $product, Currency $currency): bool
	{
		return $this->isProductDeliveryFree($product, true, $currency);
	}
	
	public function isProductDeliveryFreeWithoutVat(Product $product, Currency $currency): bool
	{
		return $this->isProductDeliveryFree($product, false, $currency);
	}
	
	public function clearCache(): void
	{
		$this->cache->clean([
			Cache::Tags => ['products'],
		]);
	}

	public function getSupplierCode(Product $product, string $supplierCode): ?string
	{
		$supplierProduct = $product->getSupplierProduct($supplierCode);

		return $supplierProduct ? $supplierProduct->code : null;
	}
	
	/**
	 * @param \StORM\Collection<\Eshop\DB\SupplierProduct> $supplierProducts
	 * @param \Eshop\DB\Category $category
	 * @param \Eshop\DB\Supplier $supplier
	 * @return array<\Eshop\DB\Product>
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function createDummyProducts(Collection $supplierProducts, Category $category, Supplier $supplier): array
	{
		$products = [];
		$mutation = Arrays::first(\array_keys($this->getConnection()->getAvailableMutations()));
		
		/** @var \Eshop\DB\SupplierProduct $supplierProduct */
		foreach ($supplierProducts->where('this.fk_product IS NULL') as $supplierProduct) {
			$product = $this->createOne([
				'ean' => $supplierProduct->ean ?: null,
				'mpn' => $supplierProduct->mpn ?: null,
				'code' => $supplierProduct->code,
				'subCode' => $supplierProduct->productSubCode,
				'supplierCode' => $supplierProduct->code,
				'name' => [$mutation => $supplierProduct->name],
				'content' => [$mutation => $supplierProduct->content],
				'unit' => $supplierProduct->unit,
				'unavailable' => $supplierProduct->unavailable,
				'hidden' => $supplier->defaultHiddenProduct,
				'storageDate' => $supplierProduct->storageDate,
				'defaultBuyCount' => $supplierProduct->defaultBuyCount,
				'minBuyCount' => $supplierProduct->minBuyCount,
				'buyStep' => $supplierProduct->buyStep,
				'inPackage' => $supplierProduct->inPackage,
				'inCarton' => $supplierProduct->inCarton,
				'inPalett' => $supplierProduct->inPalett,
				'weight' => $supplierProduct->weight,
				'primaryCategory' => $category->getPK(),
				'supplierLock' => $supplier->importPriority,
				'supplierSource' => $supplier,
				'categories' => [$category->getPK(),],
			]);
			
			Arrays::invoke($this->onDummyProductCreated, $product, $supplierProduct);
			
			$products[$product->getPK()] = $product;
			
			$supplierProduct->update(['product' => $product->getPK()]);
		}
		
		return $products;
	}
	
	/**
	 * @param \Eshop\DB\Product $product
	 * @return array<mixed>
	 */
	public function getPreviewAttributesByProduct(Product $product): array
	{
		$attributeValues = $this->attributeValueRepository->getCollection()
			->join(['attributeAssign' => 'eshop_attributeassign'], 'this.uuid = attributeAssign.fk_value', [], 'INNER')
			->join(['attribute' => 'eshop_attribute'], 'this.fk_attribute = attribute.uuid', [], 'INNER')
			->where('attribute.showProduct=1')
			->setOrderBy(['attribute.priority' => 'ASC', 'this.priority' => 'ASC'])
			->where('attributeAssign.fk_product', $product->getPK());
		
		$attributes = [
			'attributes' => [],
			'values' => [],
		];
		
		foreach ($attributeValues as $attributeValue) {
			$attribute = $attributeValue->attribute;
			
			if (!isset($attributes['attributes'][$attribute->getPK()])) {
				$attributes['attributes'][$attribute->getPK()] = $attribute;
			}
			
			if (!isset($attributes['values'][$attribute->getPK()])) {
				$attributes['values'][$attribute->getPK()] = [];
			}
			
			if (isset($attributes['values'][$attribute->getPK()][$attributeValue->getPK()])) {
				continue;
			}
			
			$attributes['values'][$attribute->getPK()][$attributeValue->getPK()] = $attributeValue;
		}
		
		return $attributes;
	}

	/**
	 * Return all descendants recursively and direct ancestors
	 * Indexed by depth
	 * @param \Eshop\DB\Product $product
	 * @return array<mixed>
	 */
	public function getProductFullTree(Product $product): array
	{
		$downTree = $this->getProductTree($product);

		$upTree = [];

		while ($masterProduct = $product->masterProduct) {
			$upTree[] = [$masterProduct->getPK() => $masterProduct];

			$product = $masterProduct;
		}

		return \array_merge(\array_reverse($upTree), $downTree);
	}

	/**
	 * @param \Eshop\DB\Product $product
	 * @return array<mixed>
	 */
	public function getProductTree(Product $product): array
	{
		$result = [];

		$this->doGetProductTree($product, $result);

		return $result;
	}

	/**
	 * @deprecated Use DIConnection::generateUuid()
	 */
	public static function generateUuid(?string $ean, ?string $fullCode): string
	{
		$namespace = 'product';

		if ($ean) {
			return DIConnection::generateUuid($namespace, $ean);
		}

		if ($fullCode) {
			return DIConnection::generateUuid($namespace, $fullCode);
		}

		throw new InvalidArgumentException('There is no unique parameter');
	}
	
	protected function getValidPricelists(Collection $collection): Collection
	{
		return $collection
			->where('this.isActive', true)
			->where('(discount.validFrom IS NULL OR discount.validFrom <= DATE(now())) AND (discount.validTo IS NULL OR discount.validTo >= DATE(now()))')
			->where('this.fk_currency', $this->shopperUser->getCurrency()->getPK())
			->where('this.fk_country', $this->shopperUser->getCountry()->getPK());
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\VisibilityList> $collection
	 * @return \StORM\Collection<\Eshop\DB\VisibilityList>
	 */
	protected function getValidVisibilityLists(Collection $collection): Collection
	{
		return $collection->where('this.hidden', false);
	}
	
	/**
	 * @param array $products
	 * @param string $product
	 * @return array<mixed>
	 */
	private function doGetGroupedMergedProducts(array $products, string $product): array
	{
		$descendants = $products[$product] ?? [];
		$realDescendants = [];

		foreach ($descendants as $descendant) {
			$realDescendants[] = $descendant;

			$realDescendants = \array_merge($realDescendants, $this->doGetGroupedMergedProducts($products, $descendant));
		}

		return $realDescendants;
	}

	/**
	 * @param \Eshop\DB\Product $product
	 * @param array<mixed> $result
	 * @param int $depth
	 */
	private function doGetProductTree(Product $product, array &$result, int $depth = 0): void
	{
		$result[$depth][$product->getPK()] = $product;

		foreach ($product->slaveProducts as $mergedProduct) {
			$this->doGetProductTree($mergedProduct, $result, $depth + 1);
		}
	}
	
	private function sqlHandlePrice(string $alias, string $priceExp, ?int $levelDiscountPct, int $maxDiscountPct, array $generalPricelistIds, int $prec, ?float $rate): string
	{
		$expression = $rate === null ? "$alias.$priceExp" : "ROUND($alias.$priceExp * $rate,$prec)";
		
		$levelDiscountPct ??= 0;
		
		if ($generalPricelistIds) {
			$pricelists = \implode(',', \array_map(function ($value) {
				return "'$value'";
			}, $generalPricelistIds));
			
			$expression = "IF($alias.fk_pricelist IN ($pricelists),
			ROUND($expression * ((100 - IF(LEAST(this.discountLevelPct, $maxDiscountPct) > $levelDiscountPct,LEAST(this.discountLevelPct, $maxDiscountPct),$levelDiscountPct)) / 100),$prec),
			$expression)";
		}
		
		return $expression;
	}
	
	private function sqlExplode(string $expression, string $delimiter, int $position): string
	{
		return "REPLACE(SUBSTRING(SUBSTRING_INDEX($expression, '$delimiter', $position),
       LENGTH(SUBSTRING_INDEX($expression, '$delimiter', " . ($position - 1) . ")) + 1), '$delimiter', '')";
	}
	
	private function isProductDeliveryFree(Product $product, bool $vat, Currency $currency): bool
	{
		/** @var \Eshop\DB\DeliveryDiscount $deliveryDiscount */
		foreach ($this->deliveryDiscountRepository->many()->where('this.fk_currency', $currency->getPK()) as $deliveryDiscount) {
			if ($deliveryDiscount->discount->isActive() === false ||
				$deliveryDiscount->discountPriceFrom > ($vat ? $product->getValue('priceVat') : $product->getValue('price')) ||
				(\abs($deliveryDiscount->discountPct - 100) >= \PHP_FLOAT_EPSILON)) {
				continue;
			}
			
			return true;
		}
		
		return false;
	}
}
