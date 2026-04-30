<?php

require __DIR__ . '/vendor/autoload.php';

$engine = new Latte\Engine;

$engine->addExtension(new \Nette\Bridges\ApplicationLatte\UIExtension(null));
$engine->addExtension(new \Nette\Bridges\FormsLatte\FormsExtension());
$engine->addExtension(new Latte\Essential\TranslatorExtension(fn($val) => $val));
$engine->addExtension(new \Nette\Bridges\CacheLatte\CacheExtension(new \Nette\Caching\Storages\DevNullStorage()));
$engine->addExtension(new \Latte\Essential\RawPhpExtension());

$linter = new Latte\Tools\Linter($engine);

$path = 'src';
$it = new RecursiveDirectoryIterator($path);
$it = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
$it = new RegexIterator($it, '~\.latte$~');

$counter = 0;
$errors = 0;

foreach ($it as $file) {
	$file = (string) $file;
	if (!$linter->lintLatte($file)) {
		$errors++;
	}
	$counter++;
}

echo "Done (checked $counter files, found errors in $errors)\n";
exit($errors > 0 ? 1 : 0);