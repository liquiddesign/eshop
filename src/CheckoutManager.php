<?php

declare(strict_types=1);

namespace Eshop;

use Eshop\DB\DeliveryDiscount;
use Eshop\DB\DeliveryDiscountRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\DiscountCoupon;
use Eshop\DB\DiscountCouponRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\Variant;
use Eshop\DB\Address;
use Eshop\DB\Cart;
use Eshop\DB\CartItem;
use Eshop\DB\CartItemRepository;
use Eshop\DB\CartRepository;
use Eshop\DB\Customer;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DeliveryRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentRepository;
use Eshop\DB\Purchase;
use Eshop\DB\PurchaseRepository;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\SmartObject;
use Nette\Utils\DateTime;
use Security\DB\AccountRepository;
use StORM\Collection;
use StORM\Connection;
use StORM\DIConnection;
use StORM\Exception\NotFoundException;

/**
 * Služba která zapouzdřuje košíky nakupujícího
 * @method onCustomerCreate(\Eshop\DB\Customer $customer)
 * @method onOrderCreate(\Eshop\DB\Order $order)
 * @method onCartItemDelete()
 * @package Eshop
 */
class CheckoutManager
{
	use SmartObject;
	
	/**
	 * @var callable[]&callable(\Eshop\DB\Customer): void; Occurs after customer create
	 */
	public $onCustomerCreate;
	
	/**
	 * @var callable[]&callable(\Eshop\DB\Order): void; Occurs after order create
	 */
	public $onOrderCreate;
	
	/**
	 * @var callable[]&callable(): void; Occurs after cart item delete
	 */
	public $onCartItemDelete;
	
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
	
	private Collection $deliveryTypes;
	
	private ?float $sumPrice = null;
	
	private ?float $sumPriceVat = null;
	
	private ?int $sumAmount = null;
	
	private ?int $sumAmountTotal = null;
	
	private ?float $sumWeight = null;
	
	private ?int $sumPoints = null;
	
	/**
	 * @var string[]
	 */
	private array $checkoutSequence;
	
	private OrderRepository $orderRepository;
	
	private AccountRepository $accountRepository;
	
	private CustomerRepository $customerRepository;
	
	private PurchaseRepository $purchaseRepository;
	
