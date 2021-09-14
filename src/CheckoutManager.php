<?php

declare(strict_types=1);

namespace Eshop;

use Eshop\DB\CartItemTaxRepository;
use Eshop\DB\Currency;
use Eshop\DB\DeliveryDiscount;
use Eshop\DB\DeliveryDiscountRepository;
use Eshop\DB\DeliveryTypeRepository;
use Eshop\DB\DiscountCoupon;
use Eshop\DB\DiscountCouponRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\Set;
use Eshop\DB\SetItemRepository;
use Eshop\DB\SetRepository;
use Eshop\DB\TaxRepository;
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
use Eshop\DB\VatRateRepository;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\SmartObject;
use Nette\Utils\DateTime;
use Security\DB\AccountRepository;
use StORM\Collection;
use StORM\Connection;
use StORM\DIConnection;
use StORM\Exception\NotFoundException;
use Web\DB\SettingRepository;

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
	
	private SettingRepository $settingRepository;
	
	private Collection $paymentTypes;
	
	private Collection $deliveryTypes;
	
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
	
	private PurchaseRepository $purchaseRepository;
	
	private Response $response;
	
	private TaxRepository $taxRepository;
	
	private CartItemTaxRepository $cartItemTaxRepository;
	
	private SetRepository $setRepository;
	
	private SetItemRepository $setItemRepository;
	
	private VatRateRepository $vatRateRepository;
	
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
		Response $response,
		TaxRepository $taxRepository,
		CartItemTaxRepository $cartItemTaxRepository,
		SettingRepository $settingRepository,
		SetRepository $setRepository,
		SetItemRepository $setItemRepository,
		VatRateRepository $vatRateRepository
	)
	{
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
		$this->taxRepository = $taxRepository;
		$this->cartItemTaxRepository = $cartItemTaxRepository;
		$this->settingRepository = $settingRepository;
		$this->setRepository = $setRepository;
		$this->setItemRepository = $setItemRepository;
		$this->vatRateRepository = $vatRateRepository;
		
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
	
	private function getCart(): Cart
	{
		if (!$this->cartExists()) {
			return $this->createCart();
		}
		
		return $this->customer ? $this->customer->activeCart : $this->unattachedCarts[$this->cartToken];
	}
	
	public function getCartCurrency(): ?Currency
	{
		return $this->cartExists() ? $this->getCart()->currency : null;
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
			'expirationTs' => $this->customer ? null : (string)new DateTime('+' . $this->cartExpiration . ' days'),
		]);
		
		$this->customer ? $this->customer->update(['activeCart' => $cart]) : $this->unattachedCarts[$this->cartToken] = $cart;
		
		return $cart;
	}
	
	public function canBuyProduct(Product $product): bool
	{
		
		return !$product->unavailable && $product->price !== null && $this->shopper->getBuyPermission();
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
	
	public function createSetItems(CartItem $cartItem)
	{
		$setProduct = $cartItem->product;
		
		if ($setProduct->productsSet) {
			/** @var Product[] $products */
			$products = $this->productRepository->getProducts()->join(['setT' => 'eshop_set'], 'this.uuid = setT.fk_product')->where('setT.fk_set', $setProduct->getPK())->toArray();
			
			foreach ($products as $product) {
				/** @var \Eshop\DB\VatRate $vat */
				$vat = $this->vatRateRepository->one($product->vatRate);
				
				$vatPct = $vat ? $vat->rate : 0;
				
				/** @var Set $set */
				$set = $this->setRepository->many()->where('fk_set', $setProduct->getPK())->where('fk_product', $product->getPK())->first();
				
				$this->setItemRepository->createOne([
					'cartItem' => $cartItem->getPK(),
					'productSet' => $set->getPK(),
					'productName' => $product->toArray()['name'],
					'productCode' => $product->code,
					'productSubCode' => $product->subCode,
					'productWeight' => $product->weight,
					'amount' => $set->amount,
					'vatPct' => (float)$vatPct,
					'discountPct' => $set->discountPct,
					'priority' => $set->priority
				]);
			}
		}
	}
	
	/**
	 * @param \Eshop\DB\Product $product
	 * @param \Eshop\DB\Variant|null $variant
	 * @param int $amount
	 * @param bool $replaceMode
	 * @param ?bool $checkInvalidAmount
	 * @param ?bool $checkCanBuy
	 * @param Cart|null $cart
	 * @return CartItem
	 * @throws BuyException
	 * @throws NotFoundException
	 */
	public function addItemToCart(Product $product, ?Variant $variant = null, int $amount = 1, bool $replaceMode = false, ?bool $checkInvalidAmount = true, ?bool $checkCanBuy = true, ?Cart $cart = null): CartItem
	{
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
			} else {
				$disabled = true;
			}
		}
		
		if ($checkInvalidAmount !== false && !$this->checkAmount($product, $amount)) {
			if ($checkInvalidAmount === true) {
				throw new BuyException('Invalid amount', BuyException::INVALID_AMOUNT);
			} else {
				$disabled = true;
			}
		}
		
		if ($item = $this->itemRepository->getItem($cart ?? $this->getCart(), $product, $variant)) {
			$this->changeItemAmount($product, $variant, $replaceMode ? $amount : $item->amount + $amount, $checkInvalidAmount, $cart);
			
			return $item;
		}
		
		$cartItem = $this->itemRepository->syncItem($cart ?? $this->getCart(), null, $product, $variant, $amount, $disabled);
		
		if ($currency = $this->getCartCurrency()) {
			/** @var \Eshop\DB\Tax[] $taxes */
			$taxes = $this->taxRepository->getTaxesForProduct($product, $currency->getPK());
			
			foreach ($taxes as $tax) {
				$tax = $tax->toArray();
				$this->cartItemTaxRepository->createOne([
					'name' => $tax['name'],
					'price' => $tax['price'],
					'cartItem' => $cartItem->getPK()
				], null);
			}
		}
		
		$this->refreshSumProperties();
		
		$this->createSetItems($cartItem);
		
		return $cartItem;
	}
	
	public function changeItemAmount(Product $product, ?Variant $variant = null, int $amount = 1, bool $checkInvalidAmount = true, ?Cart $cart = null): void
	{
		if ($checkInvalidAmount && !$this->checkAmount($product, $amount)) {
			throw new BuyException('Invalid amount', BuyException::INVALID_AMOUNT);
		}
		
		$this->itemRepository->updateItemAmount($cart ?: $this->getCart(), $variant, $product, $amount);
		$this->refreshSumProperties();
		
		if ($cartItem = $this->itemRepository->getItem($cart ?: $this->getCart(), $product, $variant)) {
			foreach ($this->productRepository->getUpsellsForCartItem($cartItem) as $upsell) {
				if ($this->itemRepository->isUpsellActive($cartItem->getPK(), $upsell->getPK())) {
					$upsellCartItem = $this->itemRepository->many()->where('fk_upsell', $cartItem->getPK())->where('product.uuid', $upsell->getPK())->first();
					
					/** @var \Eshop\DB\Product $upsell */
					if (!$upsellWithPrice = $this->productRepository->getProducts()->where('this.uuid', $upsell->getPK())->first()) {
						if ($cartItem->product->dependedValue) {
							$upsell->price = $cartItem->getPriceSum() * ($cartItem->product->dependedValue / 100);
							$upsell->priceVat = $cartItem->getPriceVatSum() * ($cartItem->product->dependedValue / 100);
							$upsell->currencyCode = $this->shopper->getCurrency()->code;
						}
					} else {
						if ($upsellWithPrice->getPriceVat()) {
							$upsell->price = $cartItem->amount * $upsellWithPrice->getPrice();
							$upsell->priceVat = $cartItem->amount * $upsellWithPrice->getPriceVat();
						}
					}
					
					$upsellCartItem->update([
						'price' => $upsell->price,
						'priceVat' => $upsell->priceVat
					]);
				}
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
	
	public function addItemsFromCart(Cart $cart)
	{
		$ids = $this->itemRepository->getItems([$cart->getPK()])->where('this.fk_product IS NOT NULL')->setSelect(['aux' => 'this.fk_product'])->toArrayOf('aux');
		
		$products = $this->productRepository->getProducts()->where('this.uuid', $ids)->toArray();
		
		foreach ($this->itemRepository->getItems([$cart->getPK()]) as $item) {
			if (!isset($products[$item->getValue('product')])) {
				throw new BuyException('product not found');
			}
			
			$this->addItemToCart($products[$item->getValue('product')], $item->variant, $item->amount, false, null, null);
		}
	}
	
	public function getPaymentTypes(): Collection
	{
		return $this->paymentTypes ??= $this->paymentTypeRepository->getPaymentTypes($this->shopper->getCurrency(), $this->customer, $this->shopper->getCustomerGroup());
	}
	
	public function getDeliveryTypes(): Collection
	{
		return $this->deliveryTypes ??= $this->deliveryTypeRepository->getDeliveryTypes($this->shopper->getCurrency(), $this->customer, $this->shopper->getCustomerGroup(), $this->getDeliveryDiscount(), $this->getSumWeight(), $this->getSumDimension());
	}
	
	public function checkDiscountCoupon(): bool
	{
		/** @var \Eshop\DB\DiscountCoupon $discountCoupon */
		$discountCoupon = $this->getDiscountCoupon();
		
		if (!$discountCoupon) {
			return true;
		}
		
		return (bool)$this->discountCouponRepository->getValidCoupon($discountCoupon->code, $discountCoupon->currency, $discountCoupon->exclusiveCustomer);
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
		$previousStep = $this->checkoutSequence[$sequence - 1] ?? null;
		
		if ($previousStep === 'cart') {
			return $this->getPurchase() && (bool)count($this->getItems()) && empty($this->getIncorrectCartItems()) && $this->checkDiscountCoupon();
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
		
		return (float)$price ?: 0.0;
	}
	
	public function getCheckoutPriceVat(): float
	{
		$priceVat = $this->getSumPriceVat() + $this->getDeliveryPriceVat() - $this->getDiscountPriceVat();
		
		return (float)$priceVat ?: 0.0;
	}
	
	public function getCartCheckoutPrice(): float
	{
		$price = $this->getSumPrice() - $this->getDiscountPrice();
		
		return (float)$price ?: 0.0;
	}
	
	public function getCartCheckoutPriceVat(): float
	{
		$priceVat = $this->getSumPriceVat() - $this->getDiscountPriceVat();
		
		return (float)$priceVat ?: 0.0;
	}
	
	public function getPaymentPrice(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			return (float)$this->getPaymentTypes()[$this->getPurchase()->getValue('paymentType')]->price ?? 0.0;
		}
		
		return 0.0;
	}
	
	public function getPaymentPriceVat(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			return (float)$this->getPaymentTypes()[$this->getPurchase()->getValue('paymentType')]->priceVat ?? 0.0;
		}
		
		return 0.0;
	}
	
	public function getDeliveryPrice(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->deliveryType) {
			return (float)$this->getDeliveryTypes()[$this->getPurchase()->getValue('deliveryType')]->price ?? 0.0;
		}
		
		return 0.0;
	}
	
	public function getDeliveryPriceVat(): float
	{
		if ($this->getPurchase() && $this->getPurchase()->paymentType) {
			return (float)$this->getDeliveryTypes()[$this->getPurchase()->getValue('deliveryType')]->priceVat ?? 0.0;
		}
		
		return 0.0;
	}
	
	public function getDeliveryDiscount(): ?DeliveryDiscount
	{
		$currency = $this->cartExists() ? $this->getCart()->currency : $this->shopper->getCurrency();
		
		return $this->deliveryDiscountRepository->getActiveDeliveryDiscount($currency, $this->getCartCheckoutPrice());
	}
	
	public function getPossibleDeliveryDiscount(): ?DeliveryDiscount
	{
		$currency = $this->cartExists() ? $this->getCart()->currency : $this->shopper->getCurrency();
		
		return $this->deliveryDiscountRepository->getNextDeliveryDiscount($currency, $this->getCartCheckoutPrice());
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
		return !($amount < $product->minBuyCount || ($product->maxBuyCount !== null && $amount > $product->maxBuyCount) || ($product->buyStep && $amount % $product->buyStep !== 0));
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
	
	public function syncPurchase($values): Purchase
	{
		$values['uuid'] = $this->getCart()->getValue('purchase');
		
		if (!$values['uuid']) {
			$values['currency'] = $this->getCart()->currency->getPK();
			
			/** @var Purchase $purchase */
			$purchase = $this->purchaseRepository->createOne($values);
			
			$this->getCart()->update(['purchase' => $purchase->getPK()]);
		} else {
			/** @var Purchase $purchase */
			$purchase = $this->purchaseRepository->syncOne($values, null, true);
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
		$cart = $this->getCart();
		$currency = $cart->currency;
		
		$cart->update(['approved' => ($customer && $customer->orderPermission == 'full') || $customer ? 'yes' : 'waiting']);
		
		// createAccount
		if ($purchase->createAccount && !$customer) {
			$customer = $this->createAccount($purchase);
			
			$this->onCustomerCreate($customer);
		}
		
		$year = \date('Y');
		$code = \vsprintf($this->shopper->getCountry()->orderCodeFormat, [$this->orderRepository->many()->where('YEAR(this.createdTs)', $year)->enum() + $this->shopper->getCountry()->orderCodeStartNumber, $year]);
		
		$orderValues = [
			'code' => $code,
			'purchase' => $purchase
		];
		
		$orderValues['receivedTs'] = $this->shopper->getEditOrderAfterCreation() ? null : (string)new DateTime();
		
		$order = $this->orderRepository->createOne($orderValues);
		
		// @TODO: getDeliveryPrice se pocita z aktulaniho purchase ne z parametru a presunout do order repository jako create order
		if ($purchase->deliveryType) {
			$this->deliveryRepository->createOne([
				'order' => $order,
				'currency' => $currency,
				'type' => $purchase->deliveryType,
				'typeName' => $purchase->deliveryType->toArray()['name'],
				'typeCode' => $purchase->deliveryType->code,
				'price' => $this->getDeliveryPrice(),
				'priceVat' => $this->getDeliveryPriceVat()
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
		$this->sumDimension = null;
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
