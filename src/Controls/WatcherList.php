<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\WatcherRepository;
use Eshop\Shopper;
use Grid\Datalist;
use StORM\DIConnection;
use StORM\Exception\NotFoundException;
use StORM\ICollection;

/**
 * Class WatcherList
 * @package Eshop\Controls
 */
class WatcherList extends Datalist
{
	private WatcherRepository $watcherRepository;

	private bool $email;
	
	public function __construct(WatcherRepository $watcherRepository, Shopper $shopper, DIConnection $connection, bool $email = false)
	{
		parent::__construct($watcherRepository->getWatchersByCustomer($shopper->getCustomer()));

		$this->email = $email;
	
		$this->watcherRepository = $watcherRepository;
		$this->setDefaultOnPage(20);
		
		$langSuffix = $connection->getMutationSuffix();
		
		$this->addFilterExpression('productName', function (ICollection $collection, $value) use ($langSuffix): void {
			$collection->where("products.name$langSuffix LIKE :query", ['query' => '%' . $value . '%']);
		}, '');

		/** @var \Forms\Form $filterForm */
		$filterForm = $this->getFilterForm();

		$filterForm->addText('productName');
		$filterForm->addSubmit('submit');
	}
	
	public function handleDeleteWatcher(string $watcherId): void
	{
		try {
			$this->watcherRepository->delete($this->watcherRepository->one($watcherId), $this->email);
		} catch (NotFoundException $e) {
		}
		
		$this->redirect('this');
	}
	
	public function render(): void
	{
		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render($template->getFile() ?: __DIR__ . '/watcherList.latte');
	}
}