	private Response $response;
	
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
		PurchaseRepository $purchaseRepository,
		AccountRepository $accountRepository,
		CustomerRepository $customerRepository,
		DiscountCouponRepository $discountCouponRepository,
		Request $request,
		Response $response
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
		$this->purchaseRepository = $purchaseRepository;
		$this->accountRepository = $accountRepository;
		$this->customerRepository = $customerRepository;
		$this->discountCouponRepository = $discountCouponRepository;
		$this->response = $response;
		
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
	}
	
	public function setCheckoutSequence(array $checkoutSequence): void
	{
		$this->checkoutSequence = $checkoutSequence;
	}
	
	public function cartExists(): bool
	{
		if ($this->customer) {
			try {
				return (bool)$this->customer->activeCart;
			} catch (NotFoundException $x) {
				$this->customer->activeCart = null;
				
				return false;
			}
		}
		
		if (!\array_key_exists($this->cartToken, $this->unattachedCarts)) {
			$this->unattachedCarts[$this->cartToken] = $this->cartRepository->getUnattachedCart($this->cartToken);
		}
		
		return (bool) $this->unattachedCarts[$this->cartToken];
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
		
		return $this->sumAmountTotal ??= (int) $this->itemRepository->getSumProperty([$this->getCart()->getPK()], 'amount');
	}
	
	public function getSumWeight(): float
	{
		if (!$this->cartExists()) {
			return 0.0;
		}
		
		return $this->sumWeight ??= $this->itemRepository->getSumProperty([$this->getCart()->getPK()], 'productWeight');
	}
	
	public function getSumPoints(): int
	{
		if (!$this->cartExists()) {
			return 0;
		}
		
		return $this->sumPoints ??= (int) $this->itemRepository->getSumProperty([$this->getCart()->getPK()], 'pts');
	}
	
	private function getCart(): Cart
	{
		if (!$this->cartExists()) {
			return $this->createCart();
		}
		
		return $this->customer ? $this->customer->activeCart : $this->unattachedCarts[$this->cartToken];
	}
	
	public function getCartCurrencyCode(): ?string
	{
		return $this->cartExists() ? $this->getCart()->currency->code : null;
	}
	
	public function getAvailableCarts(): array
	{
		//@TODO: vrátí asociativní pole všech košíku číslo => cart
		return [];
	}
	
	public function changeCart(int $id): void
	{
		// @TODO: zmeni aktivni kosik
	}
	
	public function createCart(int $id = 1, bool $activate = true): Cart
	{
		$cart = $this->cartRepository->createOne([
			'uuid' => $this->customer ? null : $this->cartToken,
			'id' => $id,
			'active' => $activate,
			'customer' => $this->customer ? $this->customer : null,
			'currency' => $this->shopper->getCurrency(),
			'expirationTs' => $this->customer ? null : (string) new DateTime('+' . $this->cartExpiration . ' days'),
		]);
		
		$this->customer ? $this->customer->update(['activeCart' => $cart]) : $this->unattachedCarts[$this->cartToken] = $cart;
		
		return $cart;
	}
	
	public function canBuyProduct(Product $product): bool
	{
		return !$product->unavailable && !$product->draft && $product->price !== null;
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
	 * @param bool $checkInvalidAmount
	 * @param bool $checkCanBuy
	 * @throws \Eshop\BuyException
	 */
	public function addItemToCart(Product $product, ?Variant $variant = null, int $amount = 1, bool $replaceMode = false, bool $checkInvalidAmount = true, bool $checkCanBuy = true, ?Cart $cart = null): CartItem
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
		
		if ($this->shopper->getCatalogPermission() !== 'full') {
			throw new BuyException('Permission denied', BuyException::PERMISSION_DENIED);
		}
		
		if ($item = $this->itemRepository->getItem($cart ?? $this->getCart(), $product, $variant)) {
			$this->changeItemAmount($product, $variant, $replaceMode ? $amount : $item->amount + $amount, $checkInvalidAmount, $cart);
			
			return $item;
		}
		
		$cartItem = $this->itemRepository->syncItem($cart ?? $this->getCart(), null, $product, $variant, $amount);
		
		$this->refreshSumProperties();
		
		return $cartItem;
	}
	
	public function changeItemAmount(Product $product, ?Variant $variant = null, int $amount = 1, bool $checkInvalidAmount = true, ?Cart $cart = null): void
	{
		if ($checkInvalidAmount && !$this->checkAmount($product, $amount)) {
			throw new BuyException('Invalid amount', BuyException::INVALID_AMOUNT);
		}
		
		$this->itemRepository->updateItemAmount($cart ?: $this->getCart(), $variant, $product, $amount);
		$this->refreshSumProperties();
	}
	
	public function deleteItem(CartItem $item): void
	{
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
	
	public function addItemsFromCart(Cart $cart)
	{
		$ids = $this->itemRepository->getItems([$cart->getPK()])->where('this.fk_product IS NOT NULL')->setSelect(['aux' => 'this.fk_product'])->toArrayOf('aux');
		$products = $this->productRepository->getProducts()->where('this.uuid', $ids)->toArray();
		
		foreach ($this->itemRepository->getItems([$cart->getPK()]) as $item) {
			try {
				if (!isset($products[$item->getValue('product')])) {
					throw new BuyException('product not found');
				}
				
				$this->addItemToCart($products[$item->getValue('product')], $item->variant, $item->amount);
			} catch (BuyException $exception) {
				$this->disallowItemInCart($item);
			}
		}
	}
	
	public function getPaymentTypes(): Collection
	{
		return $this->paymentTypes ??= $this->paymentTypeRepository->getPaymentTypes($this->shopper->getCurrency(), $this->customer, $this->shopper->getCustomerGroup());
	}
	
	public function getDeliveryTypes(): Collection
	{
		return $this->deliveryTypes ??= $this->deliveryTypeRepository->getDeliveryTypes($this->shopper->getCurrency(), $this->customer, $this->shopper->getCustomerGroup(), $this->getDeliveryDiscount(), $this->getSumWeight());
	}
	
	public function checkDiscountCoupon(): bool
	{
		/** @var \Eshop\DB\DiscountCoupon $discountCoupon */
		$discountCoupon = $this->getDiscountCoupon();
		
		if (!$discountCoupon) {
			return true;
		}
		
		return (bool) $this->discountCouponRepository->getValidCoupon($discountCoupon->code, $discountCoupon->currency, $discountCoupon->exclusiveCustomer);
	}
	
	public function checkOrder(): bool
	{
		if (!$this->checkCart()) {
			return false;
		}
		
		return true;
	}
	
	public function checkCart(): bool
	{
		if (!\boolval(\count($this->getItems()))) {
			return false;
		}
		
		if (!$this->checkDiscountCoupon()) {
			return false;
		}
		
		if (\count($this->getIncorrectCartItems())) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * @return \Eshop\DB\CartItem[]
	 */
	public function getIncorrectCartItems(): array
	{
		$incorrectItems = [];
		
		/** @var \Eshop\DB\CartItem $cartItem */
		foreach ($this->getItems() as $cartItem) {
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
				}
				
				$incorrectItems[] = [
					'object' => $cartItem,
					'reason' => 'incorrect-amount',
					'correctValue' => $correctAmount,
				];
			}
			
			if (!$this->checkCartItemPrice($cartItem)) {
				$incorrectItems[] = [
					'object' => $cartItem,
					'reason' => 'incorrect-price',
					'correctValue' => \floatval($this->productRepository->getProduct($cartItem->product->getPK())->getPrice($cartItem->amount)),
				];
			}
			
			$productRoundAmount = $this->getProductRoundAmount($cartItem->amount, $cartItem->product);
			
			if ($productRoundAmount !== $cartItem->amount) {
				$incorrectItems[] = [
					'object' => $cartItem,
					'reason' => 'product-round',
					'correctValue' => $productRoundAmount,
				];
			}
		}
		
		return $incorrectItems;
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
	
	/**
	 * cart - pokud je buy allowed
	 * addresses - pokud je predchozi krok splen (doplneni adresy)
	 * deliveryPayment - pokud je predchozi krok splen (volba dopravy a platby)
	 * @param string $step
	 * @return bool
	 */
	public function isStepAllowed(string $step): bool
	{
		$sequence = \array_search($step, $this->checkoutSequence);
		$previousStep =  $this->checkoutSequence[$sequence - 1] ?? null;
		
		if ($previousStep === 'cart') {
			return $this->getPurchase() && (bool) count($this->getItems()) && empty($this->getIncorrectCartItems()) && $this->checkDiscountCoupon();
		}
		
		if ($previousStep === 'addresses') {
			return $this->getPurchase() && $this->getPurchase()->email;
		}
		
		if ($previousStep === 'deliveryPayment') {
			return $this->getPurchase() && $this->getPurchase()->deliveryType && $this->getPurchase()->paymentType;
		}
		
		return true;
	}
	
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
		$price = $this->getSumPrice() + $this->getDeliveryPrice() - $this->getDiscountPrice();
		
		return (float) $price ?: 0.0;
	}
	
	public function getCheckoutPriceVat(): float
	{
		$priceVat = $this->getSumPriceVat() + $this->getDeliveryPriceVat() - $this->getDiscountPriceVat();
		
		return (float) $priceVat ?: 0.0;
	}
	
	public function getCartCheckoutPrice(): float
	{
		$price = $this->getSumPrice() - $this->getDiscountPrice();
		
		return (float) $price ?: 0.0;
	}
	
	public function getCartCheckoutPriceVat(): float
	{
		$priceVat = $this->getSumPriceVat() - $this->getDiscountPriceVat();
		
		return (float) $priceVat ?: 0.0;
	}
	
	public function getPaymentPrice(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			return (float) $this->getPaymentTypes()[$this->getPurchase()->getValue('paymentType')]->price ?? 0.0;
		}
		
		return 0.0;
	}
	
	public function getPaymentPriceVat(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			return (float) $this->getPaymentTypes()[$this->getPurchase()->getValue('paymentType')]->priceVat ?? 0.0;
		}
		
		return 0.0;
	}
	
	public function getDeliveryPrice(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->deliveryType) {
			return (float) $this->getDeliveryTypes()[$this->getPurchase()->getValue('deliveryType')]->price ?? 0.0;
		}
		
		return 0.0;
	}
	
	public function getDeliveryPriceVat(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			return (float) $this->getDeliveryTypes()[$this->getPurchase()->getValue('deliveryType')]->priceVat ?? 0.0;
		}
		
		return 0.0;
	}
	
	public function getDeliveryDiscount(): ?DeliveryDiscount
	{
		$currency = $this->cartExists() ? $this->getCart()->currency : $this->shopper->getCurrency();
		
		return $this->deliveryDiscountRepository->getActiveDeliveryDiscount($currency, $this->getSumPrice());
	}
	
	public function getPossibleDeliveryDiscount(): ?DeliveryDiscount
	{
		$currency = $this->cartExists() ? $this->getCart()->currency : $this->shopper->getCurrency();
		
		return $this->deliveryDiscountRepository->getNextDeliveryDiscount($currency, $this->getSumPrice());
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
			$this->syncPurchase([]);
		}
		
		$this->getPurchase()->update(['coupon' => $coupon]);
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
		return !($amount < $product->minBuyCount || ($product->maxBuyCount !== null && $amount > $product->maxBuyCount) || ($product->buyStep !== null && $amount % $product->buyStep !== 0));
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
	
	public function syncPurchase($values): Purchase
	{
		$values['uuid'] = $this->getCart()->getValue('purchase');
		
		/** @var Purchase $purchase */
		$purchase = $this->purchaseRepository->syncOne($values, null, true);
		
		if (!$values['uuid']) {
			$this->getCart()->update(['purchase' => $purchase]);
		}
		
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
	
	public function createAccount(Purchase $purchase): Customer
	{
		/** @var \Security\DB\Account $account */
		$account = $this->accountRepository->createOne([
			'uuid' => Connection::generateUuid(),
			'login' => $purchase->email,
			'password' => $purchase->password,
			'active' => true,
			'authorized' => true,
			//			'confirmationToken' => $token,
		]);
		
		return $this->customerRepository->createNew([
			'account' => $account,
			'email' => $account->login,
			'fullname' => $purchase->fullname,
			'phone' => $purchase->phone,
			'ic' => $purchase->ic,
			'dic' => $purchase->dic,
			'billAddress' => $purchase->billAddress,
			'deliveryAddress' => $purchase->deliveryAddress,
		]);
	}
	
	public function createOrder(?Purchase $purchase = null): void
	{
		$purchase = $purchase ?: $this->getPurchase();
		$customer = $this->shopper->getCustomer();
		$currency = $this->getCart()->currency;
		
		// createAccount
		if ($purchase->createAccount && !$customer) {
			$customer = $this->createAccount($purchase);
			
			$this->onCustomerCreate($customer);
		}
		
		$year = \date('Y');
		$code = \vsprintf($this->shopper->getCountry()->orderCodeFormat, [$this->orderRepository->many()->where('YEAR(this.createdTs)', $year)->enum() + 1, $year]);
		
		$order = $this->orderRepository->createOne(['code' => $code, 'purchase' => $purchase, 'customer' => $customer, 'currency' => $currency]);
		
		// @TODO: getDeliveryPrice se pocita z aktulaniho purchase ne z parametru a presunout do order repository jako create order
		if ($purchase->deliveryType) {
			$this->deliveryRepository->createOne([
				'order' => $order,
				'currency' => $currency,
				'type' => $purchase->deliveryType,
				'typeName' => $purchase->deliveryType->toArray()['name'],
				'typeCode' => $purchase->deliveryType->code,
				'price' => $this->getDeliveryPrice(),
				'priceVat' => $this->getDeliveryPriceVat(),
			]);
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
		
		$this->createCart();
		
		$this->refreshSumProperties();
		
		$this->onOrderCreate($order);
	}
	
	private function checkCurrency(Product $product): bool
	{
		return $product->currencyCode === $this->getCart()->currency->code;
	}
	
	private function refreshSumProperties(): void
	{
		$this->sumPrice = null;
		$this->sumPriceVat = null;
		$this->sumAmountTotal = null;
		$this->sumAmount = null;
		$this->sumWeight = null;
		$this->sumPoints = null;
	}
	
	/**
	 * @param \Eshop\DB\Customer $customer
	 * @deprecated TODO: redesign to $this->shooper->getCustomer();
	 */
	public function setCustomer(Customer $customer): void
	{
		$this->customer = $customer;
	}
}
