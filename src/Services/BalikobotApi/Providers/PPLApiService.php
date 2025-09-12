<?php

namespace Eshop\Services\BalikobotApi\Providers;

use Base\Bridges\AutoWireService;
use Eshop\Services\BalikobotApi\ApiConnection;
use Eshop\Services\BalikobotApi\DeliveryProviderInterface;
use Eshop\Services\BalikobotApi\PackageInfo;
use Eshop\Services\BalikobotApi\Responses\PPLReturnShipmentResponse;
use Nette\Http\IRequest;
use Nette\Utils\Arrays;
use Tracy\Debugger;
use Tracy\ILogger;

readonly class PPLApiService implements DeliveryProviderInterface, AutoWireService
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

		// Exception out of valid states declared by API
		if (!Arrays::contains([200, 208, 400, 503], $response->getStatusCode())) {
			$errorMessage = \sprintf('PPLApi - Collection order API request failed: %d %s', $response->getStatusCode(), $response->getReasonPhrase());
			Debugger::log($errorMessage, ILogger::ERROR);

			throw new \RuntimeException($errorMessage);
		}

		$data = \json_decode($response->getBody()->getContents(), true)['packages'][0];
		$errors = null;

		if ($data['status'] === 400) {
			$errors = \implode('|', \array_column($data['errors'], 'message'));
		}

		return new PPLReturnShipmentResponse(
			$data['package_id'] ?? null,
			$data['status_message'] ?? $errors,
			$data['status'],
		);
	}

	public function getNoteCharacterLimit(): int
	{
		return 300;
	}
}
