<?php

namespace Eshop\Admin\Controls\Customer;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use DaveLiddament\PhpLanguageExtensions\RestrictTraitTo;
use Eshop\DB\Customer;
use Eshop\DB\Product;
use Nette\Application\UI\Presenter;

#[RestrictTraitTo(BackendPresenter::class)]
trait FavouriteProductsTrait
{
	public function renderEditFavouriteProducts(Customer $customer): void
	{
		$this->template->headerLabel = 'Oblíbené produkty - ' . $customer->getName();
		$this->template->headerTree = [
			['Zákazníci', 'default'],
			['Oblíbené produkty'],
		];
		$this->template->displayButtons = [$this->createBackButton('default'), $this->createButton2('edit', 'Zákazník', linkArgs: [$customer])];
		$this->template->displayControls = [$this->getComponent('editFavouriteProducts')];
	}

	public function createComponentEditFavouriteProducts(): AdminForm
	{
		/** @var \Eshop\DB\Customer $customer */
		$customer = $this->getParameter('customer');

		$form = $this->formFactory->create(defaultGroup: false);

		$form->monitor(Presenter::class, function () use ($form, $customer): void {
			$productInput = $form->addMultiSelectAjax('favouriteProducts', 'Oblíbené produkty', 'Zvolte produkt', Product::class, ['maximumSelectionLength' => 300]);

			if ($customer) {
				$this->template->select2AjaxDefaults[$productInput->getHtmlId()] = $customer->getFavouriteProducts()->toArrayOf('name');
			}

			$form->addSubmit('submit', 'Uložit');
		});

		$form->onSuccess[] = function (AdminForm $form) use ($customer): void {
			$values = $form->getValuesWithAjax();

			$customer->getFavouriteProducts()->unrelateAll();
			$customer->getFavouriteProducts()->relate($values['favouriteProducts']);

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('this', [$customer]);
		};

		return $form;
	}
}
