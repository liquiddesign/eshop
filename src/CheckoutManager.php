<?php

namespace Eshop;

use Base\ShopsConfig;
use Carbon\Carbon;
use Eshop\Admin\SettingsPresenter;
use Eshop\Common\CheckInvalidAmount;
use Eshop\Common\IncorrectItemReason;
use Eshop\DB\Address;
use Eshop\DB\AddressRepository;
use Eshop\DB\Attribute;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\BannedEmailRepository;
use Eshop\DB\Cart;
use Eshop\DB\CartItem;
use Eshop\DB\CartItemRepository;
use Eshop\DB\CartItemTaxRepository;
use Eshop\DB\CartRepository;
use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\Currency;
use Eshop\DB\Customer;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DeliveryDiscount;
use Eshop\DB\DeliveryDiscountRepository;
use Eshop\DB\DeliveryRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\DiscountCoupon;
use Eshop\DB\DiscountCouponRepository;
use Eshop\DB\LoyaltyProgramHistoryRepository;
use Eshop\DB\NewsletterUserRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderLogItem;
use Eshop\DB\OrderLogItemRepository;
use Eshop\DB\OrderRepository;
use Eshop\DB\PackageItemRepository;
use Eshop\DB\PackageRepository;
use Eshop\DB\PaymentRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\PriceRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\Purchase;
use Eshop\DB\PurchaseRepository;
use Eshop\DB\RelatedCartItemRepository;
use Eshop\DB\RelatedPackageItemRepository;
use Eshop\DB\RelatedTypeRepository;
use Eshop\DB\ReviewRepository;
use Eshop\DB\TaxRepository;
use Eshop\DB\Variant;
use Eshop\DB\VatRate;
use Eshop\Integration\Integrations;
use Nette;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\SmartObject;
use Nette\Utils\Arrays;
use Security\DB\AccountRepository;
use StORM\Collection;
use StORM\Connection;
use StORM\DIConnection;
use StORM\Exception\NotFoundException;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

/**
 * Služba která zapouzdřuje nákupní proces
 * @package Eshop
 */
class CheckoutManager
{
	use SmartObject;
	
	public const DEFAULT_CART_ID = '1';
	public const ACTIVE_CART_ID = null;
	
	public const DEFAULT_MIN_BUY_COUNT = 1;
	public const DEFAULT_MAX_BUY_COUNT = 999999999;
	
	/**
	 * @var array<callable(\Eshop\DB\Customer): void> Occurs after customer create
	 */
	public array $onCustomerCreate = [];

	/**
	 * @var array<callable(\Eshop\DB\Purchase): void> Occurs after customer create
	 */
	public array $onOrderCustomerProcessed = [];
	
	/**
	 * @var array<callable(\Eshop\DB\Order): void> Occurs after order create
	 */
	public array $onOrderCreate = [];
	
	/**
	 * @var array<callable(\Eshop\DB\Cart $cart): void> Occurs after cart create
	 */
	public array $onCartCreate = [];
	
	/**
	 * @var array<callable(): void> Occurs after cart item delete
	 */
	public array $onCartItemDelete = [];
	
	/**
	 * @var array<callable(\Eshop\DB\CartItem): void> Occurs after cart item create
	 */
	public array $onCartItemCreate = [];
	
	/**
	 * @var array<callable(): void> Occurs after cart item updated
	 */
	public array $onCartItemUpdate = [];
	
	/**
	 * @var array<mixed>|null
	 */
	public ?array $orderCodeArguments = null;
	
	protected Collection $paymentTypes;
	
	protected ?Customer $customer = null;
	
	/**
	 * @var array<string, float>
	 */
	protected array $sumPrice = [];
	
	/**
	 * @var array<string, float>
	 */
	protected array $sumPriceVat = [];
	
	/**
	 * @var array<string, float>
	 */
	protected array $sumPriceBefore = [];
	
	/**
	 * @var array<string, float>
	 */
	protected array $sumPriceVatBefore = [];
	
	/**
	 * @var array<string, int>
	 */
	protected array $sumAmount = [];
	
	/**
	 * @var array<string, int>
	 */
	protected array $sumAmountTotal = [];
	
	/**
	 * @var array<string, float>
	 */
	protected array $sumWeight = [];
	
	/**
	 * @var array<string, float>
	 */
	protected array $sumDimension = [];
	
	/**
	 * @var array<string, int>
	 */
	protected array $sumPoints = [];
	
	/**
	 * @var array<string, float>
	 */
	protected array $maxWeight = [];
	
	/**
	 * @var array<string, int>
	 */
	protected array $maxDimension = [];
	
	/**
	 * @var bool|null Cached status of discount coupon validity during request
	 */
	protected ?bool $isDiscountCouponValid = null;
	
	/**
	 * @var array<string>
	 */
	protected array $checkoutSequence;
	
	protected ?string $cartToken;
	
	protected ?string $lastOrderToken;
	
	/**
	 * @var array<\Eshop\DB\Cart>|array<null>
	 */
	protected array $unattachedCarts = [];
	
	/**
	 * @var array<\Eshop\DB\Cart>|array<null>
	 */
	protected array $carts = [];
	
	protected int $cartExpiration = 30;
	
	public function __construct(
		protected readonly ShopperUser $shopperUser,
		protected readonly CartRepository $cartRepository,
		protected readonly CartItemRepository $cartItemRepository,
		protected readonly ProductRepository $productRepository,
		protected readonly DeliveryDiscountRepository $deliveryDiscountRepository,
		protected readonly PaymentTypeRepository $paymentTypeRepository,
		protected readonly PaymentRepository $paymentRepository,
		protected readonly DeliveryTypeRepository $deliveryTypeRepository,
		protected readonly DeliveryRepository $deliveryRepository,
		protected readonly OrderRepository $orderRepository,
		protected readonly AccountRepository $accountRepository,
		protected readonly CustomerRepository $customerRepository,
		protected readonly DiscountCouponRepository $discountCouponRepository,
		protected readonly Response $response,
		protected readonly TaxRepository $taxRepository,
		protected readonly CartItemTaxRepository $cartItemTaxRepository,
		protected readonly PackageRepository $packageRepository,
		protected readonly PackageItemRepository $packageItemRepository,
		protected readonly LoyaltyProgramHistoryRepository $loyaltyProgramHistoryRepository,
		protected readonly BannedEmailRepository $bannedEmailRepository,
		protected readonly OrderLogItemRepository $orderLogItemRepository,
		protected readonly CustomerGroupRepository $customerGroupRepository,
		protected readonly CatalogPermissionRepository $catalogPermissionRepository,
		protected readonly AttributeAssignRepository $attributeAssignRepository,
		protected readonly ReviewRepository $reviewRepository,
		protected readonly NewsletterUserRepository $newsletterUserRepository,
		protected readonly SettingRepository $settingRepository,
		protected readonly RelatedCartItemRepository $relatedCartItemRepository,
		protected readonly RelatedPackageItemRepository $relatedPackageItemRepository,
		protected readonly RelatedTypeRepository $relatedTypeRepository,
		protected readonly DIConnection $stm,
		protected readonly PurchaseRepository $purchaseRepository,
		protected readonly PriceRepository $priceRepository,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly Request $request,
		protected readonly Nette\DI\Container $container,
		protected readonly Integrations $integrations,
		protected readonly AddressRepository $addressRepository,
	) {
	}
	
	public function startup(): void
	{
		if (!$this->request->getCookie('cartToken') && !$this->getCustomer()) {
			$this->cartToken = DIConnection::generateUuid();
			$this->response->setCookie('cartToken', $this->cartToken, $this->cartExpiration . ' days');
		} else {
			$this->cartToken = $this->request->getCookie('cartToken');
		}
		
		if ($this->getCustomer() && $this->cartToken) {
			if ($cart = $this->cartRepository->getUnattachedCart($this->cartToken)) {
				$this->handleCartOnLogin($cart, $this->getCustomer());
			}
			
			$this->response->deleteCookie('cartToken');
			$this->cartToken = null;
		}
		
		$this->lastOrderToken = $this->request->getCookie('lastOrderToken');
	}
	
	public function getCustomer(): ?Customer
	{
		return $this->customer ?: $this->shopperUser->getCustomer();
	}
	
	public function setCustomer(?Customer $customer): void
	{
		$this->customer = $customer;
	}
	
