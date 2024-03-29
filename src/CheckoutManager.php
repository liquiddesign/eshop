<?php

declare(strict_types=1);

namespace Eshop;

use Carbon\Carbon;
use Eshop\Admin\SettingsPresenter;
use Eshop\Common\CheckInvalidAmount;
use Eshop\Common\IncorrectItemReason;
use Eshop\DB\Address;
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
use Nette;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\SmartObject;
use Nette\Utils\DateTime;
use Security\DB\AccountRepository;
use StORM\Collection;
use StORM\Connection;
use StORM\DIConnection;
use StORM\Exception\NotFoundException;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

/**
 * Služba která zapouzdřuje košíky nakupujícího
 * @method onCustomerCreate(\Eshop\DB\Customer $customer)
 * @method onCartCreate(\Eshop\DB\Cart $cart)
 * @method onOrderCreate(\Eshop\DB\Order $order)
 * @method onCartItemDelete()
 * @method onCartItemCreate(\Eshop\DB\CartItem $cartItem)
 * @method onCartItemUpdate(\Eshop\DB\CartItem $cartItem)
 * @package Eshop
 */
class CheckoutManager
{
	use SmartObject;
	public const DEFAULT_MIN_BUY_COUNT = 1;
	public const DEFAULT_MAX_BUY_COUNT = 999999999;
	
	/**
	 * @var callable[]&callable(\Eshop\DB\Customer): void; Occurs after customer create
	 */
	public $onCustomerCreate;
	
	/**
	 * @var callable[]&callable(\Eshop\DB\Order): void; Occurs after order create
	 */
	public $onOrderCreate;
	
	/**
	 * @var callable[] Occurs after cart create
	 */
	public array $onCartCreate = [];
	
	/**
	 * @var callable[]&callable(): void; Occurs after cart item delete
	 */
	public $onCartItemDelete;
	
	/**
	 * @var array<callable(\Eshop\DB\CartItem): void>; Occurs after cart item create
	 */
	public $onCartItemCreate;
	
	/**
	 * @var callable[]&callable(): void; Occurs after cart item updated
	 */
	public $onCartItemUpdate;
	
	/**
	 * @var array<mixed>|null
	 */
	public ?array $orderCodeArguments = null;
	
	protected ?string $cartToken;

	protected ?string $lastOrderToken;
	
	/**
	 * @var \Eshop\DB\Cart[]|null[]
	 */
	protected array $unattachedCarts = [];

	protected int $cartExpiration = 30;

	protected ?Customer $customer;

	protected Shopper $shopper;

	protected CartRepository $cartRepository;

	protected CartItemRepository $itemRepository;

	protected ProductRepository $productRepository;

	protected DeliveryDiscountRepository $deliveryDiscountRepository;

	protected DeliveryTypeRepository $deliveryTypeRepository;

	protected DeliveryRepository $deliveryRepository;

	protected PaymentTypeRepository $paymentTypeRepository;

	protected PaymentRepository $paymentRepository;

	protected DiscountCouponRepository $discountCouponRepository;

	protected NewsletterUserRepository $newsletterUserRepository;

	protected SettingRepository $settingRepository;

	protected Collection $paymentTypes;

	protected ?float $sumPrice = null;

	protected ?float $sumPriceVat = null;

	protected ?float $sumPriceBefore = null;

	protected ?float $sumPriceVatBefore = null;

	protected ?int $sumAmount = null;

	protected ?int $sumAmountTotal = null;

	protected ?float $sumWeight = null;

	protected ?float $sumDimension = null;

	protected ?int $sumPoints = null;

	protected ?float $maxWeight = null;

	protected ?int $maxDimension = null;

	protected bool $autoFixCart;

	/**
	 * @var bool|null Cached status of discount coupon validity during request
	 */
	protected ?bool $isDiscountCouponValid = null;
	
	/**
	 * @var string[]
	 */
	protected array $checkoutSequence;

	protected OrderRepository $orderRepository;

	protected AccountRepository $accountRepository;

	protected CustomerRepository $customerRepository;

	protected Response $response;

	protected TaxRepository $taxRepository;

	protected CartItemTaxRepository $cartItemTaxRepository;

	protected PackageRepository $packageRepository;

	protected PackageItemRepository $packageItemRepository;

	protected LoyaltyProgramHistoryRepository $loyaltyProgramHistoryRepository;

	protected BannedEmailRepository $bannedEmailRepository;

	protected OrderLogItemRepository $orderLogItemRepository;

	protected CustomerGroupRepository $customerGroupRepository;

	protected CatalogPermissionRepository $catalogPermissionRepository;

	protected AttributeAssignRepository $attributeAssignRepository;

	protected ReviewRepository $reviewRepository;

	protected RelatedCartItemRepository $relatedCartItemRepository;

	protected RelatedPackageItemRepository $relatedPackageItemRepository;

	protected RelatedTypeRepository $relatedTypeRepository;

	protected DIConnection $stm;

	protected PurchaseRepository $purchaseRepository;

	protected PriceRepository $priceRepository;

