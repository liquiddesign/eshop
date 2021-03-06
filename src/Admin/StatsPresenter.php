<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use App\Admin\PresenterTrait;
use Eshop\Admin\Controls\IStatsControlFactory;
use Eshop\Admin\Controls\StatsControl;

class StatsPresenter extends BackendPresenter
{
	/** @inject */
	public IStatsControlFactory $statsControlFactory;

	public function createComponentStats(): StatsControl
	{
		$control = $this->statsControlFactory->create();

		return $control;
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
}