	/**
	 * @param \Eshop\DB\Product $product Must have set prices
	 * @param \Eshop\DB\Variant|null $variant
	 * @param int $amount
	 * @param ?bool $replaceMode true - replace | false - add or update | null - only add
	 * @param \Eshop\Common\CheckInvalidAmount $checkInvalidAmount
	 * @param ?bool $checkCanBuy
	 * @param \Eshop\DB\Cart|null $cart
	 * @param \Eshop\DB\CartItem|null $upsell
	 * @throws \Eshop\BuyException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function addItemToCart(
		Product $product,
		?Variant $variant = null,
		int $amount = 1,
		?bool $replaceMode = false,
		CheckInvalidAmount $checkInvalidAmount = CheckInvalidAmount::CHECK_THROW,
		?bool $checkCanBuy = true,
		?Cart $cart = null,
		?CartItem $upsell = null,
		?string $cartId = self::ACTIVE_CART_ID,
	): CartItem {
		if (!$this->checkCurrency($product, $cartId)) {
			throw new BuyException('Invalid currency', BuyException::INVALID_CURRENCY);
		}
		
		if ($checkCanBuy !== false && !$this->shopperUser->getBuyPermission()) {
			throw new BuyException('Permission denied', BuyException::PERMISSION_DENIED);
		}
		
		$disabled = false;
		
		if ($checkCanBuy !== false && !$this->shopperUser->canBuyProduct($product)) {
			if ($checkCanBuy === true) {
				throw new BuyException('Product is not for sell', BuyException::NOT_FOR_SELL);
			}
			
			$disabled = true;
		}
		
		if (!$this->shopperUser->canBuyProductAmount($product, $amount)) {
			if ($checkInvalidAmount === CheckInvalidAmount::CHECK_THROW) {
				throw new BuyException("Invalid amount: $amount", BuyException::INVALID_AMOUNT);
			}
			
			if ($checkInvalidAmount === CheckInvalidAmount::CHECK_NO_THROW) {
				$disabled = true;
			} elseif ($checkInvalidAmount === CheckInvalidAmount::SET_DEFAULT_AMOUNT) {
				$amount = $product->defaultBuyCount;
			}
		}
		
		if ($replaceMode !== null && $item = $this->cartItemRepository->getItem($cart ?? $this->getCart($cartId), $product, $variant)) {
			$this->changeItemAmount($product, $variant, $replaceMode ? $amount : $item->amount + $amount, $checkInvalidAmount !== CheckInvalidAmount::NO_CHECK, $cart ?? $this->getCart($cartId));
			
			Arrays::invoke($this->onCartItemCreate, $item);
			
			return $item;
		}
		
		$cartItem = $this->cartItemRepository->syncItem($cart ?? $this->getCart($cartId), null, $product, $variant, $amount, $disabled);
		
		if ($upsell) {
			$cartItem->update(['upsell' => $upsell->getPK(),]);
		}
		
		if ($currency = $this->getCartCurrency()) {
			$taxes = $this->taxRepository->getTaxesForProduct($product, $currency);
			
			foreach ($taxes as $tax) {
				$tax = $tax->toArray();
				$this->cartItemTaxRepository->createOne([
					'name' => $tax['name'],
					'price' => $tax['price'],
					'cartItem' => $cartItem->getPK(),
				], null);
			}
		}
		
		$this->refreshSumProperties();
		
		Arrays::invoke($this->onCartItemCreate, $cartItem);
		
		return $cartItem;
	}
	
	public function cartExists(?string $id = self::ACTIVE_CART_ID): bool
	{
		return (bool) ($id === self::ACTIVE_CART_ID ? $this->getActiveCart() : $this->getRealCart($id));
	}
	
	public function getCart(?string $id = self::ACTIVE_CART_ID, bool $createIfNotExists = true): Cart
	{
		$cart = $id === self::ACTIVE_CART_ID ? $this->getActiveCart() : $this->getRealCart($id);
		
		if (!$cart) {
			if (!$createIfNotExists) {
				throw new Nette\Application\ApplicationException("Cart #$id not exists");
			}
			
			return $this->createCart($id ?? self::DEFAULT_CART_ID, $id === self::ACTIVE_CART_ID);
		}
		
		return $cart;
	}
	
	public function handleCartOnLogin(Cart $oldCart, Customer $customer): void
	{
		unset($customer);
		
		$this->addItemsFromCart($oldCart);
		
		if ($oldCart->closedTs) {
			return;
		}
		
		$oldCart->delete();
	}
	
	/**
	 * @param \Eshop\DB\Currency|null $currency
	 * @param \Eshop\DB\DiscountCoupon|null $discountCoupon
	 * @return \StORM\Collection<\Eshop\DB\Pricelist>
	 */
	public function getPricelists(?Currency $currency = null, ?DiscountCoupon $discountCoupon = null): Collection
	{
		return $this->shopperUser->getPricelists($currency, $discountCoupon ?? $this->getDiscountCoupon());
	}
	
	public function getSumPrice(?string $id = self::ACTIVE_CART_ID): float
	{
		if (!$this->cartExists($id)) {
			return 0.0;
		}
		
		return $this->sumPrice[$id] ??= $this->cartItemRepository->getSumProperty([$this->getCart($id)->getPK()], 'price');
	}
	
	public function getSumPriceVat(?string $id = self::ACTIVE_CART_ID): float
	{
		if (!$this->cartExists($id)) {
			return 0.0;
		}
		
		return $this->sumPriceVat[$id] ??= $this->cartItemRepository->getSumProperty([$this->getCart($id)->getPK()], 'priceVat');
	}
	
	public function getSumPriceBefore(?string $id = self::ACTIVE_CART_ID): float
	{
		if (!$this->cartExists($id)) {
			return 0.0;
		}
		
		return $this->sumPriceBefore[$id] ??= $this->cartItemRepository->getSumProperty([$this->getCart($id)->getPK()], 'priceBefore');
	}
	
	public function getSumPriceVatBefore(?string $id = self::ACTIVE_CART_ID): float
	{
		if (!$this->cartExists($id)) {
			return 0.0;
		}
		
		return $this->sumPriceVatBefore[$id] ??= $this->cartItemRepository->getSumProperty([$this->getCart($id)->getPK()], 'priceVatBefore');
	}
	
	public function getSumItems(?string $id = self::ACTIVE_CART_ID): int
	{
		if (!$this->cartExists($id)) {
			return 0;
		}
		
		return $this->sumAmount[$id] ??= $this->cartItemRepository->getSumItems($this->getCart($id));
	}
	
	public function getSumAmount(?string $id = self::ACTIVE_CART_ID): int
	{
		if (!$this->cartExists($id)) {
			return 0;
		}
		
		return $this->sumAmountTotal[$id] ??= (int) $this->cartItemRepository->getSumProperty([$this->getCart($id)->getPK()], 'amount');
	}
	
	public function getSumWeight(?string $id = self::ACTIVE_CART_ID): float
	{
		if (!$this->cartExists($id)) {
			return 0.0;
		}
		
		return $this->sumWeight[$id] ??= $this->cartItemRepository->getSumProperty([$this->getCart($id)->getPK()], 'productWeight');
	}
	
	public function getSumDimension(?string $id = self::ACTIVE_CART_ID): float
	{
		if (!$this->cartExists($id)) {
			return 0.0;
		}
		
		return $this->sumDimension[$id] ??= $this->cartItemRepository->getSumProperty([$this->getCart($id)->getPK()], 'productDimension');
	}
	
	public function getMaxWeight(?string $id = self::ACTIVE_CART_ID): float
	{
		if (!$this->cartExists()) {
			return 0.0;
		}
		
		return $this->maxWeight[$id] ??= $this->cartItemRepository->many()->where('fk_cart', $this->getCart($id)->getPK())->max('productWeight');
	}
	
	public function getMaxDimension(?string $id = self::ACTIVE_CART_ID): int
	{
		if (!$this->cartExists($id)) {
			return 0;
		}
		
		return $this->maxDimension[$id] ??= (int) $this->cartItemRepository->many()->where('fk_cart', $this->getCart($id)->getPK())->max('GREATEST(productWidth,productLength,productDepth)');
	}
	
	public function getSumPoints(?string $id = self::ACTIVE_CART_ID): int
	{
		if (!$this->cartExists($id)) {
			return 0;
		}
		
		return $this->sumPoints[$id] ??= (int) $this->cartItemRepository->getSumProperty([$this->getCart($id)->getPK()], 'pts');
	}
	
	public function getCartCurrency(?string $id = self::ACTIVE_CART_ID): ?Currency
	{
		return $this->cartExists($id) ? $this->getCart($id)->currency : null;
	}
	
	public function getCartCurrencyCode(?string $id = self::ACTIVE_CART_ID): ?string
	{
		return $this->cartExists($id) ? $this->getCart($id)->currency->code : null;
	}
	
	public function createCart(string $id = self::DEFAULT_CART_ID, bool $activate = true): Cart
	{
		$cart = $this->cartRepository->createOne([
			'uuid' => $this->getCustomer() ? null : ($id === self::DEFAULT_CART_ID ? $this->cartToken : null),
			'id' => $id,
			'active' => $activate,
			'cartToken' => $this->getCustomer() ? null : $this->cartToken,
			'customer' => $this->getCustomer() ?: null,
			'currency' => $this->getCustomer() && $this->getCustomer()->preferredCurrency ? $this->getCustomer()->preferredCurrency : $this->shopperUser->getCurrency(),
			'expirationTs' => $this->getCustomer() ? null : (string) new \Carbon\Carbon('+' . $this->cartExpiration . ' days'),
			'shop' => $this->shopsConfig->getSelectedShop(),
		]);
		
		if ($activate) {
			$this->getCustomer() ? $this->getCustomer()->update(['activeCart' => $cart]) : $this->unattachedCarts[$this->cartToken] = $cart;
			
			Arrays::invoke($this->onCartCreate, $cart);
		} else {
			$this->carts[$id] = $cart;
		}
		
		return $cart;
	}
	
	public function switchCart(string $id, bool $createIfNotExists = false): Cart
	{
		$this->stm->getLink()->beginTransaction();
		
		$this->getCart()->update(['activate' => false]);
		
		$cart = $this->getCart($id, $createIfNotExists);
		$cart->update(['activate' => true]);
		
		if ($this->getCustomer()) {
			$this->getCustomer()->update(['activeCart' => $cart]);
		}
		
		$this->stm->getLink()->commit();
		
		return $cart;
	}
	
	public function canBuyProduct(Product $product): bool
	{
		return $this->shopperUser->canBuyProduct($product);
	}
	
