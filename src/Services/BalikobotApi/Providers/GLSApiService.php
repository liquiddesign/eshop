<?php

namespace Eshop\Services\BalikobotApi\Providers;

use Base\Bridges\AutoWireService;
use Eshop\Services\BalikobotApi\ApiConnection;
use Eshop\Services\BalikobotApi\DeliveryProviderInterface;
use Eshop\Services\BalikobotApi\PackageInfo;
use Eshop\Services\BalikobotApi\Responses\GLSReturnShipmentResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Utils\Arrays;
use Nette\Utils\Json;
use Nette\Utils\Strings;
use Tracy\Debugger;
use Tracy\ILogger;

readonly class GLSApiService implements DeliveryProviderInterface, AutoWireService
{
	public function __construct(private ApiConnection $apiConnection)
	{
	}

	public function orderReturnShipment(PackageInfo $packageInfo): GLSReturnShipmentResponse
	{
		$requestData = [
			'packages' => [
				'eid' => $packageInfo->getId(),
				'rec_name' => Strings::substring($packageInfo->getRecipientName(), 0, 59),
				'rec_phone' => $packageInfo->getRecipientPhone(),
				'rec_email' => $packageInfo->getRecipientEmail(),
				'rec_street' => $packageInfo->getStreetAddress(),
				'rec_city' => $packageInfo->getCity(),
				'rec_zip' => $packageInfo->getZipCode(),
				'rec_country' => $packageInfo->getCountryCode(),
				'rec_firm' => Strings::substring($packageInfo->getRecipientCompany(), 0, 59),
				'del_insurance' => false,
				'note' => $packageInfo->getNote(),
				'pickup_date' => $packageInfo->getPickupDate()->format('Y-m-d'),
				'service_type' => '1',
			],
		];

		$response = $this->apiConnection->request(
			IRequest::Post,
			'gls/b2a',
			$requestData,
		);

		// Exception out of valid states declared by API
		if (!Arrays::contains([200, 208, 400, 503], $response->getStatusCode())) {
			Debugger::log(Json::encode([
				'request' => ['endpoint' => 'gls/b2a', 'data' => $requestData],
				'response' => ['code' => $response->getStatusCode(), 'reason' => $response->getReasonPhrase(), 'data' => $response->getBody()->getContents()],
			]), 'gls-api');

			$errorMessage = \sprintf('GLSApi - Collection order API request failed: %d %s. More in "gls-api" log.', $response->getStatusCode(), $response->getReasonPhrase());
			Debugger::log($errorMessage, ILogger::ERROR);

			throw new \RuntimeException($errorMessage);
		}

		$data = \json_decode($response->getBody()->getContents(), true)['packages'][0];
		$errors = null;

		if ($data['status'] === 400 && isset($data['errors'])) {
			$errors = \implode('|', \array_column($data['errors'], 'message'));
		}

		return new GLSReturnShipmentResponse(
			$data['package_id'] ?? null,
			$data['carrier_id'] ?? $data['carrer_id'] ?? null,
			$data['track_url'] ?? null,
			$data['status_message'] ?? $errors,
			$data['status'],
		);
	}

	/**
	 * @param array<\Eshop\Services\BalikobotApi\PackageInfo> $packageInfos
	 * @return array<\Eshop\Services\BalikobotApi\Responses\GLSReturnShipmentResponse>
	 */
	public function orderManyReturnShipment(array $packageInfos): array
	{
		if (\count($packageInfos) === 1) {
			throw new \InvalidArgumentException('GLSApi - orderManyReturnShipment() method requires at least 2 packages. Use orderReturnShipment() instead.');
		}

		$packages = [];
		$i = 1;

		foreach ($packageInfos as $packageInfo) {
			$packages[] = [
				'eid' => $packageInfo->getId(),
				'rec_name' => Strings::substring($packageInfo->getRecipientName(), 0, 59),
				'rec_phone' => $packageInfo->getRecipientPhone(),
				'rec_email' => $packageInfo->getRecipientEmail(),
				'rec_street' => $packageInfo->getStreetAddress(),
				'rec_city' => $packageInfo->getCity(),
				'rec_zip' => $packageInfo->getZipCode(),
				'rec_country' => $packageInfo->getCountryCode(),
				'rec_firm' => Strings::substring($packageInfo->getRecipientCompany(), 0, 59),
				'del_insurance' => false,
				'note' => $packageInfo->getNote(),
				'pickup_date' => $packageInfo->getPickupDate()->format('Y-m-d'),
				'service_type' => '1',
				'order_number' => $i++,
			];
		}

		$response = $this->apiConnection->request(
			IRequest::Post,
			'gls/b2a',
			[
				'packages' => $packages,
			]
		);

		if ($response->getStatusCode() !== IResponse::S200_OK) {
			Debugger::barDump($response->getBody()->getContents());
			$errorMessage = \sprintf('GLSApi - Collection order API request failed: %d %s', $response->getStatusCode(), $response->getReasonPhrase());
			Debugger::log($errorMessage, ILogger::ERROR);

			throw new \RuntimeException($errorMessage);
		}

		$packagesData = \json_decode($response->getBody()->getContents(), true)['packages'];

		$responses = [];

		foreach ($packagesData as $packageData) {
			$errors = null;

			if ($packageData['status'] === 400 && isset($packageData['errors'])) {
				$errors = \implode('|', \array_column($packageData['errors'], 'message'));
			}

			$responses[] = new GLSReturnShipmentResponse(
				$packageData['package_id'] ?? null,
				$packageData['carrier_id'] ?? $packageData['carrer_id'] ?? null,
				$packageData['track_url'] ?? null,
				$packageData['status_message'] ?? $errors,
				$packageData['status'],
			);
		}

		return $responses;
	}

	public function getNoteCharacterLimit(): int
	{
		return 64;
	}
}
