<?php

namespace Eshop\Integration;

use Eshop\DB\CatalogPermissionRepository;
use Eshop\DB\CustomerRepository;
use Eshop\ShopperUser;
use MailerLiteApi\Api\Groups;
use MailerLiteApi\Api\Subscribers;
use MailerLiteApi\MailerLite as MailerLiteApi;
use Nette\Utils\Validators;
use Web\DB\SettingRepository;

/**
 * @deprecated Old class, no alternative
 */
class MailerLite
{
	public Subscribers $subscribersApi;

	private ?string $apiKey = null;

	private Groups $groupsApi;

	/**
	 * @var array<\stdClass>
	 */
	private array $groups;

	/**
	 * @var array<string, array<\stdClass>>
	 */
	private array $subscribers;

	public function __construct(
		private readonly SettingRepository $settingRepository,
		public ShopperUser $shopperUser,
		public CustomerRepository $customerRepository,
		private readonly CatalogPermissionRepository $catalogPermissionRepository
	) {
	}

	/**
	 * @throws \MailerLiteApi\Exceptions\MailerLiteSdkException|\Exception
	 */
	public function unsubscribe(string $email, string $groupName): void
	{
		$this->checkApi();

		$group = $this->getGroupByName($groupName);
		$subscribers = $this->getSubscribers($group->name);

		foreach ($subscribers as $subscriber) {
			if ($subscriber->email === $email) {
				$this->groupsApi->removeSubscriber($group->id, $subscriber->id);

				break;
			}
		}
	}

	public function unsubscribeFromAllGroups(string $email): void
	{
		foreach ($this->groups as $group) {
			$subscribers = $this->getSubscribers($group->name);

			foreach ($subscribers as $subscriber) {
				if ($subscriber->email === $email) {
					$this->groupsApi->removeSubscriber($group->id, $subscriber->id);

					break;
				}
			}
		}
	}

	/**
	 * @throws \Exception
	 */
	public function syncCustomers(): void
	{
		$this->checkApi();

		$this->unsubscribeAllFromAllGroups();

		/** @var \Eshop\DB\CatalogPermission $catalogPerm */
		foreach ($this->catalogPermissionRepository->many()->where('newsletter', true)->where('newsletterGroup != "" AND newsletterGroup IS NOT NULL') as $catalogPerm) {
			if (!Validators::isEmail($catalogPerm->account->login)) {
				continue;
			}

			$this->subscribe($catalogPerm->account->login, $catalogPerm->account->fullname, 'group');
		}
	}

	/**
	 * @throws \Exception
	 */
	public function unsubscribeAllFromAllGroups(): void
	{
		$this->checkApi();

		foreach ($this->groups as $group) {
			$subscribers = $this->getSubscribers($group->name);

			foreach ($subscribers as $subscriber) {
				$this->groupsApi->removeSubscriber($group->id, $subscriber->id);
			}
		}
	}

	/**
	 * @throws \MailerLiteApi\Exceptions\MailerLiteSdkException|\Exception
	 */
	public function subscribe(string $email, ?string $name, string $groupName): void
	{
		$this->checkApi();

		$subscriber = [
			'email' => $email,
			'name' => $name,
		];

		$group = $this->getGroupByName($groupName);

		$this->groupsApi->addSubscriber($group->id, $subscriber);
	}

	/**
	 * @throws \Exception
	 */
	private function checkApi(): void
	{
		if (!$this->apiKey) {
			$this->initApi();

			if (!$this->apiKey) {
				throw new \Exception('API connection error! Check API key.');
			}
		}
	}

	/**
	 * @throws \MailerLiteApi\Exceptions\MailerLiteSdkException
	 * @throws \StORM\Exception\NotFoundException
	 */
	private function initApi(): void
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

	/**
	 * Get group, if groupName doesnt exist creates new.
	 * @param string $groupName
	 */
	private function getGroupByName(string $groupName): mixed
	{
		foreach ($this->groups as $group) {
			if ($group->name === $groupName) {
				return $group;
			}
		}

		$group = $this->groupsApi->create(['name' => $groupName]);
		$this->groups[$group->id] = $group;

		return $group;
	}

	/**
	 * @param string $groupName
	 * @return array<\stdClass>
	 */
	private function getSubscribers(string $groupName): array
	{
		$group = $this->getGroupByName($groupName);

		return $this->subscribers[$group->id] ?? [];
	}
}
