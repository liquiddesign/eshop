<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DiscountRepository;
use Eshop\DB\MerchantRepository;
use Eshop\DB\OrderRepository;
use Eshop\Shopper;
use Security\DB\AccountRepository;

class DashboardPresenter extends BackendPresenter
{
	/** @inject */
	public OrderRepository $orderRepo;
	
	/** @inject */
	public AccountRepository $accountRepo;
	
	/** @inject */
	public CustomerRepository $customerRepo;
	
	/** @inject */
	public MerchantRepository $merchantRepo;
	
	/** @inject */
	public DiscountRepository $discountRepo;

	/** @inject */
	public Shopper $shopper;
	
	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Nástěnka';
		$this->template->headerTree = [
			['Nástěnka'],
		];
	}
	
	public function actionDefault(): void
	{
		$this->template->recievedOrders = $this->orderRepo->many()->where('this.completedTs IS NULL AND this.canceledTs IS NULL')->orderBy(['createdTs DESC'])->setTake(10);
		
		/** @var \Security\DB\Account[] $accounts */
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
		
		$this->template->setFile(__DIR__ . \DIRECTORY_SEPARATOR . 'templates' .\DIRECTORY_SEPARATOR . 'Dashboard.default.latte');
	}
}
