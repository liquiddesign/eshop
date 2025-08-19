<?php

namespace Eshop\Services\BalikobotApi\Providers;

use Eshop\Services\BalikobotApi\ApiConnection;
use Eshop\Services\BalikobotApi\DeliveryProviderInterface;
use Eshop\Services\BalikobotApi\PackageInfo;
use Eshop\Services\BalikobotApi\Responses\PPLReturnShipmentResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Tracy\Debugger;
use Tracy\ILogger;

readonly class PPLApi implements DeliveryProviderInterface
{
	public function __construct(private ApiConnection $apiConnection)
	{
	}

	public function orderReturnShipment(PackageInfo $packageInfo): PPLReturnShipmentResponse
	{
		$response = $this->apiConnection->request(
			IRequest::Post,
			'ppl/b2a',
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
					'service_type' => '1',
					'pickup_date' => $packageInfo->getPickupDate()->format('Y-m-d'),
					'return_full_errors' => 1,
					'pieces_count' => $packageInfo->getPiecesCount(),
					'note' => $packageInfo->getNote(),
				],
			]
		);

		if ($response->getStatusCode() !== IResponse::S200_OK) {
			$errorMessage = \sprintf('PPLApi - Collection order API request failed: %d %s', $response->getStatusCode(), $response->getReasonPhrase());
			Debugger::log($errorMessage, ILogger::ERROR);

			throw new \RuntimeException($errorMessage);
		}

		$data = \json_decode($response->getBody()->getContents(), true)['packages'][0];

		return new PPLReturnShipmentResponse(
			$data['package_id'],
			$data['status_message'],
			$data['status'],
		);
	}

	public function getNoteCharacterLimit(): int
	{
		return 300;
	}
}
