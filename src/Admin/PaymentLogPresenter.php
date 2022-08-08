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
		$grid->addColumnText('Kód', 'externalCode', '%s', 'externalCode', ['class' => 'fit']);
		$grid->addColumnText('Částka', 'amount', '%s', 'amount', ['class' => 'fit']);
	
		$grid->addFilterTextInput('search', ['externalCode'], null, 'Kód');
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
