<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Eshop\DB\ProductRepository;
use Eshop\DB\WatcherRepository;
use Messages\DB\TemplateRepository;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Mail\Mailer;

class ScriptsPresenter extends \Admin\BackendPresenter
{
	public const ATTRIBUTES_CACHE_TAG = 'attributes';
	public const PRODUCERS_CACHE_TAG = 'producers';
	public const PRODUCTS_CACHE_TAG = 'products';
	public const PRICELISTS_CACHE_TAG = 'pricelists';
	public const CATEGORIES_CACHE_TAG = 'categories';
	public const EXPORT_CACHE_TAG = 'export';
	public const SETTINGS_CACHE_TAG = 'settings';

	#[\Nette\DI\Attributes\Inject]
	public WatcherRepository $watcherRepository;

	#[\Nette\DI\Attributes\Inject]
	public TemplateRepository $templateRepository;

	#[\Nette\DI\Attributes\Inject]
	public Mailer $mailer;

	#[\Nette\DI\Attributes\Inject]
	public Storage $storage;

	#[\Nette\DI\Attributes\Inject]
	public ProductRepository $productRepository;

	public function renderDefault(): void
	{
		$this->template->setFile(__DIR__ . '/templates/Scripts.default.latte');

		$this->template->scripts = [
			(object) [
				'name' => 'Vymazat mezipaměť',
				'link' => 'clearCache!',
				'info' => 'Po vymazání může být první průchod eshopem pomalý!',
			],
			(object) [
				'name' => 'Odeslat aktivní hlídací psy',
				'link' => 'checkWatchers!',
				'info' => 'Odešle e-maily zákazníkům o případných změnách v dostupnosit jejich hlídaných produktů.',
			],
			(object) [
				'name' => 'Doplnit chybějící hlavní obrázky',
				'link' => 'fixMissingMainImages!',
				'info' => 'Vybere všechny produkty, které NEMAJÍ nastavený hlavní obrázek a pokud mají alespoň jeden obrázek tak nastaví náhodně hlavní obrázek.',
			],
		];
	}

	public function handleCheckWatchers(): void
	{
		$this->watcherRepository->getChangedAmountWatchers(true);

		$this->flashMessage('Provedeno', 'success');
	}

	public function handleClearCache(): void
	{
		$cache = new Cache($this->storage);

		$cache->clean([
			Cache::Tags => [
				self::PRODUCTS_CACHE_TAG,
				self::PRICELISTS_CACHE_TAG,
				self::CATEGORIES_CACHE_TAG,
				self::EXPORT_CACHE_TAG,
				self::ATTRIBUTES_CACHE_TAG,
				self::PRODUCERS_CACHE_TAG,
				self::SETTINGS_CACHE_TAG,
			],
		]);

		$this->flashMessage('Provedeno', 'success');
	}

	public function handleFixMissingMainImages(): void
	{
		$i = 0;

		/** @var \Eshop\DB\Product $product */
		foreach ($this->productRepository->many()
					 ->where('this.imageFileName IS NULL')
					 ->join(['gallery' => 'eshop_photo'], 'this.uuid = gallery.fk_product')
					 ->select(['galleryFilename' => 'gallery.filename'])
					 ->where('gallery.uuid IS NOT NULL') as $product) {
			$product->update([
				'imageFileName' => $product->getValue('galleryFilename'),
			]);

			$i++;
		}

		$this->flashMessage('Proveno<br>Aktualizováno produktů: ' . $i, 'success');
	}
}
