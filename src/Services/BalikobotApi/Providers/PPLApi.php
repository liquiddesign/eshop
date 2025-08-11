<?php

namespace Eshop\Services\BalikobotApi\Providers;

use Eshop\Services\BalikobotApi\ApiConnection;
use Eshop\Services\BalikobotApi\DeliveryProviderInterface;
use Eshop\Services\BalikobotApi\Responses\PPLReturnShipmentResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Http\Request;

readonly class PPLApi implements DeliveryProviderInterface
{
	public function __construct(private ApiConnection $apiConnection)
	{
	}

	function orderReturnShipment(array $requestInfo): PPLReturnShipmentResponse
	{
		$response = $this->apiConnection->request(IRequest::Post, 'ppl/b2a', (array) $requestInfo);

		if ($response->getStatusCode() !== IResponse::S200_OK) {
			throw new \Exception(); // TODO
		}

		$data = \json_decode($response->getBody()->getContents(), true)[0];

		return new PPLReturnShipmentResponse(
			$data['package_id'],
			$data['carrier_id'],
			$data['status_message'],
			$data['status'],
		);
	}
}