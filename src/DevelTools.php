<?php

declare(strict_types=1);

namespace Eshop;

use Nette\Application\UI\Form;
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
		$size = \memory_get_usage();

		return \round($size / \pow(1024, ($i = \floor(\log($size, 1024)))), 2) . ' ' . $unit[$i];
	}

	/**
	 * Returns SQL with all parameters replaced (fixes PdoDebugger replacing only first occurrence)
	 */
	public static function showCollection(ICollection $collection): string
	{
		$sql = $collection->getSql();

		foreach ($collection->getVars() as $key => $value) {
			if (\is_string($value)) {
				$replacement = "'" . $value . "'";
			} elseif (\is_array($value)) {
				$replacement = \implode(',', $value);
			} elseif ($value === null) {
				$replacement = 'NULL';
			} else {
				$replacement = (string) $value;
			}

			$sql = \str_replace(':' . $key, $replacement, $sql);
		}

		return $sql;
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

	public static function bdumpFormErrors(Form $form): void
	{
		$errors = [];

		/** @var \Nette\Forms\Control|\Nette\Forms\Container $component */
		foreach ($form->getComponents(true) as $component) {
			if (!$component->getErrors()) {
				continue;
			}

			$errors[$component->getName()] = $component->getErrors();
		}

		Debugger::barDump($errors);
	}
}
