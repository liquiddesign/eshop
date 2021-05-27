<?php

namespace Eshop\Integration;

use Eshop\DB\CatalogPermission;
use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\CustomerRepository;
use Eshop\Shopper;
use MailerLiteApi\Api\Groups;
use MailerLiteApi\Api\Subscribers;
use MailerLiteApi\MailerLite as MailerLiteApi;
use Nette\Utils\Validators;
use Web\DB\SettingRepository;

class MailerLite
{
	private ?string $apiKey;

	private Shopper $shopper;

	private CustomerRepository $customerRepository;

	private CatalogPermissionRepository $catalogPermissionRepository;

	private Groups $groupsApi;

	private Subscribers $subscribersApi;

	private array $groups;

	private array $subscribers;

	public function __construct(SettingRepository $settingRepository, Shopper $shopper, CustomerRepository $customerRepository, CatalogPermissionRepository $catalogPermissionRepository)
	{
		if ($apiKey = $settingRepository->many()->where('name = "mailerLiteApiKey"')->first()) {
			$this->apiKey = $apiKey->value;
			$this->subscribersApi = (new MailerLiteApi($this->apiKey))->subscribers();
			$this->groupsApi = (new MailerLiteApi($this->apiKey))->groups();
			$groups = $this->groupsApi->get()->toArray();

			foreach ($groups as $group) {
				$this->groups[$group->id] = $group;
				$this->subscribers[$group->id] = $this->groupsApi->getSubscribers($group->id);
			}
		}

		$this->shopper = $shopper;
		$this->customerRepository = $customerRepository;
		$this->catalogPermissionRepository = $catalogPermissionRepository;
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

	public function unsubscribeAllFromAllGroups()
	{
		foreach ($this->groups as $group) {
			$subscribers = $this->getSubscribers($group->name);

			foreach ($subscribers as $subscriber) {
				$this->groupsApi->removeSubscriber($group->id, $subscriber->id);
			}
		}
	}

	public function syncCustomers()
	{
		$this->unsubscribeAllFromAllGroups();

		foreach ($this->customerRepository->many() as $customer) {
			/** @var \Eshop\DB\Customer $customer */

			if ($customer->newsletter && $customer->newsletterGroup) {
				$this->subscribe($customer->email, $customer->fullname, $customer->newsletterGroup);
			}

			foreach ($customer->accounts as $account) {
				if (!Validators::isEmail($account->login)) {
					continue;
				}

				/** @var CatalogPermission $catalogPerm */
				$catalogPerm = $this->catalogPermissionRepository->many()->where('fk_account', $account->getPK())->first();

				if ($catalogPerm->newsletter && $catalogPerm->getNewsletterGroup()) {
					$this->subscribe($account->login, $account->fullname, $catalogPerm->getNewsletterGroup());
				}
			}
		}
	}

}