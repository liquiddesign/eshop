<?php
declare(strict_types=1);

namespace Eshop;

use Admin\Controls\AdminGrid;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Nette\Application\Application;
use Nette\DI\Attributes\Inject;
use PdoDebugger;
use StORM\Entity;
use StORM\LogItem;
use Tracy\Debugger;

abstract class BackendPresenter extends \Admin\BackendPresenter
{
	#[Inject]
	public ProductRepository $productRepository;

	#[Inject]
	public CategoryRepository $categoryRepository;

	#[Inject]
	public AttributeValueRepository $attributeValueRepository;

	#[Inject]
	public CustomerRepository $customerRepository;

	#[Inject]
	public Application $application;

	public function afterRender(): void
	{
		\Tracy\Debugger::$maxLength = 100000;

		$this->application->onShutdown[] = function (): void {
			if (($debug = $this->getRequest()->getParameter('debug')) !== null) {
				$logItems = $this->connection->getLog();

				\uasort($logItems, function (LogItem $a, LogItem $b): int {
					return $b->getTotalTime() <=> $a->getTotalTime();
				});

				$totalTime = 0;
				$totalAmount = 0;

				$logItems = \array_filter($logItems, function (LogItem $item) use (&$totalTime, &$totalAmount, $debug): bool {
					$totalTime += $item->getTotalTime();
					$totalAmount += $item->getAmount();

					if ($debug === '0') {
						return true;
					}

					return $item->getTotalTime() > 0.01;
				});

				Debugger::dump($totalTime);
				Debugger::dump($totalAmount);

				foreach ($logItems as $logItem) {
					Debugger::dump($logItem);
					Debugger::dump(PdoDebugger::show($logItem->getSql(), $logItem->getVars()));
				}
			}
		};
	}

	public function handleGetProductsForSelect2(?string $q = null, ?int $page = null): void
	{
		if (!$q) {
			$this->payload->results = [];
			$this->sendPayload();
		}

		$suffix = $this->productRepository->getConnection()->getMutationSuffix();

		/** @var array<\Eshop\DB\Product> $products */
		$products = $this->productRepository->getCollection(true)
			->where("this.name$suffix LIKE :q OR this.code = :exact OR this.ean = :exact", ['q' => "%$q%", 'exact' => $q,])
			->setPage($page ?? 1, 5)
			->toArrayOf('name');

		$results = [];

		foreach ($products as $pk => $name) {
			$results[] = [
				'id' => $pk,
				'text' => $name,
			];
		}

		$this->payload->results = $results;
		$this->payload->pagination = ['more' => \count($products) === 5];

		$this->sendPayload();
	}

	public function handleGetCategoriesForSelect2(?string $q = null, ?int $page = null): void
	{
		if (!$q) {
			$this->payload->results = [];
			$this->sendPayload();
		}

		$suffix = $this->categoryRepository->getConnection()->getMutationSuffix();

		$categories = $this->categoryRepository->toArrayForSelect(
			$this->categoryRepository->getCollection(true)
				->where("this.name$suffix LIKE :q OR this.code LIKE :q", ['q' => "%$q%"])
				->setPage($page ?? 1, 5)
		);

		$results = [];

		foreach ($categories as $pk => $category) {
			$results[] = [
				'id' => $pk,
				'text' => $category,
			];
		}

		$this->payload->results = $results;
		$this->payload->pagination = ['more' => \count($categories) === 5];

		$this->sendPayload();
	}

	/**
	 * @throws \Nette\Application\AbortException
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function handleGetTonerProductsForSelect2(?string $q = null, ?Product $product = null, ?int $page = null): void
	{
		if (!$q) {
			$this->payload->result = [];
			$this->sendPayload();
		}

		/** @var \Eshop\DB\Category $printerCategory */
		$printerCategory = $this->categoryRepository->one('printers');

		$suffix = $this->productRepository->getConnection()->getMutationSuffix();

		$printers = $this->productRepository->getCollection(true)
			->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'this.uuid = nxnCategory.fk_product')
			->join(['category' => 'eshop_category'], 'nxnCategory.fk_category = category.uuid')
			->where('category.path LIKE :categoryPath', ['categoryPath' => $printerCategory->path . '%'])
			->where("this.name$suffix LIKE :q OR this.code = :exact OR this.ean = :exact", ['q' => "%$q%", 'exact' => $q,])
			->setPage($page ?? 1, 5);

		if ($product) {
			$printers->where('this.uuid != :thisProduct', ['thisProduct' => $product->getPK()]);
		}

		$payload = [];

		/** @var \Eshop\DB\Product $printer */
		foreach ($printers as $printer) {
			$payload[] = [
				'id' => $printer->getPK(),
				'text' => $printer->name,
			];
		}

		$this->payload->results = $payload;
		$this->sendPayload();
	}

	public function handleGetAttributeValues(?string $q = null, ?int $page = null): void
	{
		if (!$q) {
			$this->payload->result = [];
			$this->sendPayload();
		}

		$payload = $this->attributeValueRepository->getAttributesForAdminAjax($q, $page);

		$this->payload->results = $payload['results'];
		$this->payload->pagination = $payload['pagination'];

		$this->sendPayload();
	}

	public function onDeleteImagePublic(Entity $object, string $propertyName = 'imageFileName'): void
	{
		$this->onDeleteImage($object, $propertyName);
	}

	protected function getBulkFormActionLink(): string
	{
		return $this->link('this', ['selected' => $this->getParameter('selected')]);
	}

	/**
	 * @return array<string>
	 */
	protected function getBulkFormIds(): array
	{
		return $this->getParameter('ids') ?: [];
	}

	protected function getBulkFormGrid(string $name): AdminGrid
	{
		/** @var \Admin\Controls\AdminGrid $grid */
		$grid = $this->getComponent($name);

		return $grid;
	}
}
