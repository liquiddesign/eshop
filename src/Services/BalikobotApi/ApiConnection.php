<?php

namespace Eshop\Services\BalikobotApi;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

class ApiConnection
{
	private ?Client $client = null;

	public function __construct(
		private readonly string $baseUrl,
		private readonly string $login,
		private readonly string $password
	) {
	}

	public function request(string $method, string $endpoint, array $params = []): ResponseInterface
	{
		if ($this->client === null) {
			$this->client = new Client();
		}

		return $this->client->request($method, $this->baseUrl . $endpoint, [
			'auth' => [$this->login, $this->password],
			'json' => $params,
			'http_errors' => false,
		]);
	}
}
