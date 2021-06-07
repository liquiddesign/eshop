<?php

namespace Eshop\Integration;

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
	private ?string $apiKey = null;

	private Shopper $shopper;

	private CustomerRepository $customerRepository;

	private CatalogPermissionRepository $catalogPermissionRepository;

	private SettingRepository $settingRepository;

	private Groups $groupsApi;

	private Subscribers $subscribersApi;

	private array $groups;

	private array $subscribers;

	public function __construct(SettingRepository $settingRepository, Shopper $shopper, CustomerRepository $customerRepository, CatalogPermissionRepository $catalogPermissionRepository)
	{
		$this->shopper = $shopper;
		$this->customerRepository = $customerRepository;
		$this->catalogPermissionRepository = $catalogPermissionRepository;
		$this->settingRepository = $settingRepository;
	}

	private function initApi()
	{
		if ($apiKey = $this->settingRepository->many()->where('name = "mailerLiteApiKey"')->first()) {
			$this->apiKey = $apiKey->value;
			$this->subscribersApi = (new MailerLiteApi($this->apiKey))->subscribers();
			$this->groupsApi = (new MailerLiteApi($this->apiKey))->groups();
			$groups = $this->groupsApi->get()->toArray();

			$this->groups = [];
			$this->subscribers = [];

			foreach ($groups as $group) {
				$this->groups[$group->id] = $group;
				$this->subscribers[$group->id] = $this->groupsApi->getSubscribers($group->id);
			}
		}
	}

	private function checkApi()
	{
		if (!$this->apiKey) {
			$this->initApi();

			if (!$this->apiKey) {
				throw new \Exception('API connection error! Check API key.');
			}
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
		$this->checkApi();

		foreach ($this->groups as $group) {
			$subscribers = $this->getSubscribers($group->name);

			foreach ($subscribers as $subscriber) {
				$this->groupsApi->removeSubscriber($group->id, $subscriber->id);
			}
		}
	}

	public function syncCustomers()
	{
		$this->checkApi();

		$this->unsubscribeAllFromAllGroups();

		foreach ($this->catalogPermissionRepository->many()->where('newsletter', true)->where('newsletterGroup != "" AND newsletterGroup IS NOT NULL') as $catalogPerm) {
			if (!Validators::isEmail($catalogPerm->account->login)) {
				continue;
			}

			$this->subscribe($catalogPerm->account->login, $catalogPerm->account->fullname, $catalogPerm->newsletterGroup);
		}
	}

}