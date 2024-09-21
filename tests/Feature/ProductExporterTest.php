<?php

$container = createContainer();

$productExporter = $container->getByType(\Eshop\Common\Services\ProductExporter::class);
/** @var \Eshop\DB\ProductRepository $productRepository */
$productRepository = $container->getByType(\Eshop\DB\ProductRepository::class);

test('basic', function () use ($container, $productExporter, $productRepository): void {
	$products = $productRepository->many()->setTake(100);

	$tempFilename = \tempnam($container->getParameter('tempDir'), 'csv');

	$writer = \League\Csv\Writer::createFromPath($tempFilename);

	$productsCollection = clone $products;
	$productsArray = $productsCollection->setIndex('code')->toArray();

	$productExporter->exportCsv($products, $writer, ['code' => 'Kód', 'name_cs' => 'Název'], header: ['Kód', 'Název']);

	$reader = \League\Csv\Reader::createFromPath($tempFilename);
	$reader->setDelimiter(';');
	$reader->setHeaderOffset(0);

	expect($reader)
		->count()->toEqual($products->count())
		->getHeader()->toContain('Kód', 'Název');

	foreach ($reader->getRecords() as $record) {
		expect($productsArray)->toHaveKey($record['Kód']);

		$product = $productsArray[$record['Kód']];

		expect($record['Kód'])->toBe($product->code)
			->and($record['Název'])->toBe($product->name);
	}

	\Nette\Utils\FileSystem::delete($tempFilename);
});