<?php

declare(strict_types=1);

namespace Eshop\Front\Web;

use Eshop\DB\CartRepository;
use Eshop\DB\Product;
use Eshop\DB\VisibilityListItemRepository;
use Eshop\DB\WatcherRepository;
use Eshop\Services\ProductsCache\GeneralProductsCacheProvider;
use Nette\Application\BadRequestException;
use Nette\DI\Attributes\Inject;
use Nette\DI\Container;
use Nette\IOException;
use Nette\Utils\FileSystem;
use StORM\DIConnection;
use Tracy\Debugger;
use Web\DB\SettingRepository;

abstract class SandboxPresenter extends \Eshop\Front\FrontendPresenter
{
	#[Inject]
	public SettingRepository $settingRepository;

	#[Inject]
	public WatcherRepository $watcherRepository;

	#[Inject]
	public CartRepository $cartRepository;

	#[Inject]
	public DIConnection $stm;

	#[Inject]
	public Container $container;

	#[Inject]
	public VisibilityListItemRepository $visibilityListItemRepository;

	#[Inject]
	public GeneralProductsCacheProvider $productsProvider;

	public function actionDefault(): void
	{
		$this->stm->setDebug(false);

		if (!$id = $this->getParameter('id')) {
			throw new BadRequestException();
		}

		if (!$this->tryCall($id, [])) {
			throw new BadRequestException();
		}

		if (!$this->container->getParameters()['trustedMode'] && !$this->container->getParameters()['debugMode']) {
			throw new BadRequestException();
		}

		$this->terminate();
	}

	public function watchers(): void
	{
		Debugger::dump($this->watcherRepository->getChangedAmountWatchers());
		Debugger::dump($this->watcherRepository->getChangedPriceWatchers());
	}

	public function carts(): void
	{
		Debugger::dump($this->cartRepository->getLostCarts());
	}

	public function createMissingVisibilityListItems(): void
	{
		$products = $this->productRepository->many();

		while ($product = $products->fetch()) {
			$this->visibilityListItemRepository->syncOne([
				'product' => $product->getPK(),
				'visibilityList' => 'main',
			], []);
		}
	}

	protected function imageCopy(string $originName, string $newName): bool
	{
		$imagesDirThumb = $this->container->getParameters()['wwwDir'] . '/userfiles/' . Product::GALLERY_DIR . '/thumb/';
		$imagesDirDetail = $this->container->getParameters()['wwwDir'] . '/userfiles/' . Product::GALLERY_DIR . '/detail/';
		$imagesDirOrigin = $this->container->getParameters()['wwwDir'] . '/userfiles/' . Product::GALLERY_DIR . '/origin/';

		try {
			if (!\is_file($imagesDirThumb . $originName)) {
				return false;
			}

			FileSystem::copy($imagesDirThumb . $originName, $imagesDirThumb . $newName);
		} catch (IOException $exception) {
			return false;
		}

		try {
			if (!\is_file($imagesDirDetail . $originName)) {
				return false;
			}

			FileSystem::copy($imagesDirDetail . $originName, $imagesDirDetail . $newName);
		} catch (IOException $exception) {
			return false;
		}

		try {
			if (!\is_file($imagesDirOrigin . $originName)) {
				return false;
			}

			FileSystem::copy($imagesDirOrigin . $originName, $imagesDirOrigin . $newName);
		} catch (IOException $exception) {
			return false;
		}

		return true;
	}
}
