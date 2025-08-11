<?php

namespace Eshop\Services\BalikobotApi;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

class ApiConnection
{
	private Client $client;

	public function __construct(
		private readonly string $baseUrl,
		private readonly string $login,
		private readonly string $password
	)
	{
		$this->client = new Client();
	}

	public function request(string $method, string $endpoint, array $params = []): ResponseInterface
	{
		return $this->client->request($method, $this->baseUrl . $endpoint, [
			'auth' => [$this->login, $this->password],
			'json' => $params,
			'http_errors' => false,
		]);
	}
}