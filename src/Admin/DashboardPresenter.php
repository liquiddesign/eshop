<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DiscountRepository;
use Eshop\DB\MerchantRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\ShopperUser;
use Security\DB\AccountRepository;

class DashboardPresenter extends BackendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public OrderRepository $orderRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public AccountRepository $accountRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public CustomerRepository $customerRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public MerchantRepository $merchantRepo;
	
	#[\Nette\DI\Attributes\Inject]
	public DiscountRepository $discountRepo;

	#[\Nette\DI\Attributes\Inject]
	public ShopperUser $shopperUser;
	
	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Nástěnka';
		$this->template->headerTree = [
			['Nástěnka'],
		];
	}
	
	public function actionDefault(): void
	{
		$state = $this->shopperUser->getEditOrderAfterCreation() ? Order::STATE_OPEN : Order::STATE_RECEIVED;

		$this->template->recievedOrders = $this->orderRepo->getCollectionByState($state)->orderBy(['createdTs DESC'])->setTake(10);
		
		/** @var array<\Security\DB\Account> $accounts */
		$accounts = $this->accountRepo->many()->orderBy(['tsRegistered' => 'DESC']);
		
		$counter = 0;
		$this->template->nonActiveUsers = [];

		foreach ($accounts as $account) {
			if ($counter === 10) {
				break;
			}
			
			if ($account->isActive()) {
				continue;
			}

			$this->template->nonActiveUsers[] = $account;
			$counter++;
		}
		
		$this->template->discounts = $this->discountRepo->getActiveDiscounts();
		
		$this->template->setFile(__DIR__ . \DIRECTORY_SEPARATOR . 'templates' . \DIRECTORY_SEPARATOR . 'Dashboard.default.latte');
	}
}
