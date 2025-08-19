<?php

namespace Eshop\Services\BalikobotApi\Providers;

use Eshop\Services\BalikobotApi\ApiConnection;
use Eshop\Services\BalikobotApi\DeliveryProviderInterface;
use Eshop\Services\BalikobotApi\PackageInfo;
use Eshop\Services\BalikobotApi\Responses\GLSReturnShipmentResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Tracy\Debugger;
use Tracy\ILogger;

readonly class GLSApi implements DeliveryProviderInterface
{
	public function __construct(private ApiConnection $apiConnection)
	{
	}

	public function orderReturnShipment(PackageInfo $packageInfo): GLSReturnShipmentResponse
	{
		//TODO PPL má parameter pieces count ktorý udáva koľko balíkov bude ready na odoslanie. Ale čo GLS? Mám do requestu pridať N balíkov podla zadaného údaju?

		$response = $this->apiConnection->request(
			IRequest::Post,
			'gls/b2a',
			[
				'packages' => [
					'eid' => $packageInfo->getId(),
					'rec_name' => $packageInfo->getRecipientName(),
					'rec_phone' => $packageInfo->getRecipientPhone(),
					'rec_email' => $packageInfo->getRecipientEmail(),
					'rec_street' => $packageInfo->getStreetAddress(),
					'rec_city' => $packageInfo->getCity(),
					'rec_zip' => $packageInfo->getZipCode(),
					'rec_country' => $packageInfo->getCountryCode(),
					'rec_firm' => $packageInfo->getRecipientCompany(),
					'del_insurance' => false,
					'note' => $packageInfo->getNote(),
					'pickup_date' => $packageInfo->getPickupDate()->format('Y-m-d'),
					'service_type' => '1',
				],
			]
		);

		if ($response->getStatusCode() !== IResponse::S200_OK) {
			Debugger::barDump($response->getBody()->getContents());
			$errorMessage = \sprintf('GLSApi - Collection order API request failed: %d %s', $response->getStatusCode(), $response->getReasonPhrase());
			Debugger::log($errorMessage, ILogger::ERROR);

			throw new \RuntimeException($errorMessage);
		}

		$data = \json_decode($response->getBody()->getContents(), true)['packages'][0];

		return new GLSReturnShipmentResponse(
			$data['package_id'],
			$data['carrier_id'],
			$data['track_url'],
			$data['status_message'],
			$data['status'],
		);
	}

	public function getNoteCharacterLimit(): int
	{
		return 64;
	}
}
