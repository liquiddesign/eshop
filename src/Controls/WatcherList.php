<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\Shopper;
use Eshop\DB\WatcherRepository;
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
	
	public function __construct(WatcherRepository $watcherRepository, Shopper $shopper, DIConnection $connection)
	{
		parent::__construct($watcherRepository->getWatchersByCustomer($shopper->getCustomer()));
	
		$this->watcherRepository = $watcherRepository;
		$this->setDefaultOnPage(20);
		
		$langSuffix = $connection->getMutationSuffix();
		
		$this->addFilterExpression('productName', function (ICollection $collection, $value) use ($langSuffix): void {
			$collection->where("products.name$langSuffix LIKE :query", ['query' => '%' . $value . '%']);
		}, '');
		
		$this->getFilterForm()->addText('productName');
		$this->getFilterForm()->addSubmit('submit');
	}
	
	public function handleDeleteWatcher(string $watcherId): void
	{
		try {
			$this->watcherRepository->one($watcherId)->delete();
		} catch (NotFoundException $e) {
		}
		
		$this->redirect('this');
	}
	
	public function render(): void
	{
		$this->template->render($this->template->getFile() ?: __DIR__ . '/watcherList.latte');
	}
}
