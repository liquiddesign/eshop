<?php

namespace Eshop\Services\BalikobotApi\Providers;

use Eshop\Services\BalikobotApi\ApiConnection;
use Eshop\Services\BalikobotApi\DeliveryProviderInterface;
use Eshop\Services\BalikobotApi\Responses\GLSReturnShipmentResponse;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Tracy\Debugger;

readonly class GLSApi implements DeliveryProviderInterface
{
	public function __construct(private ApiConnection $apiConnection)
	{
	}

	public function orderReturnShipment(array $requestInfo): GLSReturnShipmentResponse
	{
		$response = $this->apiConnection->request(IRequest::Post, 'gls/b2a', $requestInfo);

		Debugger::barDump($response->getBody()->getContents());

		if ($response->getStatusCode() !== IResponse::S200_OK) {
			// TODO
			throw new \Exception();
		}

		$data = \json_decode($response->getBody()->getContents(), true)[0];

		return new GLSReturnShipmentResponse(
			$data['package_id'],
			$data['carrier_id'],
			$data['track_url'],
			$data['status_message'],
			$data['status'],
		);
	}
}
