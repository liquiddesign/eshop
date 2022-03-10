<?php

declare(strict_types=1);

namespace Eshop;

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
use Eshop\DB\Order;
use Eshop\DB\OrderLogItem;
use Eshop\DB\OrderLogItemRepository;
use Eshop\DB\OrderRepository;
use Eshop\DB\PackageItemRepository;
use Eshop\DB\PackageRepository;
use Eshop\DB\PaymentRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\Purchase;
use Eshop\DB\TaxRepository;
use Eshop\DB\Variant;
use Nette;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\SmartObject;
use Nette\Utils\DateTime;
use Security\DB\AccountRepository;
use StORM\Collection;
use StORM\Connection;
use StORM\DIConnection;

/**
 * Služba která zapouzdřuje košíky nakupujícího
 * @method onCustomerCreate(\Eshop\DB\Customer $customer)
 * @method onCartCreate(\Eshop\DB\Cart $cart)
 * @method onOrderCreate(\Eshop\DB\Order $order)
 * @method onCartItemDelete()
 * @method onCartItemCreate(\Eshop\DB\CartItem $cartItem)
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

	private ?string $cartToken;

	private ?string $lastOrderToken;

	/**
	 * @var \Eshop\DB\Cart[]|null[]
	 */
	private array $unattachedCarts = [];

	private int $cartExpiration = 30;

	private ?Customer $customer;

	private Shopper $shopper;

	private CartRepository $cartRepository;

	private CartItemRepository $itemRepository;

	private ProductRepository $productRepository;

	private DeliveryDiscountRepository $deliveryDiscountRepository;

	private DeliveryTypeRepository $deliveryTypeRepository;

	private DeliveryRepository $deliveryRepository;

	private PaymentTypeRepository $paymentTypeRepository;

	private PaymentRepository $paymentRepository;

	private DiscountCouponRepository $discountCouponRepository;

	private Collection $paymentTypes;

	private ?float $sumPrice = null;

	private ?float $sumPriceVat = null;

	private ?int $sumAmount = null;

	private ?int $sumAmountTotal = null;

	private ?float $sumWeight = null;

	private ?float $sumDimension = null;

	private ?int $sumPoints = null;

	/**
	 * @var string[]
	 */
	private array $checkoutSequence;

	private OrderRepository $orderRepository;

	private AccountRepository $accountRepository;

	private CustomerRepository $customerRepository;

	private Response $response;

	private TaxRepository $taxRepository;

	private CartItemTaxRepository $cartItemTaxRepository;

	private PackageRepository $packageRepository;

	private PackageItemRepository $packageItemRepository;

	private LoyaltyProgramHistoryRepository $loyaltyProgramHistoryRepository;

	private BannedEmailRepository $bannedEmailRepository;

	private OrderLogItemRepository $orderLogItemRepository;

	private Nette\Security\Passwords $passwords;

	private CustomerGroupRepository $customerGroupRepository;

	private CatalogPermissionRepository $catalogPermissionRepository;

	private AttributeAssignRepository $attributeAssignRepository;

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
		Nette\Security\Passwords $passwords,
		CustomerGroupRepository $customerGroupRepository,
		CatalogPermissionRepository $catalogPermissionRepository,
		AttributeAssignRepository $attributeAssignRepository
	) {
		$this->customer = $shopper->getCustomer();
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
		$this->passwords = $passwords;
		$this->customerGroupRepository = $customerGroupRepository;
		$this->catalogPermissionRepository = $catalogPermissionRepository;
		$this->attributeAssignRepository = $attributeAssignRepository;

		if (!$request->getCookie('cartToken') && !$this->customer) {
			$this->cartToken = DIConnection::generateUuid();
			$response->setCookie('cartToken', $this->cartToken, $this->cartExpiration . ' days');
		} else {
			$this->cartToken = $request->getCookie('cartToken');
		}

		if ($this->customer && $this->cartToken) {
			if ($cart = $this->cartRepository->getUnattachedCart($this->cartToken)) {
				$this->addItemsFromCart($cart);
				$cart->delete();
			}

			$response->deleteCookie('cartToken');
			$this->cartToken = null;
		}

		$this->lastOrderToken = $request->getCookie('lastOrderToken');

		$this->onCartCreate[] = function ($cart): void {
			$this->shopper->setDiscountCoupon($this->getDiscountCoupon());
		};
	}

	public function setCheckoutSequence(array $checkoutSequence): void
	{
		$this->checkoutSequence = $checkoutSequence;
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
			throw new BuyException('Invalid amount', BuyException::INVALID_AMOUNT);
		}

		$this->itemRepository->syncItem($this->getCart(), $item, $product, $variant, $amount);
	}

	/**
	 * @param \Eshop\DB\Product $product
	 * @param \Eshop\DB\Variant|null $variant
	 * @param int $amount
	 * @param bool $replaceMode
	 * @param ?bool $checkInvalidAmount
	 * @param ?bool $checkCanBuy
	 * @param \Eshop\DB\Cart|null $cart
	 * @throws \Eshop\BuyException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function addItemToCart(
		Product $product,
		?Variant $variant = null,
		int $amount = 1,
		bool $replaceMode = false,
		?bool $checkInvalidAmount = true,
		?bool $checkCanBuy = true,
		?Cart $cart = null
	): CartItem {
		if (!$this->checkCurrency($product)) {
			throw new BuyException('Invalid currency', BuyException::INVALID_CURRENCY);
		}

		if (!$this->shopper->getBuyPermission()) {
			throw new BuyException('Permission denied', BuyException::PERMISSION_DENIED);
		}

		$disabled = false;

		if ($checkCanBuy !== false && !$this->canBuyProduct($product)) {
			if ($checkCanBuy === true) {
				throw new BuyException('Product is not for sell', BuyException::NOT_FOR_SELL);
			}

			$disabled = true;
		}

		if ($checkInvalidAmount !== false && !$this->checkAmount($product, $amount)) {
			if ($checkInvalidAmount === true) {
				throw new BuyException('Invalid amount', BuyException::INVALID_AMOUNT);
			}

			$disabled = true;
		}

		if ($item = $this->itemRepository->getItem($cart ?? $this->getCart(), $product, $variant)) {
			$this->changeItemAmount($product, $variant, $replaceMode ? $amount : $item->amount + $amount, $checkInvalidAmount, $cart);

			if ($this->onCartItemCreate) {
				$this->onCartItemCreate($item);
			}

			return $item;
		}

		$cartItem = $this->itemRepository->syncItem($cart ?? $this->getCart(), null, $product, $variant, $amount, $disabled);

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
	 * @throws \Eshop\BuyException
	 */
	public function changeItemAmount(Product $product, ?Variant $variant = null, int $amount = 1, bool $checkInvalidAmount = true, ?Cart $cart = null): void
	{
		if ($checkInvalidAmount && !$this->checkAmount($product, $amount)) {
			throw new BuyException('Invalid amount', BuyException::INVALID_AMOUNT);
		}

		$this->itemRepository->updateItemAmount($cart ?: $this->getCart(), $variant, $product, $amount);
		$this->refreshSumProperties();

		if (!($cartItem = $this->itemRepository->getItem($cart ?: $this->getCart(), $product, $variant))) {
			return;
		}

		foreach ($this->productRepository->getCartItemRelations($cartItem) as $upsell) {
			if ($this->itemRepository->isUpsellActive($cartItem->getPK(), $upsell->getPK())) {
				$upsellCartItem = $this->itemRepository->many()->where('fk_upsell', $cartItem->getPK())->where('product.uuid', $upsell->getPK())->first();

				$upsellCartItem->update([
					'price' => $upsell->getValue('price'),
					'priceVat' => $upsell->getValue('priceVat'),
					'amount' => $upsell->getValue('amount'),
				]);
			}
		}
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

	public function addItemsFromCart(Cart $cart, bool $required = false): void
	{
		$ids = $this->itemRepository->getItems([$cart->getPK()])->where('this.fk_product IS NOT NULL')->setSelect(['aux' => 'this.fk_product'])->toArrayOf('aux');

		/** @var \Eshop\DB\Product[] $products */
		$products = $this->productRepository->getProducts()->where('this.uuid', $ids)->toArray();

		/** @var \Eshop\DB\CartItem $item */
		foreach ($this->itemRepository->getItems([$cart->getPK()]) as $item) {
			if (!isset($products[$item->getValue('product')])) {
				if ($required) {
					throw new BuyException('product not found');
				}

				continue;
			}

			$this->addItemToCart($products[$item->getValue('product')], $item->variant, $item->amount, false, null, null);
		}
	}

	public function getPaymentTypes(): Collection
	{
		return $this->paymentTypes ??= $this->paymentTypeRepository->getPaymentTypes($this->shopper->getCurrency(), $this->customer, $this->shopper->getCustomerGroup());
	}

	public function getDeliveryTypes(bool $vat = false): Collection
	{
		return $this->deliveryTypeRepository->getDeliveryTypes(
			$this->shopper->getCurrency(),
			$this->customer,
			$this->shopper->getCustomerGroup(),
			$this->getDeliveryDiscount($vat),
			$this->getSumWeight(),
			$this->getSumDimension(),
		);
	}

	public function checkDiscountCoupon(): bool
	{
		/** @var \Eshop\DB\DiscountCoupon|null $discountCoupon */
		$discountCoupon = $this->getDiscountCoupon();

		if ($discountCoupon === null) {
			return true;
		}

		return (bool)$this->discountCouponRepository->getValidCouponByCart($discountCoupon->code, $this->getCart(), $discountCoupon->exclusiveCustomer);
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
	 * @return array<int, array<string, \Eshop\DB\CartItem|float|int|string|null>>
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
						'correctValue' => \floatval($this->productRepository->getProduct($cartItem->product->getPK())->getPrice($cartItem->amount)),
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

	public function getPaymentPrice(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			$price = $this->getPaymentTypes()[$this->getPurchase()->getValue('paymentType')]->getValue('price');

			return isset($price) ? (float)$price : 0.0;
		}

		return 0.0;
	}

	public function getPaymentPriceVat(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			$price = $this->getPaymentTypes()[$this->getPurchase()->getValue('paymentType')]->getValue('priceVat');

			return isset($price) ? (float)$price : 0.0;
		}

		return 0.0;
	}

	public function getDeliveryPrice(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->deliveryType) {
			$price = $this->getDeliveryTypes()[$this->getPurchase()->getValue('deliveryType')]->getValue('price');

			return isset($price) ? (float)$price : 0.0;
		}

		return 0.0;
	}

	public function getDeliveryPriceVat(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			$price = $this->getDeliveryTypes(true)[$this->getPurchase()->getValue('deliveryType')]->getValue('priceVat');

			return isset($price) ? (float)$price : 0.0;
		}

		return 0.0;
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
	}

	public function getDiscountPrice(): float
	{
		if ($coupon = $this->getDiscountCoupon()) {
			if ($coupon->discountPct) {
				return \floatval($this->getSumPrice() * $coupon->discountPct / 100);
			}

			return \floatval($coupon->discountValue);
		}

		return 0.0;
	}

	public function getDiscountPriceVat(): float
	{
		if ($coupon = $this->getDiscountCoupon()) {
			if ($coupon->discountPct) {
				return \floatval($this->getSumPriceVat() * $coupon->discountPct / 100);
			}

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
				'password' => $this->passwords->hash($purchase->password),
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

		if ($createAccount) {
			$customer->account = $account;

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
	 * @throws \Eshop\BuyException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function createOrder(?Purchase $purchase = null): void
	{
		$purchase = $purchase ?: $this->getPurchase();

		if ($this->bannedEmailRepository->isEmailBanned($purchase->email)) {
			throw new BuyException('Banned email', BuyException::BANNED_EMAIL);
		}

		$discountCoupon = $this->getDiscountCoupon();

		if ($discountCoupon && $discountCoupon->usageLimit && $discountCoupon->usagesCount >= $discountCoupon->usageLimit) {
			throw new BuyException('Coupon invalid!', BuyException::INVALID_COUPON);
		}

		$customer = $this->shopper->getCustomer();
		$cart = $this->getCart();
		$currency = $cart->currency;

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
			[$this->orderRepository->many()->where('YEAR(this.createdTs)', $year)->enum() + $this->shopper->getCountry()->orderCodeStartNumber, $year],
		);

		$orderValues = [
			'code' => $code,
			'purchase' => $purchase,
		];

		$orderValues['receivedTs'] = $this->shopper->getEditOrderAfterCreation() ? null : (string)new DateTime();

		/** @var \Eshop\DB\Order $order */
		$order = $this->orderRepository->createOne($orderValues);

		//@todo getDeliveryPrice se pocita z aktulaniho purchase ne z parametru a presunout do order repository jako create order
		if ($purchase->deliveryType) {
			/** @var \Eshop\DB\Delivery $delivery */
			$delivery = $this->deliveryRepository->createOne([
				'order' => $order,
				'currency' => $currency,
				'type' => $purchase->deliveryType,
				'typeName' => $purchase->deliveryType->toArray()['name'],
				'typeCode' => $purchase->deliveryType->code,
				'price' => $this->getDeliveryPrice(),
				'priceVat' => $this->getDeliveryPriceVat(),
			]);

			/** @var \Eshop\DB\Package $package */
			$package = $this->packageRepository->createOne([
				'order' => $order->getPK(),
				'delivery' => $delivery->getPK(),
			]);

			foreach ($purchase->getItems() as $cartItem) {
				$this->packageItemRepository->createOne([
					'package' => $package->getPK(),
					'cartItem' => $cartItem->getPK(),
					'amount' => $cartItem->amount,
				]);
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
		}

		if ($discountCoupon && $discountCoupon->usageLimit) {
			$discountCoupon->update(['usagesCount' => $discountCoupon->usagesCount + 1]);
		}

		$this->orderLogItemRepository->createLog($order, OrderLogItem::CREATED);

		$this->createCart();

		$this->refreshSumProperties();

		$this->onOrderCreate($order);
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
}