	public function __construct(
		Shopper $shopper,
		CartRepository $cartRepository,
		CartItemRepository $itemRepository,
		ProductRepository $productRepository,
		DeliveryDiscountRepository $deliveryDiscountRepository,
		PaymentTypeRepository $paymentTypeRepository,
		PaymentRepository $paymentRepository,
		DeliveryTypeRepository $deliveryTypeRepository,
		DeliveryRepository $deliveryRepository,
		OrderRepository $orderRepository,
		AccountRepository $accountRepository,
		CustomerRepository $customerRepository,
		DiscountCouponRepository $discountCouponRepository,
		Request $request,
		Response $response,
		TaxRepository $taxRepository,
		CartItemTaxRepository $cartItemTaxRepository,
		PackageRepository $packageRepository,
		PackageItemRepository $packageItemRepository,
		LoyaltyProgramHistoryRepository $loyaltyProgramHistoryRepository,
		BannedEmailRepository $bannedEmailRepository,
		OrderLogItemRepository $orderLogItemRepository,
		CustomerGroupRepository $customerGroupRepository,
		CatalogPermissionRepository $catalogPermissionRepository,
		AttributeAssignRepository $attributeAssignRepository,
		ReviewRepository $reviewRepository,
		NewsletterUserRepository $newsletterUserRepository,
		SettingRepository $settingRepository,
		RelatedCartItemRepository $relatedCartItemRepository,
		RelatedPackageItemRepository $relatedPackageItemRepository,
		RelatedTypeRepository $relatedTypeRepository,
		DIConnection $stm,
		PurchaseRepository $purchaseRepository,
		PriceRepository $priceRepository
	) {
		$customer = $shopper->getCustomer();
		$this->customer = $customer ? $customerRepository->one($customer->getPK()) : null;
		$this->shopper = $shopper;
		$this->cartRepository = $cartRepository;
		$this->itemRepository = $itemRepository;
		$this->productRepository = $productRepository;
		$this->deliveryDiscountRepository = $deliveryDiscountRepository;
		$this->paymentTypeRepository = $paymentTypeRepository;
		$this->paymentRepository = $paymentRepository;
		$this->deliveryTypeRepository = $deliveryTypeRepository;
		$this->deliveryRepository = $deliveryRepository;
		$this->orderRepository = $orderRepository;
		$this->accountRepository = $accountRepository;
		$this->customerRepository = $customerRepository;
		$this->discountCouponRepository = $discountCouponRepository;
		$this->response = $response;
		$this->taxRepository = $taxRepository;
		$this->cartItemTaxRepository = $cartItemTaxRepository;
		$this->packageRepository = $packageRepository;
		$this->packageItemRepository = $packageItemRepository;
		$this->loyaltyProgramHistoryRepository = $loyaltyProgramHistoryRepository;
		$this->bannedEmailRepository = $bannedEmailRepository;
		$this->orderLogItemRepository = $orderLogItemRepository;
		$this->customerGroupRepository = $customerGroupRepository;
		$this->catalogPermissionRepository = $catalogPermissionRepository;
		$this->attributeAssignRepository = $attributeAssignRepository;
		$this->reviewRepository = $reviewRepository;
		$this->newsletterUserRepository = $newsletterUserRepository;
		$this->settingRepository = $settingRepository;
		$this->relatedCartItemRepository = $relatedCartItemRepository;
		$this->relatedPackageItemRepository = $relatedPackageItemRepository;
		$this->relatedTypeRepository = $relatedTypeRepository;
		$this->stm = $stm;
		$this->purchaseRepository = $purchaseRepository;
		$this->priceRepository = $priceRepository;

		if (!$request->getCookie('cartToken') && !$this->customer) {
			$this->cartToken = DIConnection::generateUuid();
			$response->setCookie('cartToken', $this->cartToken, $this->cartExpiration . ' days');
		} else {
			$this->cartToken = $request->getCookie('cartToken');
		}
		
		if ($this->customer && $this->cartToken) {
			if ($cart = $this->cartRepository->getUnattachedCart($this->cartToken)) {
				$this->handleCartOnLogin($cart, $this->customer);
			}
			
			$response->deleteCookie('cartToken');
			$this->cartToken = null;
		}
		
		$this->lastOrderToken = $request->getCookie('lastOrderToken');
		
		$this->shopper->discountCoupon = $this->getDiscountCoupon();
	}

	public function handleCartOnLogin(Cart $oldCart, Customer $customer): void
	{
		unset($customer);

		$this->addItemsFromCart($oldCart);
		$oldCart->delete();
	}
	
	public function setCheckoutSequence(array $checkoutSequence): void
	{
		$this->checkoutSequence = $checkoutSequence;
	}

	public function setAutoFixCart(bool $value): void
	{
		$this->autoFixCart = $value;
	}
	
	public function getPricelists(?Currency $currency = null, ?DiscountCoupon $discountCoupon = null): Collection
	{
		return $this->shopper->getPricelists($currency, $discountCoupon ?? $this->getDiscountCoupon());
	}

	public function cartExists(): bool
	{
		if ($this->customer) {
			if ($this->customer->activeCart) {
				return true;
			}
			
			$this->customer->activeCart = null;
			
			return false;
		}
		
		if (!\array_key_exists($this->cartToken, $this->unattachedCarts)) {
			$this->unattachedCarts[$this->cartToken] = $this->cartRepository->getUnattachedCart($this->cartToken);
		}
		
		return (bool)$this->unattachedCarts[$this->cartToken];
	}
	
	public function getSumPrice(): float
	{
		if (!$this->cartExists()) {
			return 0.0;
		}
		
		return $this->sumPrice ??= $this->itemRepository->getSumProperty([$this->getCart()->getPK()], 'price');
	}
	
	public function getSumPriceVat(): float
	{
		if (!$this->cartExists()) {
			return 0.0;
		}
		
		return $this->sumPriceVat ??= $this->itemRepository->getSumProperty([$this->getCart()->getPK()], 'priceVat');
	}

	public function getSumPriceBefore(): float
	{
		if (!$this->cartExists()) {
			return 0.0;
		}

		return $this->sumPriceBefore ??= $this->itemRepository->getSumProperty([$this->getCart()->getPK()], 'priceBefore');
	}

