<?php
declare(strict_types=1);

namespace Eshop;

use StORM\ICollection;
use Tracy\Debugger;

class DevelTools
{
	public static function getPeakMemoryUsage(): string
	{
		return \number_format(\memory_get_peak_usage() / 1000000, 2, '.', ' ') . ' MB';
	}

	public static function getCurrentMemoryUsage(): string
	{
		$unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];
		$size = \memory_get_usage(true);

		return \round($size / \pow(1024, ($i = \floor(\log($size, 1024)))), 2) . ' ' . $unit[$i];
	}

	/**
	 * Returns SQL
	 * @param \StORM\ICollection $collection
	 */
	public static function showCollection(ICollection $collection): string
	{
		return \PdoDebugger::show($collection->getSql(), $collection->getVars());
	}

	/**
	 * Dumps SQL
	 * @param \StORM\ICollection $collection
	 */
	public static function dumpCollection(ICollection $collection): void
	{
		Debugger::dump(self::showCollection($collection));
	}

	/**
	 * Dumps SQL
	 * @param \StORM\ICollection $collection
	 */
	public static function bdumpCollection(ICollection $collection): void
	{
		Debugger::barDump(self::showCollection($collection));
	}
}
