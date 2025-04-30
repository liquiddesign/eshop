<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Eshop\DB\ProductRepository;
use Eshop\DB\ProductsCacheStateRepository;
use Eshop\Services\ProductsCache\GeneralProductsCacheProvider;
use JetBrains\PhpStorm\Deprecated;
use Nette\DI\Attributes\Inject;
use Tracy\Debugger;

#[Deprecated('ProductsCache now only has one version which is regularly updated.')]
class ProductsCachePresenter extends \Eshop\BackendPresenter
{
	#[Inject]
	public ProductRepository $productRepository;

	#[Inject]
	public GeneralProductsCacheProvider $productsProvider;

	#[Inject]
	public ProductsCacheStateRepository $productsCacheStateRepository;

	public function renderDefault(): void
	{
		Debugger::$showBar = false;

		$this->template->cacheStates = $this->productsCacheStateRepository->many()->toArray();

		$this->template->setFile(__DIR__ . '/templates/ProductsCache.default.latte');
	}

	public function handleWarmUpCache(): void
	{
		Debugger::$showBar = false;

		$this->productsProvider->warmUpCacheTable();

		$this->flashMessage('Provedeno', 'success');
		$this->redirect('this');
	}
}