	public function getSumPriceVatBefore(): float
	{
		if (!$this->cartExists()) {
			return 0.0;
		}

		return $this->sumPriceVatBefore ??= $this->itemRepository->getSumProperty([$this->getCart()->getPK()], 'priceVatBefore');
	}
	
	public function getSumItems(): int
	{
		if (!$this->cartExists()) {
			return 0;
		}
		
		return $this->sumAmount ??= $this->itemRepository->getSumItems($this->getCart());
	}
	
	public function getSumAmount(): int
	{
		if (!$this->cartExists()) {
			return 0;
		}
		
		return $this->sumAmountTotal ??= (int)$this->itemRepository->getSumProperty([$this->getCart()->getPK()], 'amount');
	}
	
	public function getSumWeight(): float
	{
		if (!$this->cartExists()) {
			return 0.0;
		}
		
		return $this->sumWeight ??= $this->itemRepository->getSumProperty([$this->getCart()->getPK()], 'productWeight');
	}
	
	public function getSumDimension(): float
	{
		if (!$this->cartExists()) {
			return 0.0;
		}
		
		return $this->sumDimension ??= $this->itemRepository->getSumProperty([$this->getCart()->getPK()], 'productDimension');
	}
	
	public function getMaxWeight(): float
	{
		if (!$this->cartExists()) {
			return 0.0;
		}
		
		return $this->maxWeight ??= $this->itemRepository->many()->where('fk_cart', $this->getCart()->getPK())->max('productWeight');
	}
	
	public function getMaxDimension(): int
	{
		if (!$this->cartExists()) {
			return 0;
		}
		
		return $this->maxDimension ??= (int) $this->itemRepository->many()->where('fk_cart', $this->getCart()->getPK())->max('GREATEST(productWidth,productLength,productDepth)');
	}
	
	public function getSumPoints(): int
	{
		if (!$this->cartExists()) {
			return 0;
		}
		
		return $this->sumPoints ??= (int)$this->itemRepository->getSumProperty([$this->getCart()->getPK()], 'pts');
	}
	
	public function getCartCurrency(): ?Currency
	{
		return $this->cartExists() ? $this->getCart()->currency : null;
	}
	
	public function getCartCurrencyCode(): ?string
	{
		return $this->cartExists() ? $this->getCart()->currency->code : null;
	}
	
	/**
	 * @return \Eshop\DB\Cart[]
	 */
	public function getAvailableCarts(): array
	{
		//@TODO: vrátí asociativní pole všech košíku číslo => cart
		return [];
	}
	
	public function changeCart(int $id): void
	{
		unset($id);
		// @TODO: zmeni aktivni kosik
	}
	
	public function createCart(int $id = 1, bool $activate = true): Cart
	{
		$cart = $this->cartRepository->createOne([
			'uuid' => $this->customer ? null : $this->cartToken,
			'id' => $id,
			'active' => $activate,
			'customer' => $this->customer ?: null,
			'currency' => $this->shopper->getCurrency(),
			'expirationTs' => $this->customer ? null : (string)new DateTime('+' . $this->cartExpiration . ' days'),
		]);
		
		$this->customer ? $this->customer->update(['activeCart' => $cart]) : $this->unattachedCarts[$this->cartToken] = $cart;
		
		if (isset($this->onCartCreate)) {
			Nette\Utils\Arrays::invoke($this->onCartCreate, $cart);
		}
		
		return $cart;
	}
	
	public function canBuyProduct(Product $product): bool
	{
		return !$product->unavailable && $product->getValue('price') !== null && $this->shopper->getBuyPermission();
	}
	
	public function disallowItemInCart(CartItem $item): void
	{
		$item->update(['product' => null]);
	}
	
	public function updateItemInCart(CartItem $item, Product $product, ?Variant $variant = null, int $amount = 1, bool $checkInvalidAmount = true, bool $checkCanBuy = true): void
	{
		if (!$this->checkCurrency($product)) {
			throw new BuyException('Invalid currency', BuyException::INVALID_CURRENCY);
		}
		
		if ($checkCanBuy && !$this->canBuyProduct($product)) {
			throw new BuyException('Product is not for sell', BuyException::NOT_FOR_SELL);
		}
		
		if ($checkInvalidAmount && !$this->checkAmount($product, $amount)) {
			throw new BuyException("Invalid amount: $amount", BuyException::INVALID_AMOUNT);
		}
		
		$this->itemRepository->syncItem($this->getCart(), $item, $product, $variant, $amount);
	}
	
