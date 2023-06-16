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
}
