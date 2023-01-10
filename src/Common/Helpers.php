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
}