	/**
	 * @param \Eshop\DB\Product $product Must have set prices
	 * @param \Eshop\DB\Variant|null $variant
	 * @param int $amount
	 * @param ?bool $replaceMode true - replace | false - add or update | null - only add
	 * @param bool|null|\Eshop\Common\CheckInvalidAmount::SET_DEFAULT_AMOUNT $checkInvalidAmount null - check, but do not throw
	 *                                                                                           false - dont check | true - check and throw
	 *                                                                                           constants - see CheckInvalidAmount
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
		bool | int | null $checkInvalidAmount = true,
		?bool $checkCanBuy = true,
		?Cart $cart = null,
		?CartItem $upsell = null
	): CartItem {
		if (!$this->checkCurrency($product)) {
			throw new BuyException('Invalid currency', BuyException::INVALID_CURRENCY);
		}
		
		if ($checkCanBuy !== false && !$this->shopper->getBuyPermission()) {
			throw new BuyException('Permission denied', BuyException::PERMISSION_DENIED);
		}
		
		$disabled = false;
		
		if ($checkCanBuy !== false && !$this->canBuyProduct($product)) {
			if ($checkCanBuy === true) {
				throw new BuyException('Product is not for sell', BuyException::NOT_FOR_SELL);
			}
			
			$disabled = true;
		}

		if (\is_null($checkInvalidAmount) || \is_bool($checkInvalidAmount)) {
			if ($checkInvalidAmount !== false && !$this->checkAmount($product, $amount)) {
				if ($checkInvalidAmount === true) {
					throw new BuyException("Invalid amount: $amount", BuyException::INVALID_AMOUNT);
				}

				$disabled = true;
			}
		} elseif (\is_int($checkInvalidAmount)) {
			if ($checkInvalidAmount === CheckInvalidAmount::SET_DEFAULT_AMOUNT) {
				$amount = $this->checkAmount($product, $amount) ? $amount : $product->defaultBuyCount;
			}
		}
		
		if ($replaceMode !== null && $item = $this->itemRepository->getItem($cart ?? $this->getCart(), $product, $variant)) {
			$this->changeItemAmount($product, $variant, $replaceMode ? $amount : $item->amount + $amount, $checkInvalidAmount, $cart);
			
			if ($this->onCartItemCreate) {
				$this->onCartItemCreate($item);
			}
			
			return $item;
		}
		
		$cartItem = $this->itemRepository->syncItem($cart ?? $this->getCart(), null, $product, $variant, $amount, $disabled);
		
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
		
		if ($this->onCartItemCreate) {
			$this->onCartItemCreate($cartItem);
		}
		
		return $cartItem;
	}
	
	/**
	 * @param \Eshop\DB\CartItem $cartItem
	 * @param \Eshop\DB\Product $upsell Must be processed by getCartItemRelations()
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function addUpsellToCart(CartItem $cartItem, Product $upsell, ?int $realAmount = null): CartItem
	{
		$existingUpsell = $this->itemRepository->getUpsellByObjects($cartItem, $upsell);
		
		if ($existingUpsell) {
			$realAmount = $realAmount && $existingUpsell->realAmount ? $existingUpsell->realAmount + $realAmount : null;
			
			$existingUpsell->update([
				'price' => (float) $upsell->getValue('price'),
				'priceVat' => (float) $upsell->getValue('priceVat'),
				'amount' => $realAmount ? $realAmount * $cartItem->amount : $upsell->getValue('amount'),
				'realAmount' => $realAmount,
			]);
			
			return $this->itemRepository->getUpsellByObjects($cartItem, $upsell);
		}
		
		/** @var \Eshop\DB\VatRateRepository $vatRepo */
		$vatRepo = $this->itemRepository->getConnection()->findRepository(VatRate::class);
		/** @var \Eshop\DB\VatRate|null $vat */
		$vat = $vatRepo->one($upsell->vatRate);
		
		$vatPct = $vat ? $vat->rate : 0;
		$amount = $realAmount ? $realAmount * $cartItem->amount : $upsell->getValue('amount');
		
