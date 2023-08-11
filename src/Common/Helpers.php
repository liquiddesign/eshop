<?php

namespace Eshop\Common;

class Helpers
{
	/**
	 * @return array<string, string|null>
	 */
	public static function replaceArrayValue(array $array, $value, $replace): array
	{
		return \array_replace($array, \array_fill_keys(\array_keys($array, $value), $replace));
	}

	/**
	 * Converts array of strings to SQL IN statement
	 * @param array<string> $array
	 */
	public static function arrayToSqlInStatement(array $array): string
	{
		return \implode("','", $array);
	}

	public static function removeEmoji(string $string): string
	{

		/**
		 * @see https://unicode.org/charts/PDF/UFE00.pdf
		 */
		$variantSelectors = '[\x{FE00}–\x{FE0F}]?';

		/**
		 * There are many sets of modifiers
		 * such as skin color modifiers and etc
		 *
		 * Not used, because this range already included
		 * in 'Match Miscellaneous Symbols and Pictographs' range
		 * $skin_modifiers = '[\x{1F3FB}-\x{1F3FF}]';
		 *
		 * Full list of modifiers:
		 * https://unicode.org/emoji/charts/full-emoji-modifiers.html
		 */

		// Match Enclosed Alphanumeric Supplement
		$regexAlphanumeric = "/[\x{1F100}-\x{1F1FF}]$variantSelectors/u";
		$clearString = \preg_replace($regexAlphanumeric, '', $string);

		// Match Miscellaneous Symbols and Pictographs
		$regexSymbols = "/[\x{1F300}-\x{1F5FF}]$variantSelectors/u";
		$clearString = \preg_replace($regexSymbols, '', $clearString);

		// Match Emoticons
		$regexEmoticons = "/[\x{1F600}-\x{1F64F}]$variantSelectors/u";
		$clearString = \preg_replace($regexEmoticons, '', $clearString);

		// Match Transport And Map Symbols
		$regexTransport = "/[\x{1F680}-\x{1F6FF}]$variantSelectors/u";
		$clearString = \preg_replace($regexTransport, '', $clearString);

		// Match Supplemental Symbols and Pictographs
		$regexSupplemental = "/[\x{1F900}-\x{1F9FF}]$variantSelectors/u";
		$clearString = \preg_replace($regexSupplemental, '', $clearString);

		// Match Miscellaneous Symbols
		$regexMisc = "/[\x{2600}-\x{26FF}]$variantSelectors/u";
		$clearString = \preg_replace($regexMisc, '', $clearString);

		// Match Dingbats
		$regexDingbats = "/[\x{2700}-\x{27BF}]$variantSelectors/u";
		$clearString = \preg_replace($regexDingbats, '', $clearString);

		return $clearString;
	}
}
