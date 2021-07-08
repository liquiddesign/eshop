<?php

namespace Eshop\Integration;

class Comgate
{
	private array $config;

	private string $paymentUrl = 'https://payments.comgate.cz/v1.0/create';

	private ?string $transactionId = null;

	private ?string $redirectUrl = null;

	private array $statusParams = array();

	public function setConfig(array $config): void
	{
		$this->config = $config;
	}

	private function encodeParams()
	{
		$data = '';

		foreach ($this->config as $key => $val) {
			$data .= ($data === '' ? '' : '&') . \urlencode($key) . '=' . \urlencode((string)$val);
		}

		return $data;
	}

	private function decodeParams($data)
	{
		$encodedParams = \explode('&', $data);
		$params = array();

		foreach ($encodedParams as $encodedParam) {
			$encodedPair = \explode('=', $encodedParam);
			$paramName = \urlencode($encodedPair[0]);
			$paramValue = (\count($encodedPair) == 2 ? \urldecode($encodedPair[1]) : '');
			$params[$paramName] = $paramValue;
		}

		return $params;
	}

	private function checkParam($params, $paramName)
	{
		if (!isset($params[$paramName])) {
			throw new \Exception('Missing response parameter: ' . $paramName);
		}

		return $params[$paramName];
	}

	public function doHttpPost($url, $data)
	{
		$c = \curl_init();
		\curl_setopt($c, CURLOPT_URL, $url);
		\curl_setopt($c, CURLOPT_POST, 1);
		\curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		\curl_setopt($c, CURLOPT_HEADER, 0);
		\curl_setopt($c, CURLOPT_POSTFIELDS, $data);
		$responseBody = curl_exec($c);
		\curl_close($c);

		return $responseBody;
	}

	public function createTransaction(float $price, string $label, string $orderNumber, string $productName): void
	{
		$this->config['price'] = $price;
		$this->config['label'] = $label;
		$this->config['refId'] = $orderNumber;
		$this->config['name'] = $productName;

		$requestBody = $this->encodeParams();
		bdump($requestBody);
		$responseBody = $this->doHttpPost($this->paymentUrl, $requestBody);

		bdump($responseBody);
		$responseParams = $this->decodeParams($responseBody);
		$responseCode = $this->checkParam($responseParams, 'code');
		$responseMessage = $this->checkParam($responseParams, 'message');

		if ($responseCode != '0' || $responseMessage !== 'OK') {
			throw new \Exception('Transaction creation error ' . $responseCode . ': ' . $responseMessage);
		}

		$this->transactionId = $this->checkParam($responseParams, 'transId');
		$this->redirectUrl = $this->checkParam($responseParams, 'redirect');
	}

	public function checkTransactionStatus($params)
	{
		$this->statusParams = array();

		if (
			!isset($params) ||
			!\is_array($params) ||
			!isset($params['merchant']) || $params['merchant'] === '' ||
			!isset($params['test']) || $params['test'] === '' ||
			!isset($params['price']) || $params['price'] === '' ||
			!isset($params['curr']) || $params['curr'] === '' ||
			!isset($params['refId']) || $params['refId'] === '' ||
			!isset($params['transId']) || $params['transId'] === '' ||
			!isset($params['secret']) || $params['secret'] === '' ||
			!isset($params['status']) || $params['status'] === ''
		) {
			throw new \Exception('Missing parameters');
		}

		if (
			$params['merchant'] !== $this->_merchant ||
			$params['test'] !== ($this->_test ? 'true' : 'false') ||
			$params['secret'] !== $this->_secret
		) {
			throw new \Exception('Invalid merchant identification');
		}

		$this->statusParams = $params;
	}

	public function getTransactionId(): string
	{
		return $this->transactionId;
	}

	public function getRedirectUrl(): string
	{
		return $this->redirectUrl;
	}

	public function getTransactionStatus(): string
	{
		return $this->statusParams['status'];
	}
}
