<?php

namespace Eshop\Integration;

use Eshop\DB\CustomerRepository;
use Eshop\Shopper;
use MailerLiteApi\Api\Groups;
use MailerLiteApi\MailerLite as MailerLiteApi;
use Eshop\DB\Customer;
use Web\DB\SettingRepository;

class MailerLite
{
	private ?string $apiKey;

	private Shopper $shopper;

	private CustomerRepository $customerRepository;

	private Groups $groupsApi;

	private int $groupId;

	private array $subscribers;

	public function __construct(SettingRepository $settingRepository, Shopper $shopper, CustomerRepository $customerRepository)
	{
		if ($apiKey = $settingRepository->many()->where('name = "mailerLiteApiKey"')->first()) {
			$this->apiKey = $apiKey->value;

			$this->groupsApi = (new MailerLiteApi($this->apiKey))->groups();
			$group = (new MailerLiteApi($this->apiKey))->groups()->where(['name' => $shopper->getProjectUrl()])->get();

			$this->groupId = \count($group) == 0 || \count($group) > 1 ?
				$this->groupsApi->create(['name' => $shopper->getProjectUrl()])->id :
				$group->toArray()[0]->id;

			$this->subscribers = $this->groupsApi->getSubscribers($this->groupId);
		}

		$this->shopper = $shopper;
		$this->customerRepository = $customerRepository;
	}

	private function checkApi()
	{
		if (!$this->apiKey || !$this->groupsApi || !$this->groupId) {
			throw new \Exception('API connection error! Check API key.');
		}
	}

	public function subscribe(Customer $user)
	{
		$this->checkApi();

		$subscriber = [
			'email' => $user->email,
			'name' => $user->fullname,
		];

		$this->groupsApi->addSubscriber($this->groupId, $subscriber);
	}

	public function unsubscribe(Customer $user)
	{
		$this->checkApi();

		foreach ($this->subscribers as $subscriber) {
			if ($subscriber->email == $user->email) {
				$this->groupsApi->removeSubscriber($this->groupId, $subscriber->id);
				break;
			}
		}
	}

	public function syncCustomers()
	{
		foreach ($this->customerRepository->many() as $customer) {
			/** @var \Eshop\DB\Customer $customer */
			if ($customer->newsletter) {
				$this->subscribe($customer);
			} else {
				$this->unsubscribe($customer);
			}
		}
	}

}