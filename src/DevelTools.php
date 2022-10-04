<?php
declare(strict_types=1);

namespace Eshop;

class DevelTools
{
	public static function getPeakMemoryUsage(): string
	{
		$unit = array('b','kb','mb','gb','tb','pb');
		$size = \memory_get_peak_usage();

		return @\round($size / \pow(1024, ($i = \floor(\log($size, 1024)))), 2) . ' ' . $unit[$i];
	}

	public static function getCurrentMemoryUsage(): string
	{
		$unit = array('b','kb','mb','gb','tb','pb');
		$size = \memory_get_usage();

		return @\round($size / \pow(1024, ($i = \floor(\log($size, 1024)))), 2) . ' ' . $unit[$i];
	}
}
