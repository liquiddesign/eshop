<?php

namespace Eshop\Integration;

use Eshop\Shopper;
use MailerLiteApi\MailerLite as MailerLiteApi;
use Eshop\DB\Customer;
use Web\DB\SettingRepository;

class MailerLite
{
	private ?string $apiKey;

	private Shopper $shopper;

	public function __construct(SettingRepository $settingRepository, Shopper $shopper)
	{
		if ($apiKey = $settingRepository->many()->where('name = "mailerLiteApiKey"')->first()) {
			$this->apiKey = $apiKey->value;
		}

		$this->shopper = $shopper;
	}

	public function subscribe(Customer $user)
	{
		if(!$this->apiKey){
			throw new \Exception('Missing API key! Set it in Admin.');
		}

		$groupsApi = (new MailerLiteApi($this->apiKey))->groups();

		$subscriber = [
			'email' => 'john@example.com',
			'fields' => [
				'name' => 'John',
				'last_name' => 'Doe',
				'company' => 'John Doe Co.'
			]
		];

		$response = $groupsApi->addSubscriber($this->shopper->getProjectUrl(), $subscriber); // Change GROUP_ID with ID of group you want to add subscriber to

	}
}