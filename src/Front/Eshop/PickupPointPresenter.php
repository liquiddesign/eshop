<?php
declare(strict_types=1);

namespace Eshop\Front\Eshop;

use Eshop\Controls\IPickupPointListFactory;
use Eshop\Controls\PickupPointList;
use Grid\Datalist;

abstract class PickupPointPresenter extends \Eshop\Front\FrontendPresenter
{
	/** @inject */
	public IPickupPointListFactory $pickupPointListFactory;

	public function createComponentPickupPointList(): Datalist
	{
		$dataList = $this->pickupPointListFactory->create();
		$dataList->onAnchor[] = function (PickupPointList $dataList): void {
			$dataList->template->setFile(\dirname(__DIR__, 6) . '/app/Eshop/Controls/pickupPointList.latte');
		};

		/** @var \Forms\Form $form */
		$form = $dataList->getFilterForm();

		$form->onSuccess[] = function ($form): void {
			$this->redirect('this#anchor-search');
		};

		return $dataList;
	}
}
