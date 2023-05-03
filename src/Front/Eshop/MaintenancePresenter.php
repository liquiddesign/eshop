<?php
declare(strict_types=1);

namespace Eshop\Front\Eshop;

use Eshop\DB\AutoshipRepository;

abstract class MaintenancePresenter extends \Eshop\Front\FrontendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public AutoshipRepository $autoshipRepository;
	
	public function actionAutoships(): void
	{
		foreach ($this->autoshipRepository->many() as $autoship) {
			$this->autoshipRepository->createOrder($autoship);
		}
	}
}
