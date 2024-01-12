<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Eshop\DB\ProductRepository;
use Eshop\DB\ProductsCacheStateRepository;
use Eshop\GeneralProductProvider;
use Nette\DI\Attributes\Inject;
use Tracy\Debugger;

class ProductsCachePresenter extends \Eshop\BackendPresenter
{
	#[Inject]
	public ProductRepository $productRepository;

	#[Inject]
	public GeneralProductProvider $productsProvider;

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
