<?php

declare(strict_types=1);

namespace Eshop\Providers;

use Nette\Utils\Arrays;
use Nette\Utils\Json;
use Nette\Utils\Strings;

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
	 * @return array<string>
	 */
	public static function convertToArray($item): array
	{
		return \json_decode(\json_encode((array) $item), true);
	}

	/**
	 * @param string $json
	 * @return array<mixed>
	 */
	public static function convertJsonToArray(string $json): array
	{
		return Json::decode($json, Json::FORCE_ARRAY);
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
			'cache_wsdl' => \WSDL_CACHE_NONE,
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
			if (!Arrays::contains($closedTags, $openedTags[$i])) {
				$html .= '</' . $openedTags[$i] . '>';
			} else {
				unset($closedTags[\array_search($openedTags[$i], $closedTags)]);
			}
		}
		
		return $html;
	}

	/**
	 * @param string $fullName
	 * @return array<string> Two strings as firstName and lastName
	 */
	public static function parseFullName(string $fullName): array
	{
		$explodedName = \explode(' ', $fullName);

		$firstName = null;

		if (\count($explodedName) > 1) {
			$last = Arrays::last(\array_keys($explodedName));

			foreach ($explodedName as $id => $name) {
				if ($id === $last) {
					break;
				}

				$firstName .= $name . ' ';
			}

			$firstName = Strings::substring($firstName, 0, -1);
		}

		$lastName = null;

		if (\count($explodedName) > 1) {
			$lastName = Arrays::last($explodedName);
		}

		return [$firstName, $lastName];
	}
}
