<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Eshop\Admin\Controls\IStatsControlFactory;
use Eshop\Admin\Controls\StatsControl;
use Eshop\DB\OrderRepository;

class StatsPresenter extends BackendPresenter
{
	/** @inject */
	public IStatsControlFactory $statsControlFactory;

	/** @inject */
	public OrderRepository $orderRepository;

	public function createComponentStats(): StatsControl
	{
		return $this->statsControlFactory->create();
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Statistiky';
		$this->template->headerTree = [
			['Statistiky'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('stats')];
	}

	public function handleClearOrdersPriceCache(): void
	{
		$this->orderRepository->clearOrdersTotalPrice();

		$this->flashMessage('Provedeno', 'success');
		$this->redirect('this');
	}
}
