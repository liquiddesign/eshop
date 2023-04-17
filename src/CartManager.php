<?php

namespace Eshop;

use Eshop\Common\CheckInvalidAmount;
use Eshop\DB\Cart;
use Eshop\DB\CartItem;
use Eshop\DB\CartItemRepository;
use Eshop\DB\CartItemTaxRepository;
use Eshop\DB\Product;
use Eshop\DB\TaxRepository;
use Eshop\DB\Variant;
use Nette\SmartObject;
use Nette\Utils\Arrays;
use StORM\Collection;

class CartManager
{
	use SmartObject;
	public const DEFAULT_MIN_BUY_COUNT = 1;
	public const DEFAULT_MAX_BUY_COUNT = 999999999;

	/**
	 * @var array<callable>&callable(\Eshop\DB\Customer): void ; Occurs after customer create
	 */
	public array $onCustomerCreate;

	/**
	 * @var array<callable>&callable(\Eshop\DB\Order): void ; Occurs after order create
	 */
	public array $onOrderCreate;

	/**
	 * @var array<callable> Occurs after cart create
	 */
	public array $onCartCreate = [];

	/**
	 * @var array<callable>&callable(): void ; Occurs after cart item delete
	 */
	public array $onCartItemDelete;

	/**
	 * @var array<callable(\Eshop\DB\CartItem): void>; Occurs after cart item create
	 */
	public array $onCartItemCreate = [];

	/**
	 * @var array<callable>&callable(): void ; Occurs after cart item updated
	 */
	public array $onCartItemUpdate;

	/**
	 * @var array<mixed>|null
	 */
	public ?array $orderCodeArguments = null;

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
	 * @var array<string>
	 */
	protected array $checkoutSequence;

	protected ?string $cartToken;

	protected ?string $lastOrderToken;

	/**
	 * @var array<\Eshop\DB\Cart>|array<null>
	 */
	protected array $unattachedCarts = [];

	protected int $cartExpiration = 30;

	public function __construct(
		private readonly ShopperUser $shopperUser,
		private readonly TaxRepository $taxRepository,
		private readonly CartItemRepository $cartItemRepository,
		private readonly CartItemTaxRepository $cartItemTaxRepository,
	) {
	}

	/**
	 * @param \Eshop\DB\Product $product Must have set prices
	 * @param \Eshop\DB\Variant|null $variant
	 * @param int $amount
	 * @param ?bool $replaceMode true - replace | false - add or update | null - only add
	 * @param \Eshop\Common\CheckInvalidAmount $checkInvalidAmount
	 * @param ?bool $checkCanBuy
	 * @param \Eshop\DB\CartItem|null $upsell
	 * @throws \Eshop\BuyException
	 */
	public function addItemToCart(
		Product $product,
		?Variant $variant = null,
		int $amount = 1,
		?bool $replaceMode = false,
		CheckInvalidAmount $checkInvalidAmount = CheckInvalidAmount::CHECK_THROW,
		?bool $checkCanBuy = true,
		?CartItem $upsell = null
	): CartItem {
		if (!$this->checkCurrency($product)) {
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

		if ($replaceMode !== null && $item = $this->cartItemRepository->getItem($this->getCart(), $product, $variant)) {
			$this->changeItemAmount($product, $variant, $replaceMode ? $amount : $item->amount + $amount, $checkInvalidAmount);

			Arrays::invoke($this->onCartItemCreate($item));

			return $item;
		}

		$cartItem = $this->cartItemRepository->syncItem($this->getCart(), null, $product, $variant, $amount, $disabled);

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

		Arrays::invoke($this->onCartItemCreate($cartItem));


		return $cartItem;
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

		return (bool) $this->unattachedCarts[$this->cartToken];
	}

	public function getCart(): Cart
	{
		if (!$this->cartExists()) {
			return $this->createCart();
		}

		return ($customer = $this->shopperUser->getCustomer()) ? $customer->activeCart : $this->unattachedCarts[$this->cartToken];
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