		return $this->itemRepository->createOne([
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
	public function changeItemAmount(Product $product, ?Variant $variant = null, int $amount = 1, ?bool $checkInvalidAmount = true, ?Cart $cart = null): void
	{
		if ($checkInvalidAmount && !$this->checkAmount($product, $amount)) {
			throw new BuyException("Invalid amount: $amount", BuyException::INVALID_AMOUNT);
		}
		
		$this->itemRepository->updateItemAmount($cart ?: $this->getCart(), $variant, $product, $amount);
		$this->refreshSumProperties();
		
		if (!($cartItem = $this->itemRepository->getItem($cart ?: $this->getCart(), $product, $variant))) {
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
		
		$this->itemRepository->updateCartItemAmount($cartItem, $product, $amount);
		$this->refreshSumProperties();
		
		if (!($cartItem = $this->itemRepository->one($cartItem->getPK()))) {
			return;
		}
		
		$this->updateItem($cartItem);
	}
	
	public function deleteItem(CartItem $item): void
	{
		$this->cartItemTaxRepository->many()->where('fk_cartItem', $item->getPK())->delete();
		
		$this->itemRepository->deleteItem($this->getCart(), $item);
		
		if (!$this->getSumItems()) {
			$this->deleteCart();
		} else {
			$this->refreshSumProperties();
		}
		
		$this->onCartItemDelete();
	}
	
	public function deleteCart(): void
	{
		$this->cartItemTaxRepository->many()->where('fk_cartItem', \array_keys($this->getItems()->toArray()))->delete();
		
		$this->cartRepository->deleteCart($this->getCart());
		
		if ($this->customer) {
			$this->customer->activeCart = null;
		} else {
			unset($this->unattachedCarts[$this->cartToken]);
		}
		
		$this->refreshSumProperties();
	}
	
	public function changeItemNote(Product $product, ?Variant $variant = null, ?string $note = null): void
	{
		$this->itemRepository->updateNote($this->getCart(), $product, $variant, $note);
	}
	
	public function getItems(): Collection
	{
		return $this->cartExists() ? $this->itemRepository->getItems([$this->getCart()->getPK()]) : $this->itemRepository->many()->where('1=0');
	}
	
	public function getTopLevelItems(): Collection
	{
		return $this->getItems()->where('this.fk_upsell IS NULL');
	}
	
	public function addItemsFromCart(Cart $cart, bool $required = false): bool
	{
		$ids = $this->itemRepository->getItems([$cart->getPK()])->where('this.fk_product IS NOT NULL')->setSelect(['aux' => 'this.fk_product'])->toArrayOf('aux');
		
		/** @var \Eshop\DB\Product[] $products */
		$products = $this->productRepository->getProducts()->where('this.uuid', $ids)->toArray();
		
		$upsellsMap = [];
		$nonUpsellItems = $this->itemRepository->getItems([$cart->getPK()])->where('this.fk_upsell IS NULL')->toArray();
		
		$someProductNotFound = false;
		
		/** @var \Eshop\DB\CartItem $item */
		foreach ($nonUpsellItems as $item) {
			if (!isset($products[$item->getValue('product')])) {
				if ($required) {
					throw new BuyException('product not found');
				}
				
				$someProductNotFound = true;
				
				continue;
			}
			
			$newItem = $this->addItemToCart($products[$item->getValue('product')], $item->variant, $item->amount, null, false, $required);
			
			$upsellsMap[$item->getPK()] = $newItem;
		}
		
		$relations = $this->productRepository->getCartItemsRelations($nonUpsellItems, false, false);
		
		/** @var \Eshop\DB\CartItem $item */
		foreach ($this->itemRepository->getItems([$cart->getPK()])->where('this.fk_upsell IS NOT NULL') as $item) {
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
		return $this->paymentTypes ??= $this->paymentTypeRepository->getPaymentTypes($this->shopper->getCurrency(), $this->customer, $this->shopper->getCustomerGroup());
	}
	
	/**
	 * @return array<\Eshop\DB\DeliveryType>
	 */
	public function getDeliveryTypesProcessed(bool $vat): array
	{
		$deliveryTypes = $this->deliveryTypeRepository->getDeliveryTypes(
			$this->shopper->getCurrency(),
			$this->customer,
			$this->shopper->getCustomerGroup(),
			$this->getDeliveryDiscount($vat),
			$this->getMaxWeight(),
			$this->getMaxDimension(),
			$this->getSumWeight(),
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
			$this->shopper->getCurrency(),
			$this->customer,
			$this->shopper->getCustomerGroup(),
			$this->getDeliveryDiscount($vat),
			$this->getMaxWeight(),
			$this->getMaxDimension(),
			$this->getSumWeight(),
		);
	}
	
	public function checkDiscountCoupon(): bool
	{
		/** @var \Eshop\DB\DiscountCoupon|null $discountCoupon */
		$discountCoupon = $this->getDiscountCoupon();
		
		if ($discountCoupon === null) {
			return true;
		}

		if ($this->isDiscountCouponValid !== null) {
			return $this->isDiscountCouponValid;
		}

		$this->setDiscountCoupon(null);
		$this->shopper->discountCoupon = null;
		$this->fixCartItems();
		
		$valid = (bool)$this->discountCouponRepository->getValidCouponByCart($discountCoupon->code, $this->getCart(), $discountCoupon->exclusiveCustomer);

		$this->setDiscountCoupon($discountCoupon);
		$this->shopper->discountCoupon = $discountCoupon;
		$this->fixCartItems();

		$this->isDiscountCouponValid = $valid;

		return $valid;
	}
	
	public function checkOrder(): bool
	{
		return $this->checkCart();
	}
	
	public function checkCart(): bool
	{
		if (!\boolval(\count($this->getItems()))) {
			return false;
		}
		
		if (!$this->checkDiscountCoupon()) {
			return false;
		}
		
		return !\count($this->getIncorrectCartItems());
	}

	/**
	 * Fix cart if it is allowed by config
	 */
	public function autoFixCart(): void
	{
		if (!$this->autoFixCart) {
			return;
		}

		$this->fixCart();
	}

	/**
	 * Fix cart
	 */
	public function fixCart(): void
	{
		if (!$this->checkDiscountCoupon()) {
			$this->setDiscountCoupon(null);
		}

		$this->fixCartItems();
	}

	/**
	 * Fix cart
	 */
	public function fixCartItems(): void
	{
		$incorrectItems = $this->getIncorrectCartItems();

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
	public function getIncorrectCartItems(): array
	{
		$incorrectItems = [];
		
		/** @var \Eshop\DB\CartItem $cartItem */
		foreach ($this->getItems() as $cartItem) {
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
					$correctAmount = $this->itemRepository->roundUpToNextMultiple($cartItem->amount, $cartItem->product->buyStep);
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
	public function isStepAllowed(string $step): bool
	{
		$sequence = \array_search($step, $this->checkoutSequence);
		$previousStep = $this->checkoutSequence[$sequence - 1] ?? null;
		
		if ($previousStep === 'cart') {
			return $this->getPurchase() && (bool)\count($this->getItems()) && \count($this->getIncorrectCartItems()) === 0 && $this->checkDiscountCoupon();
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
	 * @return bool[]
	 */
	public function getCheckoutSteps(): array
	{
		$steps = [];
		
		foreach ($this->checkoutSequence as $step) {
			$steps[$step] = $this->isStepAllowed($step);
		}
		
		return $steps;
	}
	
	public function getMaxStep(): ?string
	{
		$lastStep = null;
		
		foreach ($this->checkoutSequence as $step) {
			if (!$this->isStepAllowed($step)) {
				break;
			}
			
			$lastStep = $step;
		}
		
		return $lastStep;
	}
	
	public function getCheckoutPrice(): float
	{
		$price = $this->getSumPrice() + $this->getDeliveryPrice() + $this->getPaymentPrice() - $this->getDiscountPrice();
		
		return $price ?: 0.0;
	}
	
	public function getCheckoutPriceVat(): float
	{
		$priceVat = $this->getSumPriceVat() + $this->getDeliveryPriceVat() + $this->getPaymentPriceVat() - $this->getDiscountPriceVat();
		
		return $priceVat ?: 0.0;
	}
	
	public function getCartCheckoutPrice(): float
	{
		$price = $this->getSumPrice() - $this->getDiscountPrice();
		
		return $price ?: 0.0;
	}
	
	public function getCartCheckoutPriceVat(): float
	{
		$priceVat = $this->getSumPriceVat() - $this->getDiscountPriceVat();
		
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
	
	public function getPaymentPrice(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			$paymentType = $this->getPaymentTypes()[$this->getPurchase()->getValue('paymentType')] ?? null;
			
			if (!$paymentType) {
				return 0.0;
			}
			
			$price = $paymentType->getValue('price');
			
			return isset($price) ? (float)$price : 0.0;
		}
		
		return 0.0;
	}
	
	public function getPaymentPriceVat(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			$paymentType = $this->getPaymentTypes()[$this->getPurchase()->getValue('paymentType')] ?? null;
			
			if (!$paymentType) {
				return 0.0;
			}
			
			$price = $paymentType->getValue('priceVat');
			
			return isset($price) ? (float)$price : 0.0;
		}
		
		return 0.0;
	}
	
	public function getDeliveryPrice($includePackagesNo = true): float
	{
		if ($this->getPurchase() && $this->getPurchase()->deliveryType) {
			$deliveryPackagesNo = $includePackagesNo ? $this->getPurchase(true)->deliveryPackagesNo : 1;
			$showPrice = $this->shopper->getShowPrice();

			try {
				$price = $this->getDeliveryTypes($showPrice === 'withVat')[$this->getPurchase()->getValue('deliveryType')]->getValue('price');

				return isset($price) ? (float)$price * $deliveryPackagesNo : 0.0;
			} catch (NotFoundException $e) {
				$this->getPurchase()->update(['deliveryType' => null]);

				return 0.0;
			}
		}
		
		return 0.0;
	}
	
	public function getDeliveryPriceVat($includePackagesNo = true): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			$deliveryPackagesNo = $includePackagesNo ? $this->getPurchase(true)->deliveryPackagesNo : 1;
			$showPrice = $this->shopper->getShowPrice();

			try {
				$price = $this->getDeliveryTypes($showPrice === 'withVat')[$this->getPurchase()->getValue('deliveryType')]->getValue('priceVat');

				return isset($price) ? (float)$price * $deliveryPackagesNo : 0.0;
			} catch (NotFoundException $e) {
				$this->getPurchase()->update(['deliveryType' => null]);

				return 0.0;
			}
		}
		
		return 0.0;
	}
	
	public function getDeliveryPriceBefore($includePackagesNo = true): ?float
	{
		if ($this->getPurchase() && $this->getPurchase()->deliveryType) {
			$deliveryPackagesNo = $includePackagesNo ? $this->getPurchase(true)->deliveryPackagesNo : 1;
			$showPrice = $this->shopper->getShowPrice();
			$price = $this->getDeliveryTypes($showPrice === 'withVat')[$this->getPurchase()->getValue('deliveryType')]->getValue('priceBefore');

			return isset($price) ? (float)$price * $deliveryPackagesNo : null;
		}
		
		return null;
	}
	
	public function getDeliveryPriceVatBefore($includePackagesNo = true): ?float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			$deliveryPackagesNo = $includePackagesNo ? $this->getPurchase(true)->deliveryPackagesNo : 1;
			$showPrice = $this->shopper->getShowPrice();
			$price = $this->getDeliveryTypes($showPrice === 'withVat')[$this->getPurchase()->getValue('deliveryType')]->getValue('priceBeforeVat');
			
			return isset($price) ? (float)$price * $deliveryPackagesNo : null;
		}
		
		return null;
	}
	
	public function getDeliveryDiscount(bool $vat = false): ?DeliveryDiscount
	{
		$currency = $this->cartExists() ? $this->getCart()->currency : $this->shopper->getCurrency();
		
		return $this->deliveryDiscountRepository->getActiveDeliveryDiscount($currency, $vat ? $this->getCartCheckoutPriceVat() : $this->getCartCheckoutPrice(), $this->getSumWeight());
	}
	
	public function getPossibleDeliveryDiscount(bool $vat = false): ?DeliveryDiscount
	{
		$currency = $this->cartExists() ? $this->getCart()->currency : $this->shopper->getCurrency();
		
		return $this->deliveryDiscountRepository->getNextDeliveryDiscount($currency, $vat ? $this->getCartCheckoutPriceVat() : $this->getCartCheckoutPrice(), $this->getSumWeight());
	}
	
	public function getPriceLeftToNextDeliveryDiscount(): ?float
	{
		return $this->getPossibleDeliveryDiscount() ? $this->getPossibleDeliveryDiscount()->discountPriceFrom - $this->getCartCheckoutPrice() : null;
	}
	
	public function getPriceVatLeftToNextDeliveryDiscount(): ?float
	{
		return $this->getPossibleDeliveryDiscount(true) ? $this->getPossibleDeliveryDiscount(true)->discountPriceFrom - $this->getCartCheckoutPriceVat() : null;
	}
	
	public function getDeliveryDiscountProgress(): ?float
	{
		return $this->getPossibleDeliveryDiscount() ? $this->getCartCheckoutPrice() / $this->getPossibleDeliveryDiscount()->discountPriceFrom * 100 : null;
	}
	
	public function getDeliveryDiscountProgressVat(): ?float
	{
		return $this->getPossibleDeliveryDiscount(true) ? $this->getCartCheckoutPriceVat() / $this->getPossibleDeliveryDiscount(true)->discountPriceFrom * 100 : null;
	}
	
	public function getDiscountCoupon(): ?DiscountCoupon
	{
		if (!$this->cartExists() || !$this->getPurchase()) {
			return null;
		}
		
		return $this->getPurchase()->coupon;
	}
	
	public function setDiscountCoupon(?DiscountCoupon $coupon): void
	{
		if (!$this->getPurchase()) {
			$purchase = $this->syncPurchase([]);
		}
		
		($purchase ?? $this->getPurchase())->update(['coupon' => $coupon]);

		$this->isDiscountCouponValid = null;
	}

	public function getDiscountPrice(): float
	{
		if ($coupon = $this->getDiscountCoupon()) {
			return \floatval($coupon->discountValue);
		}
		
		return 0.0;
	}

	public function getDiscountPriceVat(): float
	{
		if ($coupon = $this->getDiscountCoupon()) {
			return \floatval($coupon->discountValueVat);
		}

		return 0.0;
	}
	
	public function checkAmount(Product $product, $amount): bool
	{
		return !($amount < $product->minBuyCount || ($product->maxBuyCount !== null && $amount > $product->maxBuyCount));
		
		//		|| ($product->buyStep && $amount % $product->buyStep !== 0)
		//		$min = $product->minBuyCount ?? self::DEFAULT_MIN_BUY_COUNT;
		//		$max = $product->maxBuyCount ?? self::DEFAULT_MAX_BUY_COUNT;
		//
		//		return !($amount < $min || $amount > $max || ($amount && $product->buyStep && (($amount + $min - 1) % $product->buyStep !== 0)));
	}
	
	public function checkCartItemPrice(CartItem $cartItem): bool
	{
		$productPrice = $this->productRepository->getProduct($cartItem->product->getPK())->getPrice((int)$cartItem->amount);
		
		return \floatval($productPrice) === $cartItem->price;
	}
	
	public function getLastOrder(): ?Order
	{
		return $this->lastOrderToken ? $this->orderRepository->one($this->lastOrderToken) : null;
	}
	
	/**
	 * @param mixed $values
	 */
	public function syncPurchase($values): Purchase
	{
		if (!$this->getCart()->getValue('purchase')) {
			$values['currency'] = $this->getCart()->getValue('currency');
		}
		
		/** @var \Eshop\DB\Purchase $purchase */
		$purchase = $this->getCart()->syncRelated('purchase', $values);
		
		return $purchase;
	}
	
	public function getPurchase(bool $needed = false): ?Purchase
	{
		$purchase = $this->getCart()->purchase;
		
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
			if (!$this->accountRepository->many()->match(['login' => $purchase->email])->isEmpty()) {
				return null;
			}
			
			/** @var \Security\DB\Account $account */
			$account = $this->accountRepository->createOne([
				'uuid' => Connection::generateUuid(),
				'login' => $purchase->email,
				'password' => $purchase->password,
				'active' => !$this->shopper->getRegistrationConfiguration()['confirmation'],
				'authorized' => !$this->shopper->getRegistrationConfiguration()['emailAuthorization'],
				'confirmationToken' => $this->shopper->getRegistrationConfiguration()['emailAuthorization'] ? Nette\Utils\Random::generate(128) : null,
			]);
		}
		
		$customer = $this->customerRepository->one(['email' => $purchase->email]);
		$defaultGroup = $this->customerGroupRepository->getDefaultRegistrationGroup();
		
		$customerValues = [
			'email' => $purchase->email,
			'fullname' => $purchase->fullname,
			'phone' => $purchase->phone,
			'ic' => $purchase->ic,
			'dic' => $purchase->dic,
			'billAddress' => $purchase->billAddress,
			'deliveryAddress' => $purchase->deliveryAddress,
			'group' => $defaultGroup ? $defaultGroup->getPK() : null,
			'discountLevelPct' => $defaultGroup ? $defaultGroup->defaultDiscountLevelPct : 0,
		];
		
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
		
		if ($defaultGroup && \count($defaultGroup->defaultPricelists->toArray()) > 0) {
			$customer->pricelists->relate(\array_keys($defaultGroup->defaultPricelists->toArray()));
		}
		
		return $customer;
	}
	
	/**
	 * @param \Eshop\DB\Purchase|null $purchase
	 * @param array<string|int|float|null> $defaultOrderValues
	 * @throws \Eshop\BuyException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function createOrder(?Purchase $purchase = null, array $defaultOrderValues = []): Order
	{
		/** @var \Eshop\DB\VatRateRepository $vatRepo */
		$vatRepo = $this->itemRepository->getConnection()->findRepository(VatRate::class);
		
		$purchase = $purchase ?: $this->getPurchase();
		
		$banned = $this->bannedEmailRepository->isEmailBanned($purchase->email);
		
		if (!$this->shopper->getAllowBannedEmailOrder() && $banned) {
			throw new BuyException('Banned email', BuyException::BANNED_EMAIL);
		}
		
		$discountCoupon = $this->getDiscountCoupon();
		
		if ($discountCoupon && $discountCoupon->usageLimit && $discountCoupon->usagesCount >= $discountCoupon->usageLimit) {
			throw new BuyException('Coupon invalid!', BuyException::INVALID_COUPON);
		}
		
		$customer = $this->shopper->getCustomer();
		$cart = $this->getCart();
		$currency = $cart->currency;
		
		$this->stm->getLink()->beginTransaction();
		
		if ($customer) {
			$purchase->update(['customerDiscountLevel' => $this->productRepository->getBestDiscountLevel($customer)]);
		}
		
		$cart->update(['approved' => ($customer && $customer->orderPermission === 'full') || !$customer ? 'yes' : 'waiting']);
		
		// create customer
		if (!$customer) {
			if ($purchase->createAccount) {
				$customer = $this->createCustomer($purchase);
				
				if ($customer) {
					$purchase = $this->syncPurchase(['customer' => $customer->getPK()]);
					
					$this->onCustomerCreate($customer);
				}
			} elseif ($this->shopper->isAlwaysCreateCustomerOnOrderCreated()) {
				$customer = $this->createCustomer($purchase, false);
				
				$purchase = $this->syncPurchase(['customer' => $customer ? $customer->getPK() : $this->customerRepository->many()->match(['email' => $purchase->email])->first()]);
			}
		}
		
		$year = \date('Y');
		$code = \vsprintf(
			$this->shopper->getCountry()->orderCodeFormat,
			$this->orderCodeArguments ??
			[$this->orderRepository->many()->where('YEAR(this.createdTs)', $year)->enum() + $this->shopper->getCountry()->orderCodeStartNumber, $year],
		);
		
		$orderValues = $defaultOrderValues + [
			'code' => $code,
			'purchase' => $purchase,
		];
		
		$orderValues['receivedTs'] = $this->shopper->getEditOrderAfterCreation() ? null : (string)new DateTime();
		$orderValues['newCustomer'] = !$purchase->customer || $this->orderRepository->many()
				->join(['purchase' => 'eshop_purchase'], 'purchase.uuid = this.fk_purchase')
				->where('purchase.fk_customer', $purchase->customer->getPK())
				->count() === 0;
		
		if ($this->shopper->getAllowBannedEmailOrder() && $banned) {
			$orderValues['bannedTs'] = (string)new DateTime();
		}
		
		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->createOne($orderValues);
		
		//@todo getDeliveryPrice se pocita z aktulaniho purchase ne z parametru a presunout do order repository jako create order
		if ($purchase->deliveryType) {
			$topLevelItems = $this->getTopLevelItems()->toArray();
			$boxList = $purchase->deliveryType->getBoxesForItems($topLevelItems);
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
					'typeName' => $purchase->deliveryType->toArray()['name'],
					'typeCode' => $purchase->deliveryType->code,
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
							continue;
						}
						
						$product = $slaveProducts[$relatedProduct->getValue('slave')];
						
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
					
					if (!$relatedCartItems) {
						continue;
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
		
		$this->response->setCookie('lastOrderToken', $order->getPK(), '1 hour');
		
		if (!$this->customer) {
			$this->cartToken = DIConnection::generateUuid();
			$this->response->setCookie('cartToken', $this->cartToken, $this->cartExpiration . ' days');
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
		
		if ($purchase->sendNewsletters) {
			$this->newsletterUserRepository->syncOne([
				'email' => $purchase->email,
				'customerAccount' => $customer && $customer->account ? $customer->account->getPK() : null,
			], null, false, true);
		}
		
		$this->orderLogItemRepository->createLog($order, OrderLogItem::CREATED);
		
		$this->createCart();
		
		$this->refreshSumProperties();
		
		$this->stm->getLink()->commit();

		$this->reviewRepository->createReviewsFromOrder($order);
		
		$this->onOrderCreate($order);
		
		return $order;
	}
	
	/**
	 * @param \Eshop\DB\Customer $customer
	 * @deprecated TODO: redesign to $this->shooper->getCustomer();
	 */
	public function setCustomer(Customer $customer): void
	{
		$this->customer = $customer;
	}
	
	public function getCart(): Cart
	{
		if (!$this->cartExists()) {
			return $this->createCart();
		}
		
		return $this->customer ? $this->customer->activeCart : $this->unattachedCarts[$this->cartToken];
	}
	
	public function getAttributeNumericSumOfItemsInCart(Attribute $attribute): ?float
	{
		$sum = 0;
		
		/** @var \Eshop\DB\CartItem $item
		 */
		foreach ($this->getItems() as $item) {
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
	
	private function getProductRoundAmount(int $amount, Product $product): int
	{
		if (!$this->shopper->getCustomer() || !$this->shopper->getCustomer()->productRoundingPct) {
			return $amount;
		}
		
		$prAmount = $amount * (1 + ($this->shopper->getCustomer()->productRoundingPct / 100));
		
		if ($product->inPalett > 0) {
			$newAmount = $this->itemRepository->roundUpToProductRoundAmount($amount, $prAmount, $product->inPalett);
			
			if ($amount !== $newAmount) {
				return $newAmount;
			}
		}
		
		if ($product->inCarton > 0) {
			$newAmount = $this->itemRepository->roundUpToProductRoundAmount($amount, $prAmount, $product->inCarton);
			
			if ($amount !== $newAmount) {
				return $newAmount;
			}
		}
		
		if ($product->inPackage > 0) {
			$newAmount = $this->itemRepository->roundUpToProductRoundAmount($amount, $prAmount, $product->inPackage);
			
			if ($amount !== $newAmount) {
				return $newAmount;
			}
		}
		
		return $amount;
	}
	
	private function checkCurrency(Product $product): bool
	{
		return $product->getValue('currencyCode') === $this->getCart()->currency->code;
	}
	
	private function refreshSumProperties(): void
	{
		$this->sumPrice = null;
		$this->sumPriceVat = null;
		$this->sumAmountTotal = null;
		$this->sumAmount = null;
		$this->sumWeight = null;
		$this->sumPoints = null;
		$this->sumDimension = null;
	}
	
	/**
	 * @param \Eshop\DB\CartItem $cartItem
	 * @throws \StORM\Exception\NotFoundException
	 */
	private function updateItem(CartItem $cartItem): void
	{
		foreach ($this->productRepository->getCartItemRelations($cartItem, true, false) as $upsell) {
			if ($upsellCartItem = $this->itemRepository->getUpsell($cartItem->getPK(), $upsell->getPK())) {
				$upsellCartItem->update([
					'price' => (float)$upsell->getValue('price'),
					'priceVat' => (float)$upsell->getValue('priceVat'),
					'amount' => $upsellCartItem->realAmount ? $upsellCartItem->realAmount * $upsell->getValue('amount') : $upsell->getValue('amount'),
				]);
			}
		}
		
		$this->onCartItemUpdate($cartItem);
	}
}
