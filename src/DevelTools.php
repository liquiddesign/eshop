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
