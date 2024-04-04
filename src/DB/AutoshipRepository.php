<?php

declare(strict_types=1);

namespace Eshop\DB;

use Eshop\ShopperUser;
use Nette\Utils\Arrays;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Autoship>
 */
class AutoshipRepository extends \StORM\Repository
{
	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		private readonly ShopperUser $shopperUser,
		private readonly AddressRepository $addressRepository,
		private readonly PurchaseRepository $purchaseRepository
	) {
		parent::__construct($connection, $schemaManager);
	}
	
	public function createOrder(Autoship $autoship): ?Order
	{
		/** @var \Eshop\DB\Cart|null $cart */
		$cart = $autoship->purchase->carts->first();
		
		if (!$cart) {
			return null;
		}
		
		$this->shopperUser->setCustomer($autoship->purchase->customer);
		$this->shopperUser->getCheckoutManager()->createCart();
		$this->shopperUser->getCheckoutManager()->getCart()->update(['purchase' => $autoship->purchase]);
		$this->shopperUser->getCheckoutManager()->addItemsFromCart($cart);
		
		$purchase = $autoship->purchase->toArray(['deliveryAddress', 'billAddress'], true, false, false);
		unset($purchase['deliveryAddress']['uuid'], $purchase['deliveryAddress']['id'], $purchase['billAddress']['uuid'], $purchase['billAddress']['id']);
		
		$deliveryAddress = Arrays::pick($purchase, 'deliveryAddress');
		
		if ($deliveryAddress !== null) {
			$purchase['deliveryAddress'] = $this->addressRepository->createOne($deliveryAddress);
		}

		/** @var null|array<mixed> $billAddress */
		$billAddress = Arrays::pick($purchase, 'billAddress');

		if ($billAddress !== null) {
			$purchase['billAddress'] = $this->addressRepository->createOne($billAddress);
		}
		
		$purchase = $this->purchaseRepository->createOne($purchase);
		
		return $this->shopperUser->getCheckoutManager()->createOrder($purchase, ['autoship' => $autoship->getPK()]);
	}
}
