<?php


namespace Eshop\Integration;


use Web\DB\SettingRepository;

class MailerLite
{
	private string $apiKey;

	public function __construct(SettingRepository $settingRepository)
	{
	}
}