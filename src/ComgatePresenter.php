<?php

namespace Eshop;

use Nette\Application\BadRequestException;
use Nette\Application\UI\Presenter;
use Tracy\Debugger;

class ComgatePresenter extends Presenter
{
	public function actionPaymentResult(): void
	{
		if ($this->request->getMethod() !== 'POST') {
			throw new BadRequestException();
		}
		
		$data = $this->request->getPost();
		
		if (!isset($data)) {
			throw new \Exception('No data from server');
		}
		
		Debugger::log($data);
	}
}
