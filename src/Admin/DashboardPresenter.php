<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use App\Admin\PresenterTrait;
use Eshop\DB\DiscountRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\MerchantRepository;
use Eshop\DB\OrderRepository;
use Nette\Application\UI\Presenter;
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
	
	public function renderDefault()
	{
		$this->template->headerLabel = 'Nástěnka';
		$this->template->headerTree = [
			['Nástěnka'],
		];
	}
	
	public function actionDefault()
	{
		$this->template->recievedOrders = $this->orderRepo->many()->where('this.completedTs IS NULL AND this.canceledTs IS NULL')->orderBy(['createdTs DESC'])->setTake(10);
		
		/** @var \Security\DB\Account[] $accounts */
		$accounts = $this->accountRepo->many()->orderBy(['tsRegistered']);
		
		$counter = 0;
		$this->template->nonActiveUsers = [];
		foreach ($accounts as $account) {
			
			if ($counter == 10) {
				break;
			}
			
			if ($account->isActive()) {
				continue;
			}
			
			$customer = $this->customerRepo->many()->where('fk_account', $account->getPK())->fetch();
			
			if ($customer) {
				$this->template->nonActiveUsers[] = $customer;
				$counter++;
				continue;
			}
			
			$merchant = $this->merchantRepo->many()->where('fk_account', $account->getPK())->fetch();
			
			if ($merchant) {
				$this->template->nonActiveUsers[] = $merchant;
				$counter++;
				continue;
			}
			
			//$this->template->nonActiveUsers[] = $account;
		}
		
		$this->template->discounts = $this->discountRepo->getActiveDiscounts();
		
		$this->template->setFile(__DIR__ . \DIRECTORY_SEPARATOR . 'templates' .\DIRECTORY_SEPARATOR  . 'Dashboard.default.latte');
	}
}