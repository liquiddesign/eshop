<?php

namespace Eshop\Integration;

use Eshop\DB\CustomerRepository;
use Eshop\Shopper;
use MailerLiteApi\Api\Groups;
use MailerLiteApi\MailerLite as MailerLiteApi;
use Eshop\DB\Customer;
use Nette\Forms\Validator;
use Nette\Utils\Arrays;
use Nette\Utils\Validators;
use Web\DB\SettingRepository;

class MailerLite
{
	private ?string $apiKey;

	private Shopper $shopper;

	private CustomerRepository $customerRepository;

	private Groups $groupsApi;

	private array $groups;

	private array $subscribers;

	public function __construct(SettingRepository $settingRepository, Shopper $shopper, CustomerRepository $customerRepository)
	{
		if ($apiKey = $settingRepository->many()->where('name = "mailerLiteApiKey"')->first()) {
			$this->apiKey = $apiKey->value;
			$this->groupsApi = (new MailerLiteApi($this->apiKey))->groups();
			$groups = $this->groupsApi->get()->toArray();

			foreach ($groups as $group) {
				$this->groups[$group->id] = $group;
				$this->subscribers[$group->id] = $this->groupsApi->getSubscribers($group->id);
			}
		}

		$this->shopper = $shopper;
		$this->customerRepository = $customerRepository;
	}

	private function checkApi()
	{
		if (!$this->apiKey) {
			throw new \Exception('API connection error! Check API key.');
		}
	}

	/**
	 * Get group, if groupName doesnt exist creates new.
	 * @param string $groupName
	 * @throws \MailerLiteApi\Exceptions\MailerLiteSdkException
	 */
	private function getGroupByName(string $groupName)
	{
		foreach ($this->groups as $group) {
			if ($group->name == $groupName) {
				return $group;
			}
		}

		$group = $this->groupsApi->create(['name' => $groupName]);
		$this->groups[$group->id] = $group;

		return $group;
	}

	private function getSubscribers(string $groupName): array
	{
		$group = $this->getGroupByName($groupName);

		return isset($this->subscribers[$group->id]) ? $this->subscribers[$group->id] : [];
	}

	public function subscribe(string $email, ?string $name, string $groupName)
	{
		$this->checkApi();

		$subscriber = [
			'email' => $email,
			'name' => $name,
		];

		$group = $this->getGroupByName($groupName);

		$this->groupsApi->addSubscriber($group->id, $subscriber);
	}

	public function unsubscribe(string $email, string $groupName)
	{
		$this->checkApi();

		$group = $this->getGroupByName($groupName);
		$subscribers = $this->getSubscribers($group->name);

		foreach ($subscribers as $subscriber) {
			if ($subscriber->email == $email) {
				$this->groupsApi->removeSubscriber($group->id, $subscriber->id);
				break;
			}
		}
	}

	public function unsubscribeFromAllGroups(string $email)
	{
		foreach ($this->groups as $group) {
			$subscribers = $this->getSubscribers($group->name);

			foreach ($subscribers as $subscriber) {
				if ($subscriber->email == $email) {
					$this->groupsApi->removeSubscriber($group->id, $subscriber->id);
					break;
				}
			}
		}
	}

	public function syncCustomers()
	{
		foreach ($this->customerRepository->many() as $customer) {
			/** @var \Eshop\DB\Customer $customer */
			$this->unsubscribeFromAllGroups($customer->email);

			if ($customer->newsletter && $customer->newsletterGroup) {
				$this->subscribe($customer->email, $customer->fullname, $customer->newsletterGroup);
			}

			foreach ($customer->accounts as $account) {
				if (!Validators::isEmail($account->login)) {
					continue;
				}

				$this->unsubscribeFromAllGroups($account->login);

				if ($account->newsletter && $account->newsletterGroup) {
					$this->subscribe($account->login, $account->fullname, $account->newsletterGroup);
				}
			}
		}
	}

}