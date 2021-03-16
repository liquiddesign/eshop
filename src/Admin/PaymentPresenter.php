<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use App\Admin\PresenterTrait;
use Eshop\DB\Order;

class PaymentPresenter extends BackendPresenter
{
	public function actionOrderPayments(Order $order)
	{
	}
	
	public function renderOrderPayments()
	{
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('cartsGrid')];
	}
	
}