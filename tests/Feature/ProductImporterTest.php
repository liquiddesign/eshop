<?php

$container = createContainer();

/** @var \Eshop\Common\Services\ProductImporter $productImporter $productImporter */
$productImporter = $container->getByType(\Eshop\Common\Services\ProductImporter::class);
/** @var \Eshop\DB\ProductRepository $productRepository */
$productRepository = $container->getByType(\Eshop\DB\ProductRepository::class);

test('basic', function () use ($container, $productImporter, $productRepository): void {
	$filename = __DIR__ . '/../data/product_import_basic.csv';
	$productRepository->many()->where('code', '123')->delete();

	$productImporter->importCsv($filename, addNew: true, searchCriteria: 'code', importColumns: ['code' => 'Kód', 'ean' => 'EAN']);

	$product = $productRepository->many()->where('code', '123')->first();
	expect($product)->not->toBeNull();
	expect($product->code)->toBe('123')->and($product->ean)->toBe('123456789');
});