	public function updateItemInCart(
		CartItem $item,
		Product $product,
		?Variant $variant = null,
		int $amount = 1,
		bool $checkInvalidAmount = true,
		bool $checkCanBuy = true,
		?string $cartId = self::ACTIVE_CART_ID
	): void {
		if (!$this->checkCurrency($product)) {
			throw new BuyException('Invalid currency', BuyException::INVALID_CURRENCY);
		}
		
		if ($checkCanBuy && !$this->canBuyProduct($product)) {
			throw new BuyException('Product is not for sell', BuyException::NOT_FOR_SELL);
		}
		
		if ($checkInvalidAmount && !$this->checkAmount($product, $amount)) {
			throw new BuyException("Invalid amount: $amount", BuyException::INVALID_AMOUNT);
		}
		
		$this->cartItemRepository->syncItem($this->getCart($cartId), $item, $product, $variant, $amount);
	}
	
	/**
	 * @param \Eshop\DB\CartItem $cartItem
	 * @param \Eshop\DB\Product $upsell Must be processed by getCartItemRelations()
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function addUpsellToCart(CartItem $cartItem, Product $upsell, ?int $realAmount = null): CartItem
	{
		$existingUpsell = $this->cartItemRepository->getUpsellByObjects($cartItem, $upsell);
		
		if ($existingUpsell) {
			$realAmount = $realAmount && $existingUpsell->realAmount ? $existingUpsell->realAmount + $realAmount : null;
			
			$existingUpsell->update([
				'price' => (float) $upsell->getValue('price'),
				'priceVat' => (float) $upsell->getValue('priceVat'),
				'amount' => $realAmount ? $realAmount * $cartItem->amount : $upsell->getValue('amount'),
				'realAmount' => $realAmount,
			]);
			
			return $this->cartItemRepository->getUpsellByObjects($cartItem, $upsell);
		}
		
		/** @var \Eshop\DB\VatRateRepository $vatRepo */
		$vatRepo = $this->cartItemRepository->getConnection()->findRepository(VatRate::class);
		/** @var \Eshop\DB\VatRate|null $vat */
		$vat = $vatRepo->one($upsell->vatRate);
		
		$vatPct = $vat ? $vat->rate : 0;
		$amount = $realAmount ? $realAmount * $cartItem->amount : $upsell->getValue('amount');
		
