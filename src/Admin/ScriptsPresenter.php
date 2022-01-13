<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Eshop\DB\WatcherRepository;
use Messages\DB\TemplateRepository;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Mail\Mailer;

class ScriptsPresenter extends \Admin\BackendPresenter
{
	/** @inject */
	public WatcherRepository $watcherRepository;

	/** @inject */
	public TemplateRepository $templateRepository;

	/** @inject */
	public Mailer $mailer;

	/** @inject */
	public Storage $storage;

	public function renderDefault(): void
	{
		$this->template->setFile(__DIR__ . '/templates/Scripts.default.latte');

		$this->template->scripts = [
			(object)[
				'name' => 'Odeslat aktivní hlídací psy',
				'link' => 'checkWatchers!',
				'info' => 'Odešle e-maily zákazníkům o případných změnách v dostupnosit jejich hlídaných produktů.',
			],
			(object)[
				'name' => 'Vymazat mezipaměť',
				'link' => 'clearCache!',
				'info' => 'Po vymazání může být první průchod eshopem pomalý!',
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
			Cache::TAGS => ['products', 'pricelists', 'categories', 'export'],
		]);

		$this->flashMessage('Provedeno', 'success');
	}
}
