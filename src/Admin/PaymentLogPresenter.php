<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminGrid;
use Eshop\DB\PaymentLogRepository;

class PaymentLogPresenter extends BackendPresenter
{
	/** @inject */
	public PaymentLogRepository $paymentLogRepository;
	
	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->paymentLogRepository->many(), 20, 'created', 'DESC', true);
		
		$grid->addColumnText('ID', 'externalId', '%s', 'externalId', ['class' => 'fit']);
		$grid->addColumnText('Datum', 'created|date', '%s', 'created', ['class' => 'fit']);
		
		$grid->addColumnText('VS', 'externalCode', '%s', 'externalCode');
		
		$grid->addColumnText('Částka', ['amount|price::currency.code'], '%s', 'amount')->onRenderCell[] = [$grid, 'decoratorNumber'];;
		
		$grid->addColumnText('Protiúčet', 'countermeasure', '%s', 'countermeasure');
		$grid->addColumnText('Poznámka', 'note', '%s', 'note');
		
		$grid->addFilterTextInput('search', ['externalCode', 'countermeasure', 'note'], null, 'VS, protiúčet, poznámka');
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Platební transakce';
		$this->template->headerTree = [
			['Platební transakce', 'default'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
}