		return $this->cartItemRepository->createOne([
			'productName' => $upsell->toArray()['name'],
			'productCode' => $upsell->getFullCode(),
			'productSubCode' => $upsell->subCode,
			'productWeight' => $upsell->weight,
			'productDimension' => $upsell->dimension,
			'amount' => $amount,
			'realAmount' => $realAmount,
			'price' => $upsell->getPrice($amount),
			'priceVat' => $upsell->getPriceVat($amount),
			'vatPct' => (float) $vatPct,
			'product' => $upsell->getPK(),
			'cart' => $cartItem->getValue('cart'),
			'upsell' => $cartItem->getPK(),
		]);
	}
	
	/**
	 * @throws \Eshop\BuyException
	 */
	public function changeItemAmount(Product $product, ?Variant $variant = null, int $amount = 1, ?bool $checkInvalidAmount = true, ?Cart $cart = null, ?string $cartId = self::ACTIVE_CART_ID): void
	{
		if ($checkInvalidAmount && !$this->checkAmount($product, $amount)) {
			throw new BuyException("Invalid amount: $amount", BuyException::INVALID_AMOUNT);
		}
		
		$this->cartItemRepository->updateItemAmount($cart ?: $this->getCart($cartId), $variant, $product, $amount);
		$this->refreshSumProperties();
		
		if (!($cartItem = $this->cartItemRepository->getItem($cart ?: $this->getCart($cartId), $product, $variant))) {
			return;
		}
		
		$this->updateItem($cartItem);
	}
	
	/**
	 * @throws \Eshop\BuyException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function changeCartItemAmount(Product $product, CartItem $cartItem, int $amount = 1, ?bool $checkInvalidAmount = true): void
	{
		if ($checkInvalidAmount && !$this->checkAmount($product, $amount)) {
			throw new BuyException("Invalid amount: $amount", BuyException::INVALID_AMOUNT);
		}
		
		$this->cartItemRepository->updateCartItemAmount($cartItem, $product, $amount);
		$this->refreshSumProperties();
		
		if (!($cartItem = $this->cartItemRepository->one($cartItem->getPK()))) {
			return;
		}
		
		$this->updateItem($cartItem);
	}
	
	public function deleteItem(CartItem $item, ?string $cartId = self::ACTIVE_CART_ID): void
	{
		$this->cartItemTaxRepository->many()->where('fk_cartItem', $item->getPK())->delete();
		
		$cart = $this->getCart($cartId);
		
		if (!$cart->closedTs) {
			$this->cartItemRepository->deleteItem($this->getCart($cartId), $item);
		}
		
		if (!$this->getSumItems($cartId)) {
			$this->deleteCart($cartId);
		} else {
			$this->refreshSumProperties($cartId);
		}
		
		Arrays::invoke($this->onCartItemDelete);
	}
	
	public function deleteCart(?string $id = self::ACTIVE_CART_ID): void
	{
		$cart = $this->getCart($id);
		
		$this->cartItemTaxRepository->many()->where('fk_cartItem', \array_keys($this->getItems($id)->toArray()))->delete();
		
		if (!$cart->closedTs) {
			$this->cartRepository->deleteCart($cart);
		}
		
		if ($this->getCustomer() && $cart->active) {
			$this->getCustomer()->activeCart = null;
		} else {
			unset($this->unattachedCarts[$this->cartToken]);
		}
		
		$this->refreshSumProperties($id);
	}
	
	public function changeItemNote(Product $product, ?Variant $variant = null, ?string $note = null, ?string $cartId = self::ACTIVE_CART_ID): void
	{
		$this->cartItemRepository->updateNote($this->getCart($cartId), $product, $variant, $note);
	}
	
	public function getItems(?string $cartId = self::ACTIVE_CART_ID): Collection
	{
		return $this->cartExists($cartId) ? $this->cartItemRepository->getItems([$this->getCart($cartId)->getPK()]) : $this->cartItemRepository->many()->where('1=0');
	}
	
	public function getCartItem(Product $product, ?string $cartId = self::ACTIVE_CART_ID): ?CartItem
	{
		return $this->cartExists($cartId) ? $this->cartItemRepository->getItem($this->getCart($cartId), $product) : null;
	}
	
	/**
	 * @return \StORM\Collection<\Eshop\DB\CartItem>
	 */
	public function getTopLevelItems(?string $cartId = self::ACTIVE_CART_ID): Collection
	{
		return $this->getItems($cartId)->where('this.fk_upsell IS NULL');
	}
	
	public function addItemsFromCart(Cart $cart, bool $required = false): bool
	{
		$ids = $this->cartItemRepository->getItems([$cart->getPK()])->where('this.fk_product IS NOT NULL')->setSelect(['aux' => 'this.fk_product'])->toArrayOf('aux');
		
		/** @var array<\Eshop\DB\Product> $products */
		$products = $this->productRepository->getProducts()->where('this.uuid', $ids)->toArray();
		
		$upsellsMap = [];
		/** @var array<\Eshop\DB\CartItem> $nonUpsellItems */
		$nonUpsellItems = $this->cartItemRepository->getItems([$cart->getPK()])->where('this.fk_upsell IS NULL')->toArray();
		
		$someProductNotFound = false;
		
		foreach ($nonUpsellItems as $item) {
			if (!isset($products[$item->getValue('product')])) {
				if ($required) {
					throw new BuyException('product not found');
				}
				
				$someProductNotFound = true;
				
				continue;
			}
			
			$newItem = $this->addItemToCart($products[$item->getValue('product')], $item->variant, $item->amount, null, CheckInvalidAmount::NO_CHECK, $required);
			
			$upsellsMap[$item->getPK()] = $newItem;
		}
		
		$relations = $this->productRepository->getCartItemsRelations($nonUpsellItems, false, false);
		
		/** @var \Eshop\DB\CartItem $item */
		foreach ($this->cartItemRepository->getItems([$cart->getPK()])->where('this.fk_upsell IS NOT NULL') as $item) {
			if (!isset($products[$item->getValue('product')])) {
				if ($required) {
					throw new BuyException('product not found');
				}
				
				$someProductNotFound = true;
				
				continue;
			}
			
			$product = $item->getValue('product');
			
			if (($upsellProduct = $item->getValue('upsell')) === null) {
				if ($required) {
					throw new BuyException('Upsell product not found');
				}
				
				$someProductNotFound = true;
				
				continue;
			}
			
			if (!isset($relations[$upsellProduct][$product])) {
				if ($required) {
					throw new BuyException('Product not found');
				}
				
				$someProductNotFound = true;
				
				continue;
			}
			
			$product = $relations[$upsellProduct][$product];
			
			if (!$item->getPriceSum() > 0) {
				$product->price = 0;
			}
			
			if (!$item->getPriceVatSum() > 0) {
				$product->priceVat = 0;
			}
			
			$this->addUpsellToCart($upsellsMap[$item->getValue('upsell')], $product, $item->realAmount);
		}
		
		return !$someProductNotFound;
	}
	
	public function getPaymentTypes(): Collection
	{
		return $this->paymentTypes ??= $this->paymentTypeRepository->getPaymentTypes(
			$this->shopperUser->getCurrency(),
			$this->getCustomer(),
			$this->shopperUser->getCustomerGroup(),
			$this->shopsConfig->getSelectedShop(),
		);
	}
	
	/**
	 * @return array<\Eshop\DB\DeliveryType>
	 */
	public function getDeliveryTypesProcessed(bool $vat): array
	{
		$deliveryTypes = $this->deliveryTypeRepository->getDeliveryTypes(
			$this->shopperUser->getCurrency(),
			$this->getCustomer(),
			$this->shopperUser->getCustomerGroup(),
			$this->getDeliveryDiscount($vat),
			$this->getMaxWeight(),
			$this->getMaxDimension(),
			$this->getSumWeight(),
			$this->shopsConfig->getSelectedShop(),
		)->toArray();
		
		foreach ($deliveryTypes as $deliveryType) {
			$boxes = $deliveryType->maxWeight !== null ? \count($deliveryType->getBoxesForItems($this->getTopLevelItems()->toArray())) : 1;
			$deliveryType->setValue('packagesNo', $boxes);
		}
		
		return $deliveryTypes;
	}
	
	public function getDeliveryTypes(bool $vat = false): Collection
	{
		return $this->deliveryTypeRepository->getDeliveryTypes(
			$this->shopperUser->getCurrency(),
			$this->getCustomer(),
			$this->shopperUser->getCustomerGroup(),
			$this->getDeliveryDiscount($vat),
			$this->getMaxWeight(),
			$this->getMaxDimension(),
			$this->getSumWeight(),
			$this->shopsConfig->getSelectedShop(),
		);
	}
	
	public function checkDiscountCoupon(?string $cartId = self::ACTIVE_CART_ID): bool
	{
		/** @var \Eshop\DB\DiscountCoupon|null $discountCoupon */
		$discountCoupon = $this->getDiscountCoupon($cartId);
		
		if ($discountCoupon === null) {
			return true;
		}
		
		if ($this->isDiscountCouponValid !== null) {
			return $this->isDiscountCouponValid;
		}
		
		$this->setDiscountCoupon(null, $cartId);
		$this->fixCartItems($cartId);
		
		$valid = (bool) $this->discountCouponRepository->getValidCouponByCart($discountCoupon->code, $this->getCart($cartId), $discountCoupon->exclusiveCustomer);
		
		$this->setDiscountCoupon($discountCoupon);
		$this->fixCartItems($cartId);
		
		$this->isDiscountCouponValid = $valid;
		
		return $valid;
	}
	
	public function checkOrder(?string $cartId = self::ACTIVE_CART_ID): bool
	{
		return $this->checkCart($cartId);
	}
	
	public function checkCart(?string $cartId = self::ACTIVE_CART_ID): bool
	{
		if (!\boolval(\count($this->getItems($cartId)))) {
			return false;
		}
		
		if (!$this->checkDiscountCoupon($cartId)) {
			return false;
		}
		
		return !\count($this->getIncorrectCartItems($cartId));
	}
	
	/**
	 * Fix cart if it is allowed by config
	 */
	public function autoFixCart(?string $cartId = self::ACTIVE_CART_ID): void
	{
		if (!$this->shopperUser->getAutoFixCart()) {
			return;
		}
		
		$this->fixCart($cartId);
	}
	
	/**
	 * Fix cart
	 */
	public function fixCart(?string $cartId = self::ACTIVE_CART_ID): void
	{
		if (!$this->checkDiscountCoupon($cartId)) {
			$this->setDiscountCoupon(null, $cartId);
		}
		
		$this->fixCartItems($cartId);
	}
	
	/**
	 * Fix cart
	 */
	public function fixCartItems(?string $cartId = self::ACTIVE_CART_ID): void
	{
		$incorrectItems = $this->getIncorrectCartItems($cartId);
		
		if (!$incorrectItems) {
			return;
		}
		
		foreach ($incorrectItems as $incorrectItem) {
			try {
				if ($incorrectItem['reason'] === IncorrectItemReason::UNAVAILABLE) {
					$incorrectItem['object']->delete();
				} elseif ($incorrectItem['reason'] === IncorrectItemReason::INCORRECT_AMOUNT) {
					$incorrectItem['object']->update([
						'amount' => $incorrectItem['correctValue'],
					]);
				} elseif ($incorrectItem['reason'] === IncorrectItemReason::INCORRECT_PRICE) {
					$incorrectItem['object']->update([
						'price' => $incorrectItem['correctValue'],
						'priceVat' => $incorrectItem['correctValueVat'],
						'priceBefore' => $incorrectItem['correctValueBefore'],
						'priceVatBefore' => $incorrectItem['correctValueVatBefore'],
					]);
				} elseif ($incorrectItem['reason'] === IncorrectItemReason::PRODUCT_ROUND) {
					$incorrectItem['object']->update([
						'amount' => $incorrectItem['correctValue'],
					]);
				}
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::EXCEPTION);
			}
		}
	}
	
	/**
	 * @return array<int, array{
	 *     object: \Eshop\DB\CartItem,
	 *     reason: string,
	 *     correctValue?: string|int|float|null,
	 *     correctValueVat?: string|int|float|null,
	 *     correctValueBefore?: null|float,
	 *     correctValueVatBefore?: null|float
	 * }>
	 */
	public function getIncorrectCartItems(?string $cartId = self::ACTIVE_CART_ID): array
	{
		$incorrectItems = [];
		
		/** @var \Eshop\DB\CartItem $cartItem */
		foreach ($this->getItems($cartId) as $cartItem) {
			if (!$cartItem->product) {
				$cartItem->delete();
				
				continue;
			}
			
			if ($cartItem->upsell) {
				continue;
			}
			
			if (!$cartItem->isAvailable()) {
				$incorrectItems[] = [
					'object' => $cartItem,
					'reason' => 'unavailable',
				];
				
				continue;
			}
			
			if (!$this->checkAmount($cartItem->product, $cartItem->amount)) {
				if ($cartItem->amount < $cartItem->product->minBuyCount) {
					$correctAmount = $cartItem->product->minBuyCount;
				} elseif ($cartItem->product->maxBuyCount !== null && $cartItem->amount > $cartItem->product->maxBuyCount) {
					$correctAmount = $cartItem->product->maxBuyCount;
				} elseif ($cartItem->product->buyStep !== null && $cartItem->amount % $cartItem->product->buyStep !== 0) {
					$correctAmount = $this->cartItemRepository->roundUpToNextMultiple($cartItem->amount, $cartItem->product->buyStep);
				} else {
					$correctAmount = null;
				}
				
				$incorrectItems[] = [
					'object' => $cartItem,
					'reason' => 'incorrect-amount',
					'correctValue' => $correctAmount,
				];
			}
			
			try {
				if (!$this->checkCartItemPrice($cartItem)) {
					$incorrectItems[] = [
						'object' => $cartItem,
						'reason' => 'incorrect-price',
						'correctValue' => $this->productRepository->getProduct($cartItem->product->getPK())->getPrice($cartItem->amount),
						'correctValueVat' => $this->productRepository->getProduct($cartItem->product->getPK())->getPriceVat($cartItem->amount),
						'correctValueBefore' => $this->productRepository->getProduct($cartItem->product->getPK())->getPriceBefore(),
						'correctValueVatBefore' => $this->productRepository->getProduct($cartItem->product->getPK())->getPriceVatBefore(),
					];
				}
			} catch (\Exception $e) {
				$cartItem->delete();
				
				continue;
			}
			
			$productRoundAmount = $this->getProductRoundAmount($cartItem->amount, $cartItem->product);
			
			if ($productRoundAmount === $cartItem->amount) {
				continue;
			}
			
			$incorrectItems[] = [
				'object' => $cartItem,
				'reason' => 'product-round',
				'correctValue' => $productRoundAmount,
			];
		}
		
		return $incorrectItems;
	}
	
	/**
	 * cart - pokud je buy allowed
	 * addresses - pokud je predchozi krok splen (doplneni adresy)
	 * deliveryPayment - pokud je predchozi krok splen (volba dopravy a platby)
	 * @param string $step
	 */
	public function isStepAllowed(string $step, ?string $cartId = self::ACTIVE_CART_ID): bool
	{
		$sequence = \array_search($step, $this->shopperUser->getCheckoutSequence());
		$previousStep = $this->shopperUser->getCheckoutSequence()[$sequence - 1] ?? null;
		
		if ($previousStep === 'cart') {
			return $this->getPurchase() && (bool) \count($this->getItems($cartId)) && \count($this->getIncorrectCartItems($cartId)) === 0 && $this->checkDiscountCoupon($cartId);
		}
		
		if ($previousStep === 'addresses') {
			return $this->getPurchase() && $this->getPurchase()->email;
		}
		
		if ($previousStep === 'deliveryPayment') {
			return $this->getPurchase() && $this->getPurchase()->deliveryType && $this->getPurchase()->paymentType;
		}
		
		return true;
	}
	
	/**
	 * @return array<bool>
	 */
	public function getCheckoutSteps(?string $cartId = self::ACTIVE_CART_ID): array
	{
		$steps = [];
		
		foreach ($this->shopperUser->getCheckoutSequence() as $step) {
			$steps[$step] = $this->isStepAllowed($step, $cartId);
		}
		
		return $steps;
	}
	
	public function getMaxStep(?string $cartId = self::ACTIVE_CART_ID): ?string
	{
		$lastStep = null;
		
		foreach ($this->shopperUser->getCheckoutSequence() as $step) {
			if (!$this->isStepAllowed($step, $cartId)) {
				break;
			}
			
			$lastStep = $step;
		}
		
		return $lastStep;
	}

	public function getCheckoutPrice(?string $cartId = self::ACTIVE_CART_ID, bool $purchaseDiscount = true): float
	{
		$price = $this->getSumPrice($cartId);
		
		if ($purchaseDiscount && $this->getPurchaseDiscount($cartId)) {
			$price -= \round($price * $this->getPurchaseDiscount($cartId) / 100, 2);
		}
		
		$price += $this->getDeliveryPrice() + $this->getPaymentPrice() - $this->getDiscountPrice($cartId);
		
		return $price ?: 0.0;
	}
	
	public function getCheckoutPriceVat(?string $cartId = self::ACTIVE_CART_ID, bool $purchaseDiscount = true): float
	{
		$priceVat = $this->getSumPriceVat($cartId);
		
		if ($purchaseDiscount && $this->getPurchaseDiscount($cartId)) {
			$priceVat -= \round($priceVat * $this->getPurchaseDiscount($cartId) / 100, 2);
		}
		
		$priceVat += $this->getDeliveryPriceVat() + $this->getPaymentPriceVat() - $this->getDiscountPriceVat($cartId);
		
		return $priceVat ?: 0.0;
	}

	public function getCheckoutPriceVatBefore(?string $cartId = self::ACTIVE_CART_ID): float|null
	{
		$priceVat = $this->getSumPriceVatBefore($cartId) + $this->getDeliveryPriceVat() + $this->getPaymentPriceVat();

		return $priceVat ?: null;
	}
	
	public function getCartCheckoutPrice(?string $cartId = self::ACTIVE_CART_ID, bool $purchaseDiscount = true): float
	{
		$price = $this->getSumPrice($cartId);
		
		if ($purchaseDiscount && $this->getPurchaseDiscount($cartId)) {
			$price -= \round($price * $this->getPurchaseDiscount($cartId) / 100, 2);
		}
		
		$price -= $this->getDiscountPrice($cartId);
		
		return $price ?: 0.0;
	}
	
	public function getCartCheckoutPriceVat(?string $cartId = self::ACTIVE_CART_ID, bool $purchaseDiscount = true): float
	{
		$priceVat = $this->getSumPriceVat($cartId);
		
		if ($purchaseDiscount && $this->getPurchaseDiscount($cartId)) {
			$priceVat -= \round($priceVat * $this->getPurchaseDiscount($cartId) / 100, 2);
		}
		
		$priceVat -= $this->getDiscountPriceVat($cartId);
		
		return $priceVat ?: 0.0;
	}

	/**
	 * @deprecated Don't work in all cases!
	 */
	public function getCartCheckoutPriceBefore(): float
	{
		$price = $this->getSumPriceBefore();

		return $price ?: 0.0;
	}

	/**
	 * @deprecated Don't work in all cases!
	 */
	public function getCartCheckoutPriceVatBefore(): float
	{
		$priceVat = $this->getSumPriceVatBefore();

		return $priceVat ?: 0.0;
	}
	
	public function getPaymentPrice(?string $cartId = self::ACTIVE_CART_ID): float
	{
		if ($this->getPurchase(false, $cartId) && $this->getPurchase(false, $cartId)->paymentType) {
			$paymentType = $this->getPaymentTypes()[$this->getPurchase(false, $cartId)->getValue('paymentType')] ?? null;
			
			if (!$paymentType) {
				return 0.0;
			}
			
			$price = $paymentType->getValue('price');
			
			return isset($price) ? (float) $price : 0.0;
		}
		
		return 0.0;
	}
	
	public function getPaymentPriceVat(?string $cartId = self::ACTIVE_CART_ID): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			$paymentType = $this->getPaymentTypes()[$this->getPurchase(false, $cartId)->getValue('paymentType')] ?? null;
			
			if (!$paymentType) {
				return 0.0;
			}
			
			$price = $paymentType->getValue('priceVat');
			
			return isset($price) ? (float) $price : 0.0;
		}
		
		return 0.0;
	}
	
	public function getDeliveryPrice($includePackagesNo = true, ?string $cartId = self::ACTIVE_CART_ID): float
	{
		if ($this->getPurchase(false, $cartId) && $this->getPurchase(false, $cartId)->deliveryType) {
			$deliveryPackagesNo = $includePackagesNo ? $this->getPurchase(true, $cartId)->deliveryPackagesNo : 1;
			$showPrice = $this->shopperUser->getMainPriceType();
			
			try {
				$price = $this->getDeliveryTypes($showPrice === 'withVat')[$this->getPurchase(false, $cartId)->getValue('deliveryType')]->getValue('price');
				
				return isset($price) ? (float) $price * $deliveryPackagesNo : 0.0;
			} catch (NotFoundException $e) {
				$this->getPurchase()->update(['deliveryType' => null]);
				
				return 0.0;
			}
		}
		
		return 0.0;
	}
	
	public function getDeliveryPriceVat($includePackagesNo = true, ?string $cartId = self::ACTIVE_CART_ID): float
	{
		if ($this->getPurchase(false, $cartId) && $this->getPurchase(false, $cartId)->deliveryType) {
			$deliveryPackagesNo = $includePackagesNo ? $this->getPurchase(true, $cartId)->deliveryPackagesNo : 1;
			$showPrice = $this->shopperUser->getShowPrice();
			
			try {
				$price = $this->getDeliveryTypes($showPrice === 'withVat')[$this->getPurchase(false, $cartId)->getValue('deliveryType')]->getValue('priceVat');
				
				return isset($price) ? (float) $price * $deliveryPackagesNo : 0.0;
			} catch (NotFoundException $e) {
				$this->getPurchase()->update(['deliveryType' => null]);
				
				return 0.0;
			}
		}
		
		return 0.0;
	}
	
	public function getDeliveryPriceBefore($includePackagesNo = true, ?string $cartId = self::ACTIVE_CART_ID): ?float
	{
		if ($this->getPurchase(false, $cartId) && $this->getPurchase(false, $cartId)->deliveryType) {
			$deliveryPackagesNo = $includePackagesNo ? $this->getPurchase(true, $cartId)->deliveryPackagesNo : 1;
			$showPrice = $this->shopperUser->getShowPrice();
			$price = $this->getDeliveryTypes($showPrice === 'withVat')[$this->getPurchase(false, $cartId)->getValue('deliveryType')]->getValue('priceBefore');
			
			return isset($price) ? (float) $price * $deliveryPackagesNo : null;
		}
		
		return null;
	}
	
	public function getDeliveryPriceVatBefore($includePackagesNo = true, ?string $cartId = self::ACTIVE_CART_ID): ?float
	{
		if ($this->getPurchase(false, $cartId) && $this->getPurchase(false, $cartId)->deliveryType) {
			$deliveryPackagesNo = $includePackagesNo ? $this->getPurchase(true, $cartId)->deliveryPackagesNo : 1;
			$showPrice = $this->shopperUser->getShowPrice();
			$price = $this->getDeliveryTypes($showPrice === 'withVat')[$this->getPurchase(false, $cartId)->getValue('deliveryType')]->getValue('priceBeforeVat');
			
			return isset($price) ? (float) $price * $deliveryPackagesNo : null;
		}
		
		return null;
	}
	
	public function getDeliveryDiscount(bool $vat = false, ?string $cartId = self::ACTIVE_CART_ID): ?DeliveryDiscount
	{
		$currency = $this->cartExists($cartId) ? $this->getCart($cartId)->currency : $this->shopperUser->getCurrency();
		
		return $this->deliveryDiscountRepository->getActiveDeliveryDiscount(
			$currency,
			$vat ? $this->getCartCheckoutPriceVat($cartId) : $this->getCartCheckoutPrice($cartId),
			$this->getSumWeight($cartId)
		);
	}
	
	public function getPossibleDeliveryDiscount(bool $vat = false, ?string $cartId = self::ACTIVE_CART_ID): ?DeliveryDiscount
	{
		$currency = $this->cartExists($cartId) ? $this->getCart($cartId)->currency : $this->shopperUser->getCurrency();
		
		return $this->deliveryDiscountRepository->getNextDeliveryDiscount(
			$currency,
			$vat ? $this->getCartCheckoutPriceVat($cartId) : $this->getCartCheckoutPrice($cartId),
			$this->getSumWeight($cartId)
		);
	}
	
	public function getPriceLeftToNextDeliveryDiscount(?string $cartId = self::ACTIVE_CART_ID): ?float
	{
		return $this->getPossibleDeliveryDiscount(false, $cartId) ? $this->getPossibleDeliveryDiscount(false, $cartId)->discountPriceFrom - $this->getCartCheckoutPrice($cartId) : null;
	}
	
	public function getPriceVatLeftToNextDeliveryDiscount(?string $cartId = self::ACTIVE_CART_ID): ?float
	{
		return $this->getPossibleDeliveryDiscount(true, $cartId) ? $this->getPossibleDeliveryDiscount(true, $cartId)->discountPriceFrom - $this->getCartCheckoutPriceVat($cartId) : null;
	}
	
	public function getDeliveryDiscountProgress(?string $cartId = self::ACTIVE_CART_ID): ?float
	{
		return $this->getPossibleDeliveryDiscount() ? $this->getCartCheckoutPrice($cartId) / $this->getPossibleDeliveryDiscount()->discountPriceFrom * 100 : null;
	}
	
	public function getDeliveryDiscountProgressVat(?string $cartId = self::ACTIVE_CART_ID): ?float
	{
		return $this->getPossibleDeliveryDiscount(true) ? $this->getCartCheckoutPriceVat($cartId) / $this->getPossibleDeliveryDiscount(true)->discountPriceFrom * 100 : null;
	}

	public function getDeliveryDiscountProgressAuto(?string $cartId = self::ACTIVE_CART_ID): ?float
	{
		return $this->shopperUser->getMainPriceType() === 'withVat' ? $this->getDeliveryDiscountProgressVat($cartId) : $this->getDeliveryDiscountProgress($cartId);
	}
	
	public function getDiscountCoupon(?string $cartId = self::ACTIVE_CART_ID): ?DiscountCoupon
	{
		if (!$this->cartExists($cartId) || !$this->getPurchase(false, $cartId)) {
			return null;
		}
		
		return $this->getPurchase(false, $cartId)->coupon;
	}
	
	public function setDiscountCoupon(?DiscountCoupon $coupon, ?string $cartId = self::ACTIVE_CART_ID): void
	{
		if (!$this->getPurchase(false, $cartId)) {
			$purchase = $this->syncPurchase([], $cartId);
		}
		
		($purchase ?? $this->getPurchase(false, $cartId))->update(['coupon' => $coupon]);
		
		$this->isDiscountCouponValid = null;
	}
	
	public function getPurchaseDiscount(?string $cartId = self::ACTIVE_CART_ID): int
	{
		return $this->getPurchase(false, $cartId)?->discountPct ?? 0;
	}
	
	public function setPurchaseDiscount(int $value, ?string $cartId = self::ACTIVE_CART_ID): void
	{
		if (!$this->getPurchase(false, $cartId)) {
			$purchase = $this->syncPurchase([], $cartId);
		}
		
		($purchase ?? $this->getPurchase(false, $cartId))->update(['discountPct' => $value]);
	}
	
	public function getDiscountPrice(?string $cartId = self::ACTIVE_CART_ID): float
	{
		if ($coupon = $this->getDiscountCoupon($cartId)) {
			return \floatval($coupon->discountValue);
		}
		
		return 0.0;
	}
	
	public function getDiscountPriceVat(?string $cartId = self::ACTIVE_CART_ID): float
	{
		if ($coupon = $this->getDiscountCoupon($cartId)) {
			return \floatval($coupon->discountValueVat);
		}
		
		return 0.0;
	}
	
	public function checkAmount(Product $product, $amount): bool
	{
		return !($amount < $product->minBuyCount || ($product->maxBuyCount !== null && $amount > $product->maxBuyCount));
		
		//      || ($product->buyStep && $amount % $product->buyStep !== 0)
		//      $min = $product->minBuyCount ?? self::DEFAULT_MIN_BUY_COUNT;
		//      $max = $product->maxBuyCount ?? self::DEFAULT_MAX_BUY_COUNT;
		//
		//      return !($amount < $min || $amount > $max || ($amount && $product->buyStep && (($amount + $min - 1) % $product->buyStep !== 0)));
	}
	
	public function checkCartItemPrice(CartItem $cartItem): bool
	{
		$productPrice = $this->productRepository->getProduct($cartItem->product->getPK())->getPrice((int) $cartItem->amount);
		
		return \floatval($productPrice) === $cartItem->price;
	}
	
	public function getLastOrder(): ?Order
	{
		return $this->lastOrderToken ? $this->orderRepository->one($this->lastOrderToken) : null;
	}
	
	/**
	 * @param mixed $values
	 */
	public function syncPurchase($values, ?string $cartId = self::ACTIVE_CART_ID): Purchase
	{
		if (!$this->getCart($cartId)->getValue('purchase')) {
			$values['currency'] = $this->getCart($cartId)->getValue('currency');
		}
		
		/** @var \Eshop\DB\Purchase $purchase */
		$purchase = $this->getCart($cartId)->syncRelated('purchase', $values);
		
		return $purchase;
	}
	
	public function getPurchase(bool $needed = false, ?string $cartId = self::ACTIVE_CART_ID): ?Purchase
	{
		$purchase = $this->getCart($cartId)->purchase;
		
		if ($needed && !$purchase) {
			throw new \DomainException('purchase is not created yet');
		}
		
		return $purchase;
	}
	
	public function setDeliveryAddress(?Address $address): void
	{
		$this->getCart()->update(['deliveryAddress' => $address]);
	}
	
	public function createCustomer(Purchase $purchase, bool $createAccount = true): ?Customer
	{
		if ($createAccount) {
			$accountQuery = $this->accountRepository->many()->where('login', $purchase->email);

			$this->shopsConfig->filterShopsInShopEntityCollection($accountQuery);

			if (!$accountQuery->isEmpty()) {
				return null;
			}
			
			/** @var \Security\DB\Account $account */
			$account = $this->accountRepository->createOne([
				'uuid' => Connection::generateUuid(),
				'login' => $purchase->email,
				'password' => $purchase->password,
				'active' => !$this->shopperUser->getRegistrationConfiguration()['confirmation'],
				'authorized' => !$this->shopperUser->getRegistrationConfiguration()['emailAuthorization'],
				'confirmationToken' => $this->shopperUser->getRegistrationConfiguration()['emailAuthorization'] ? Nette\Utils\Random::generate(128) : null,
				'shop' => $this->shopsConfig->getSelectedShop()?->getPK(),
			]);
		}

		$customer = $this->findCustomerByPurchase($purchase);

		$defaultGroup = $this->customerGroupRepository->getDefaultRegistrationGroup();

		$customerValues = [
			'email' => $purchase->email,
			'fullname' => $purchase->isCompany() ? null : $purchase->fullname,
			'company' => $purchase->isCompany() ? $purchase->fullname : null,
			'phone' => $purchase->phone,
			'ic' => $purchase->ic,
			'dic' => $purchase->dic,
			'group' => $defaultGroup?->getPK(),
			'discountLevelPct' => $defaultGroup ? $defaultGroup->defaultDiscountLevelPct : 0,
			'shop' => $this->shopsConfig->getSelectedShop()?->getPK(),
		];

		if ($purchase->billAddress) {
			$data = $purchase->billAddress->toArray();
			unset($data['uuid'], $data['id']);

			if (!isset($data['name']) || !$data['name']) {
				$data['name'] = $purchase->fullname;
			}

			if ($customer?->getValue('billAddress')) {
				$customer->billAddress->update($data);
			} else {
				$billAddress = $this->addressRepository->createOne($data);

				$customerValues['billAddress'] = $billAddress->getPK();
			}
		}

		if ($purchase->deliveryAddress) {
			$data = $purchase->deliveryAddress->toArray();
			unset($data['uuid'], $data['id']);

			if ($customer?->getValue('deliveryAddress')) {
				$customer->deliveryAddress->update($data);
			} else {
				$deliveryAddress = $this->addressRepository->createOne($data);

				$customerValues['deliveryAddress'] = $deliveryAddress->getPK();
			}
		}
		
		if ($customer) {
			$customerValues['uuid'] = $customer->getPK();
		}
		
		/** @var \Eshop\DB\Customer|null $customer */
		$customer = $this->customerRepository->syncOne($customerValues);
		
		if (!$customer) {
			return null;
		}
		
		$customer = $this->customerRepository->one($customer->getPK(), true);
		
		if ($createAccount) {
			$customer->account = $account;
			
			$customerOrders = $this->orderRepository->many()->where('purchase.fk_customer', $customer->getPK())->toArrayOf('uuid', [], true);
			
			if ($customerOrders) {
				$this->purchaseRepository->many()
					->join(['eshop_order'], 'this.uuid = eshop_order.fk_purchase')
					->where('eshop_order.uuid', $customerOrders)
					->update([
						'fk_account' => $account->getPK(),
					]);
			}
			
			$this->catalogPermissionRepository->createOne([
				'catalogPermission' => $defaultGroup ? $defaultGroup->defaultCatalogPermission : 'none',
				'buyAllowed' => $defaultGroup ? $defaultGroup->defaultBuyAllowed : true,
				'orderAllowed' => true,
				'viewAllOrders' => $defaultGroup ? $defaultGroup->defaultViewAllOrders : false,
				'showPricesWithoutVat' => $defaultGroup ? $defaultGroup->defaultPricesWithoutVat : false,
				'showPricesWithVat' => $defaultGroup ? $defaultGroup->defaultPricesWithVat : false,
				'priorityPrice' => $defaultGroup ? $defaultGroup->defaultPriorityPrice : 'withoutVat',
				'customer' => $customer->getPK(),
				'account' => $account->getPK(),
			]);
		}
		
		if ($defaultGroup) {
			if ($defaultPricelists = $defaultGroup->getDefaultPricelists()->toArray()) {
				$customer->pricelists->relate(\array_keys($defaultPricelists));
			}

			if ($defaultVisibilityLists = $defaultGroup->getDefaultVisibilityLists()->toArray()) {
				$customer->visibilityLists->relate(\array_keys($defaultVisibilityLists));
			}
		}
		
		return $customer;
	}
	
	/**
	 * @param \Eshop\DB\Purchase|null $purchase
	 * @param array<string|int|float|null> $defaultOrderValues
	 * @param string $cartId
	 * @param bool $isLastOrder
	 * @throws \Eshop\BuyException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function createOrder(?Purchase $purchase = null, array $defaultOrderValues = [], ?string $cartId = self::ACTIVE_CART_ID, bool $isLastOrder = true): Order
	{
		/** @var \Eshop\DB\VatRateRepository $vatRepo */
		$vatRepo = $this->cartItemRepository->getConnection()->findRepository(VatRate::class);

		/** @var \Eshop\DB\Purchase $purchase */
		$purchase = $purchase ?: $this->getPurchase(true, $cartId);
		
		$banned = $purchase->email && $this->bannedEmailRepository->isEmailBanned($purchase->email);
		
		if (!$this->shopperUser->getAllowBannedEmailOrder() && $banned) {
			throw new BuyException('Banned email', BuyException::BANNED_EMAIL);
		}
		
		$discountCoupon = $this->getDiscountCoupon($cartId);
		
		if ($discountCoupon && $discountCoupon->usageLimit && $discountCoupon->usagesCount >= $discountCoupon->usageLimit) {
			throw new BuyException('Coupon invalid!', BuyException::INVALID_COUPON);
		}
		
		$customer = $this->getCustomer();
		$cart = $this->getCart($cartId);
		$currency = $cart->currency;
		
		$this->stm->getLink()->beginTransaction();
		
		if ($customer) {
			$purchase->update(['customerDiscountLevel' => $this->productRepository->getBestDiscountLevel($customer)]);
		}
		
		$cart->update([
			'approved' => ($customer && $customer->orderPermission === 'full') || !$customer ? 'yes' : 'waiting',
			'closedTs' => Carbon::now()->toDateTimeString(),
		]);
		
		// create customer
		if (!$customer) {
			if ($purchase->createAccount && $purchase->email) {
				$customer = $this->createCustomer($purchase);
				
				if ($customer) {
					$purchase = $this->syncPurchase(['customer' => $customer->getPK()]);
					
					Arrays::invoke($this->onCustomerCreate, $customer);
				}
			} elseif ($this->shopperUser->isAlwaysCreateCustomerOnOrderCreated()) {
				$customer = $this->createCustomer($purchase, false);
				
				$purchase = $this->syncPurchase(['customer' => $customer ? $customer->getPK() : $this->customerRepository->many()->match(['email' => $purchase->email])->first()]);
			}
		}

		Arrays::invoke($this->onOrderCustomerProcessed, $purchase);
		
		$orderValues = $defaultOrderValues + [
				'code' => $this->createOrderCode(),
				'purchase' => $purchase,
			];
		
		$orderValues['receivedTs'] = $this->shopperUser->getEditOrderAfterCreation() ? null : (string) new \Carbon\Carbon();
		$orderValues['newCustomer'] = !$purchase->customer || $this->orderRepository->many()
				->join(['purchase' => 'eshop_purchase'], 'purchase.uuid = this.fk_purchase')
				->where('purchase.fk_customer', $purchase->customer->getPK())
				->count() === 0;
		
		if ($this->shopperUser->getAllowBannedEmailOrder() && $banned) {
			$orderValues['bannedTs'] = (string) (new \Carbon\Carbon());
		}
		
		$orderValues['shop'] = $this->shopsConfig->getSelectedShop();
		
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->createOne($orderValues);

		$topLevelItems = $this->getTopLevelItems($cartId)->toArray();
		$boxList = $purchase->deliveryType ? $purchase->deliveryType->getBoxesForItems($topLevelItems) : $this->deliveryTypeRepository->getDefaultBoxes();
		$packageId = 0;

		foreach ($boxList as $box) {
			$packageItems = [];
			$packageWeight = 0.0;
			$packageId++;

			if (!\count($box->getItems())) {
				foreach ($topLevelItems as $cartItem) {
					$packageItems[$cartItem->getPK()] = [$cartItem, $cartItem->amount];
				}

				$packageWeight = $this->getSumWeight();
			} else {
				foreach ($box->getItems() as $item) {
					$cartItemId = $item->getItem()->getDescription();

					if (isset($packageItems[$cartItemId][1])) {
						$packageItems[$cartItemId][1]++;

						continue;
					}

					$packageItems[$item->getItem()->getDescription()] = [$this->getTopLevelItems()->where('this.uuid', $cartItemId)->first(true), 1];
				}

				$packageWeight = $box->getItems()->getWeight() / 1000;
			}

			/** @var \Eshop\DB\Delivery $delivery */
			$delivery = $this->deliveryRepository->createOne([
				'order' => $order,
				'currency' => $currency,
				'type' => $purchase->deliveryType,
				'typeName' => $purchase->deliveryType ? $purchase->deliveryType->toArray()['name'] : [],
				'typeCode' => $purchase->deliveryType?->code,
				'price' => $this->getDeliveryPrice(false),
				'priceVat' => $this->getDeliveryPriceVat(false),
				'priceBefore' => $this->getDeliveryPriceBefore(false),
				'priceVatBefore' => $this->getDeliveryPriceVatBefore(false),
			]);

			/** @var \Eshop\DB\Package $package */
			$package = $this->packageRepository->createOne([
				'id' => $packageId,
				'order' => $order->getPK(),
				'delivery' => $delivery->getPK(),
				'weight' => $packageWeight,
			]);

			$setRelationType = $this->settingRepository->getValueByName(SettingsPresenter::SET_RELATION_TYPE);

			if ($setRelationType) {
				/** @var \Eshop\DB\RelatedType $setRelationType */
				$setRelationType = $this->relatedTypeRepository->one($setRelationType);
			}

			foreach ($packageItems as $cartItemToParse) {
				/** @var \Eshop\DB\CartItem $cartItem */
				[$cartItem, $amount] = $cartItemToParse;

				/* Create package item for top-level cart items */
				$packageItem = $this->packageItemRepository->createOne([
					'package' => $package->getPK(),
					'cartItem' => $cartItem,
					'amount' => $amount,
				]);

				/* Create package items for upsells with link to top-level package item */
				$upsells = $purchase->getItems()->where('this.fk_upsell', $cartItem->getPK())->toArray();

				foreach ($upsells as $upsell) {
					$this->packageItemRepository->createOne([
						'package' => $package->getPK(),
						'cartItem' => $upsell->getPK(),
						'amount' => $upsell->amount,
						'upsell' => $packageItem->getPK(),
					]);
				}

				/* Get default set relation type and slave products in that relation for top-level cart item */
				if (!$setRelationType) {
					continue;
				}

				/** @var array<mixed> $relatedCartItems */
				$relatedCartItems = [];

				/* Load real products in relation with prices */
				$relatedProducts = $this->productRepository->getSlaveRelatedProducts($setRelationType, $cartItem->product)->toArray();

				if (!$relatedProducts) {
					continue;
				}

				$slaveProducts = [];

				foreach ($relatedProducts as $relatedProduct) {
					$slaveProducts[] = $relatedProduct->getValue('slave');
				}

				/* Compute total price of set items */
				/** @var array<\Eshop\DB\Product> $slaveProducts */
				$slaveProducts = $this->productRepository->getProducts()->where('this.uuid', $slaveProducts)->toArray();
				$slaveProductsTotalPrice = 0;
				$slaveProductsTotalPriceVat = 0;

				foreach ($relatedProducts as $relatedProduct) {
					if (!isset($slaveProducts[$relatedProduct->getValue('slave')])) {
						continue;
					}

					$slaveProductsTotalPrice += $slaveProducts[$relatedProduct->getValue('slave')]->getPrice() * $relatedProduct->amount;
					$slaveProductsTotalPriceVat += $slaveProducts[$relatedProduct->getValue('slave')]->getPriceVat() * $relatedProduct->amount;
				}

				$setTotalPriceModifier = $slaveProductsTotalPrice > 0 ? $cartItem->price / $slaveProductsTotalPrice : 1;
				$setTotalPriceVatModifier = $slaveProductsTotalPriceVat > 0 ? $cartItem->priceVat / $slaveProductsTotalPriceVat : 1;

				foreach ($relatedProducts as $relatedProduct) {
					if (!isset($slaveProducts[$relatedProduct->getValue('slave')])) {
						$product = $this->productRepository->one($relatedProduct->getValue('slave'), true);

						$product->setValue('price', 0);
						$product->setValue('priceVat', 0);
						$product->setValue('priceBefore', null);
						$product->setValue('priceVatBefore', null);
					} else {
						$product = $slaveProducts[$relatedProduct->getValue('slave')];
					}

					/** @var \Eshop\DB\VatRate|null $vat */
					$vat = $vatRepo->one($product->vatRate);
					$vatPct = $vat ? $vat->rate : 0;

					/* Create related cart items with price computed to match unit price of top-level cart item */
					$relatedCartItems[] = [
						'cartItem' => $cartItem->getPK(),
						'relatedType' => $setRelationType->getPK(),
						'product' => $product->getPK(),
						'relatedTypeCode' => $setRelationType->code,
						'relatedTypeName' => $setRelationType->name,
						'productName' => $product->toArray()['name'],
						'productCode' => $product->getFullCode(),
						'productSubCode' => $product->subCode,
						'productWeight' => $product->weight,
						'productDimension' => $product->dimension,
						'amount' => $relatedProduct->amount * $cartItem->amount,
						'price' => $product->getPrice() * $setTotalPriceModifier,
						'priceVat' => $product->getPriceVat() * $setTotalPriceVatModifier,
						'priceBefore' => $product->getPriceBefore() ?: $product->getPrice(),
						'priceVatBefore' => $product->getPriceVatBefore() ?: $product->getPriceVat(),
						'vatPct' => (float) $vatPct,
					];
				}

				$this->relatedCartItemRepository->many()->where('fk_cartItem', $cartItem->getPK())->delete();

				/** @var array<\Eshop\DB\RelatedCartItem> $relatedCartItems */
				$relatedCartItems = $this->relatedCartItemRepository->createMany($relatedCartItems)->toArray();

				/* Create relation between related package item and related cart item */
				foreach ($relatedCartItems as $relatedCartItem) {
					$this->relatedPackageItemRepository->createOne([
						'cartItem' => $relatedCartItem->getPK(),
						'packageItem' => $packageItem->getPK(),
					]);
				}
			}
		}
		
		if ($purchase->paymentType) {
			$this->paymentRepository->createOne([
				'order' => $order,
				'currency' => $currency,
				'type' => $purchase->paymentType,
				'typeName' => $purchase->paymentType->toArray()['name'],
				'typeCode' => $purchase->paymentType->code,
				'price' => $this->getPaymentPrice(),
				'priceVat' => $this->getPaymentPriceVat(),
			]);
		}
		
		if ($purchase->billAddress) {
			$purchase->billAddress->update(['name' => $purchase->fullname]);
		}

		try {
			$carts = $purchase->getCarts()->toArray();

			$serializedDataOfOrder = [
				'order' => $order->toArray(),
				'purchase' => $purchase->toArray(['billAddress', 'deliveryAddress']),
				'carts' => $carts,
				'cartItems' => [],
				'relatedCartItems' => [],
			];

			foreach ($carts as $cart) {
				/** @var array<\Eshop\DB\CartItem> $cartItems */
				$cartItems = $cart->getItems()->toArray();

				$serializedDataOfOrder['cartItems'] = \array_merge($cartItems, $serializedDataOfOrder['cartItems']);

				foreach ($cartItems as $cartItem) {
					$serializedDataOfOrder['relatedCartItems'] = \array_merge($cartItem->getRelatedCartItems()->toArray(), $serializedDataOfOrder['relatedCartItems']);
				}
			}

			Debugger::log(Nette\Utils\Json::encode($serializedDataOfOrder), 'orders');
		} catch (\Throwable $e) {
			Debugger::log('Cant log order: ' . $order->code, ILogger::EXCEPTION);
			Debugger::log($e, ILogger::EXCEPTION);
		}
		
		if ($isLastOrder) {
			$this->response->setCookie('lastOrderToken', $order->getPK(), '1 hour');
			
			if (!$this->getCustomer()) {
				$this->cartToken = DIConnection::generateUuid();
				$this->response->setCookie('cartToken', $this->cartToken, $this->cartExpiration . ' days');
			}
		}
		
		if ($customer) {
			if ($pointsGain = $this->orderRepository->getLoyaltyProgramPointsGainByOrderAndCustomer($order, $customer)) {
				$this->loyaltyProgramHistoryRepository->createOne([
					'points' => $pointsGain,
					'customer' => $customer,
					'loyaltyProgram' => $customer->getValue('loyaltyProgram'),
				]);
			}
			
			$customer->update([
				'lastOrder' => $order->getPK(),
				'ordersCount' => $customer->ordersCount + 1,
			]);
		}
		
		if ($discountCoupon) {
			$discountCoupon->update([
				'usagesCount' => $discountCoupon->usagesCount + 1,
				'lastUsageTs' => (new Carbon())->toDateTimeString(),
			]);
		}
		
		if ($purchase->sendNewsletters && $purchase->email) {
			$this->newsletterUserRepository->syncOne([
				'email' => $purchase->email,
				'customerAccount' => $customer && $customer->account ? $customer->account->getPK() : null,
				'shop' => $this->shopsConfig->getSelectedShop()?->getPK(),
			], null, false, true);
		}
		
		$this->orderLogItemRepository->createLog($order, OrderLogItem::CREATED);
		
		if ($isLastOrder) {
			$this->createCart();
		}
		
		if ($cartId !== self::ACTIVE_CART_ID) {
			unset($this->carts[$cartId]);
		}
		
		$this->refreshSumProperties($cartId);
		
		$this->stm->getLink()->commit();
		
		if ($purchase->email) {
			$this->reviewRepository->createReviewsFromOrder($order);
		}
		
		Arrays::invoke($this->onOrderCreate, $order);

		$this->onOrderCreate($order);
		
		return $order;
	}

	public function getAttributeNumericSumOfItemsInCart(Attribute $attribute, ?string $cartId = self::ACTIVE_CART_ID): ?float
	{
		$sum = 0;
		
		/** @var \Eshop\DB\CartItem $item
		 */
		foreach ($this->getItems($cartId) as $item) {
			if (!$item->getProduct()) {
				continue;
			}
			
			foreach ($this->attributeAssignRepository->many()
				->join(['attributevalue' => 'eshop_attributevalue'], 'this.fk_value = attributevalue.uuid')
				->where('attributevalue.fk_attribute', $attribute->getPK())
				->where('fk_product', $item->getValue('product')) as $assign) {
				$sum += $assign->value->number * $item->amount;
			}
		}
		
		return $sum;
	}

	protected function onOrderCreate(Order $order): void
	{
		unset($order);
	}
	
	protected function createOrderCode(): string
	{
		$year = Carbon::now()->format('Y');
		
		return \vsprintf(
			$this->shopperUser->getCountry()->orderCodeFormat,
			$this->orderCodeArguments ??
			[$this->orderRepository->many()->where('YEAR(this.createdTs)', $year)->enum() + $this->shopperUser->getCountry()->orderCodeStartNumber, $year],
		);
	}
	
	protected function getProductRoundAmount(int $amount, Product $product): int
	{
		if (!$this->getCustomer() || !$this->getCustomer()->productRoundingPct) {
			return $amount;
		}
		
		$prAmount = $amount * (1 + ($this->getCustomer()->productRoundingPct / 100));
		
		if ($product->inPalett > 0) {
			$newAmount = $this->cartItemRepository->roundUpToProductRoundAmount($amount, $prAmount, $product->inPalett);
			
			if ($amount !== $newAmount) {
				return $newAmount;
			}
		}
		
		if ($product->inCarton > 0) {
			$newAmount = $this->cartItemRepository->roundUpToProductRoundAmount($amount, $prAmount, $product->inCarton);
			
			if ($amount !== $newAmount) {
				return $newAmount;
			}
		}
		
		if ($product->inPackage > 0) {
			$newAmount = $this->cartItemRepository->roundUpToProductRoundAmount($amount, $prAmount, $product->inPackage);
			
			if ($amount !== $newAmount) {
				return $newAmount;
			}
		}
		
		return $amount;
	}

	protected function findCustomerByPurchase(Purchase $purchase): Customer|null
	{
		$customerQuery = $this->customerRepository->many()->where('this.email', $purchase->email);
		$this->shopsConfig->filterShopsInShopEntityCollection($customerQuery);

		return $customerQuery->first();
	}

	private function getActiveCart(): ?Cart
	{
		if ($this->getCustomer()) {
			return $this->getCustomer()->activeCart;
		}
		
		if (!\array_key_exists($this->cartToken, $this->unattachedCarts)) {
			$this->unattachedCarts[$this->cartToken] = $this->cartRepository->getUnattachedCart($this->cartToken);
		}
		
		return $this->unattachedCarts[$this->cartToken];
	}
	
	private function getRealCart(string $id, bool $saveToCache = true): ?Cart
	{
		$cart = null;
		
		if ($id === self::DEFAULT_CART_ID && !$this->getCustomer() && $this->cartToken) {
			$cart = $this->unattachedCarts[$this->cartToken] ?? $this->cartRepository->one($this->cartToken);
			
			if ($saveToCache && $cart) {
				$this->unattachedCarts[$this->cartToken] = $cart;
			}
		} elseif (!$this->getCustomer() && $this->cartToken) {
			$cart = $this->cartRepository->one(['cartToken' => $this->cartToken]);
			
			if ($saveToCache && $cart) {
				$this->unattachedCarts[$this->cartToken] = $cart;
			}
		} elseif ($this->getCustomer()->activeCart?->id === $id) {
			$cart = $this->getCustomer()->activeCart;
		} elseif ($this->getCustomer()) {
			if (\array_key_exists($id, $this->carts)) {
				return $this->carts[$id];
			}
			
			$cart = $this->cartRepository->many()
				->where('closedTs IS NULL')
				->whereMatch(['id' => $id, 'fk_customer' => $this->getCustomer(), 'fk_currency' => $this->getCustomer()->preferredCurrency ?: $this->shopperUser->getCurrency()])
				->setTake(1)
				->first();
			
			if ($saveToCache) {
				$this->carts[$id] = $cart;
			}
		}
		
		return $cart;
	}
	
	private function checkCurrency(Product $product, ?string $cartId = self::ACTIVE_CART_ID): bool
	{
		return $product->getValue('currencyCode') === $this->getCart($cartId)->currency->code;
	}
	
	private function refreshSumProperties(?string $cartId = self::ACTIVE_CART_ID): void
	{
		unset($this->sumPrice[$cartId]);
		unset($this->sumPriceVat[$cartId]);
		unset($this->sumAmountTotal[$cartId]);
		unset($this->sumAmount[$cartId]);
		unset($this->sumWeight[$cartId]);
		unset($this->sumPoints[$cartId]);
		unset($this->sumDimension[$cartId]);
	}
	
	/**
	 * @param \Eshop\DB\CartItem $cartItem
	 * @throws \StORM\Exception\NotFoundException
	 */
	private function updateItem(CartItem $cartItem): void
	{
		foreach ($this->productRepository->getCartItemRelations($cartItem, true, false) as $upsell) {
			if ($upsellCartItem = $this->cartItemRepository->getUpsell($cartItem->getPK(), $upsell->getPK())) {
				$upsellCartItem->update([
					'price' => (float) $upsell->getValue('price'),
					'priceVat' => (float) $upsell->getValue('priceVat'),
					'amount' => $upsellCartItem->realAmount ? $upsellCartItem->realAmount * $upsell->getValue('amount') : $upsell->getValue('amount'),
				]);
			}
		}
		
		Arrays::invoke($this->onCartItemUpdate, $cartItem);
	}
}
