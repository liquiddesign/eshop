<?php

declare(strict_types=1);

namespace Eshop\Providers;

abstract class Helpers
{
	public static function getRemoteFilemtime(string $imageUrl): ?int
	{
		$curl = \curl_init($imageUrl);
		
		\curl_setopt($curl, \CURLOPT_NOBODY, true);
		\curl_setopt($curl, \CURLOPT_SSL_VERIFYPEER, false);
		\curl_setopt($curl, \CURLOPT_RETURNTRANSFER, true);
		\curl_setopt($curl, \CURLOPT_FILETIME, true);
		
		$result = \curl_exec($curl);
		
		if ($result === false) {
			return null;
		}
		
		$timestamp = \curl_getinfo($curl, \CURLINFO_FILETIME);
		
		if ($timestamp !== -1) {
			return (int) $timestamp;
		}
		
		return null;
	}
	
	public static function parsePrice(string $floatVal): float
	{
		return (float) \str_replace(',', '.', $floatVal);
	}
	
	public static function getVat(float $price, float $priceVat, int $rounding = 2): float
	{
		return \round((($priceVat / $price) - 1) * 100, $rounding);
	}
	
	public static function getVatPrice(float $price, float $vat, int $rounding = 2): float
	{
		return \round($price * (100 + $vat) / 100, $rounding);
	}

	public static function getNoVatPrice(float $price, float $vat, int $rounding = 2): float
	{
		return \round($price * (100 - $vat) / 100, $rounding);
	}

	/**
	 * @param mixed $item
	 * @return string[]
	 */
	public static function convertToArray($item): array
	{
		return \json_decode(\json_encode((array) $item), true);
	}
	
	public static function createSoapClient(string $url, ?string $login = null, ?string $password = null): \SoapClient
	{
		\ini_set('default_socket_timeout', '5000');
		
		$context = \stream_context_create([
			'ssl' => [
				'verify_peer' => false,
				'verify_peer_name' => false,
				'allow_self_signed' => true,
				'keep_alive' => true,
				'connection_timeout' => 5000,
			],
		]);
		
		$options = [
			'stream_context' => $context,
			'trace' => 1,
		];
		
		if ($login && $password) {
			$options['login'] = $login;
			$options['password'] = $password;
		}
		
		return new \SoapClient($url, $options);
	}
	
	public static function getFileByPost(string $url, array $postData): ?string
	{
		$postData = \http_build_query($postData);
		
		$opts = [
			'http' => [
				'method' => 'POST',
				'header' => 'Content-Type: application/x-www-form-urlencoded',
				'content' => $postData,
			],
		];
		
		$context = \stream_context_create($opts);
		
		return \Nette\Utils\Helpers::falseToNull(\file_get_contents($url, false, $context));
	}
	
	public static function removeDuplicateBr(string $html): string
	{
		return \preg_replace('/(<br>\s*|<br \/>\s*|<\/br>\s*){3,}/i', '<br><br>', $html);
	}
	
	public static function removeHtmlAttribute(string $html, $attribute): string
	{
		return \preg_replace('/(<[^>]+) ' . $attribute . '=".*?"/i', '$1', $html);
	}
	
	public static function fixUnclosedDivs(string $html): string
	{
		\preg_match_all('#<(div)(?: .*)?(?<![/|/ ])>#iU', $html, $result);
		$openedTags = $result[1];
		\preg_match_all('#</(div)>#iU', $html, $result);
		$closedTags = $result[1];
		$lenOpened = \count($openedTags);
		
		if (\count($closedTags) === $lenOpened) {
			return $html;
		}
		
		$openedTags = \array_reverse($openedTags);

		for ($i = 0; $i < $lenOpened; $i++) {
			if (!\in_array($openedTags[$i], $closedTags)) {
				$html .= '</' . $openedTags[$i] . '>';
			} else {
				unset($closedTags[\array_search($openedTags[$i], $closedTags)]);
			}
		}
		
		return $html;
	}
}
