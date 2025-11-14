<?php

declare(strict_types=1);

namespace Eshop\Helpers;

class SqlHelper
{
	/**
	 * Escapes special LIKE wildcard characters (%, _, \) for use in SQL LIKE queries.
	 * Use with ESCAPE '\\\\' clause in your SQL query.
	 *
	 * @param string $value The value to escape
	 * @return string The escaped value safe for LIKE queries
	 *
	 * @example
	 * $escaped = SqlHelper::escapeLikeWildcards($userInput);
	 * $collection->where('name LIKE :query ESCAPE \'\\\\\'', ['query' => '%' . $escaped . '%']);
	 */
	public static function escapeLikeWildcards(string $value): string
	{
		$value = \str_replace('\\', '\\\\', $value);
		$value = \str_replace('%', '\\%', $value);

		return \str_replace('_', '\\_', $value);
	}
}
