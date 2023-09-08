<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Eshop\DB\ProductRepository;
use Eshop\DB\ProductsCacheStateRepository;
use Eshop\ProductsProvider;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\DI\Attributes\Inject;

class ProductsCachePresenter extends \Admin\BackendPresenter
{
	#[Inject]
	public Storage $storage;

	#[Inject]
	public ProductRepository $productRepository;

	#[Inject]
	public ProductsProvider $productsProvider;

	#[Inject]
	public ProductsCacheStateRepository $productsCacheStateRepository;

	public function renderDefault(): void
	{
		$this->template->cacheStates = $this->productsCacheStateRepository->many()->toArray();

		$this->template->setFile(__DIR__ . '/templates/ProductsCache.default.latte');
	}

	public function handleWarmUpCache(): void
	{
		$this->productsProvider->warmUpCacheTable();

		$cache = new Cache($this->storage);

		$cache->clean([
			Cache::Tags => [
				ScriptsPresenter::PRODUCTS_CACHE_TAG,
				ScriptsPresenter::PRICELISTS_CACHE_TAG,
				ScriptsPresenter::CATEGORIES_CACHE_TAG,
				ScriptsPresenter::EXPORT_CACHE_TAG,
				ScriptsPresenter::ATTRIBUTES_CACHE_TAG,
				ScriptsPresenter::PRODUCERS_CACHE_TAG,
				ScriptsPresenter::SETTINGS_CACHE_TAG,
			],
		]);

		$this->flashMessage('Provedeno', 'success');
		$this->redirect('this');
	}
}
