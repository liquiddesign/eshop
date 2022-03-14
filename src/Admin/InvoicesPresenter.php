<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\Invoice;
use Eshop\DB\InvoiceRepository;
use Eshop\DB\OrderRepository;
use Forms\Form;
use Grid\Datagrid;
use Messages\DB\TemplateRepository;
use Nette\Application\LinkGenerator;
use Nette\Forms\Controls\Button;
use Nette\Mail\Mailer;
use Nette\Utils\Arrays;
use Nette\Utils\Html;

class InvoicesPresenter extends BackendPresenter
{
	/** @inject */
	public InvoiceRepository $invoiceRepository;
	
	/** @inject */
	public OrderRepository $orderRepository;

	/** @inject */
	public LinkGenerator $linkGenerator;

	/** @inject */
	public Mailer $mailer;

	/** @inject */
	public TemplateRepository $templateRepository;
	
	public function createComponentGrid(): AdminGrid
	{
		$btnSecondary = 'btn btn-sm btn-outline-primary';

		$grid = $this->gridFactory->create($this->invoiceRepository->getCollection(), 20, 'code', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'fit']);
		$grid->addColumn('Objednávka', function (Invoice $invoice): ?string {
			if ($invoice->getValue('ordersCodes') === null) {
				return null;
			}

			$orders = \explode(',', $invoice->getValue('ordersCodes'));
			$last = Arrays::last($orders);

			$ordersString = '';

			foreach ($orders as $order) {
				$link = $this->link(':Eshop:Admin:Order:default', ['ordersGrid-search_order' => $order]);
				$orderCode = $last === $order ? $order : "$order,&nbsp";

				$ordersString .= "<a href='$link'>$orderCode</a>";
			}

			return \substr($ordersString, 0, -2);
		}, '%s', 'order.code');
		$grid->addColumn('Veřejná URL', function (Invoice $invoice): ?string {
			try {
				$link = $this->linkGenerator->link('Eshop:Export:invoice', ['id' => $invoice->id, 'hash' => $invoice->hash]);

				return "<a href=\"$link\" target=\"_blank\">$link</a>";
			} catch (\Nette\Application\UI\InvalidLinkException $e) {
				\bdump($e);

				return null;
			}
		});
		
		$grid->addColumnLinkDetail('detail');
		$grid->addColumnActionDelete();

		$grid->addButtonDeleteSelected(null, false, null, 'this.uuid');

		$grid->getForm()->addSubmit('demandMultiple', Html::fromHtml('<i class="fa fa-meteor"></i>&nbsp;Urgovat'))
			->setHtmlAttribute('class', $btnSecondary)
			->onClick[] = [$this, 'demandMultiple'];

		$grid->getForm()->addSubmit('notifyMultiple', Html::fromHtml('<i class="fa fa-bell"></i>&nbsp;Notifikovat'))
			->setHtmlAttribute('class', $btnSecondary)
			->onClick[] = [$this, 'notifyMultiple'];
		
		$grid->addFilterTextInput('search', ['code'], null, 'Kód');
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function createComponentForm(): Form
	{
		/** @var \Eshop\DB\Invoice|null $invoice */
		$invoice = $this->getParameter('invoice');

		$form = $this->formFactory->create();
		
		$form->addText('code', 'Kód')->setRequired();
		$form->addDate('exposed', 'Datum vystavení')->setRequired();
		$form->addDate('taxDate', 'Datum zdanitelného plnění')->setRequired();
		$form->addDate('dueDate', 'Datum splatnosti')->setRequired();
		$form->addSelect2('order', 'Objednávka', $this->orderRepository->many()->toArrayOf('code'))->setRequired()->setDisabled((bool) $invoice);
		
		$form->addGroup('Stav faktury');
		$form->addDate('paidDate', 'Zaplaceno')->setNullable();
		$form->addDate('canceled', 'Storno')->setNullable();
	
		$form->addSubmits();
		
		$form->onSuccess[] = function (AdminForm $form) use ($invoice): void {
			$values = $form->getValues('array');

			$invoice = $invoice ? $this->invoiceRepository->syncOne($values) :
				$this->invoiceRepository->createFromOrder($this->orderRepository->one(Arrays::pick($values, 'order')), $values);

			
			$this->flashMessage('Uloženo', 'success');
			
			$form->processRedirect('detail', 'default', [$invoice]);
		};
		
		return $form;
	}
	
	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Faktury';
		$this->template->headerTree = [
			['Faktury', 'default'],
		];
		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová faktura';
		$this->template->headerTree = [
			['Faktury', 'default'],
			['Nová faktura'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
		$this->template->activeTab = 'default';
	}
	
	public function renderDetail(Invoice $invoice): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Faktury', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [
			$this->createBackButton('default'),
			$this->createButtonWithClass('demand!', '<i class="fas fa-meteor"></i>&nbsp;Urgovat', 'btn btn-sm btn-outline-primary'),
			$this->createButtonWithClass('notify!', '<i class="fas fa-bell"></i>&nbsp;Notifikovat', 'btn btn-sm btn-outline-primary'),
		];

		try {
			$link = $this->linkGenerator->link('Eshop:Export:invoice', ['id' => $invoice->id, 'hash' => $invoice->hash]);

			$this->template->displayButtons[] = '<a href="' . $link . '" target="_blank"><button class="btn btn-sm btn-outline-primary"><i class="fas fa-print"></i>&nbsp;Tisková sestava</button></a>';
		} catch (\Nette\Application\UI\InvalidLinkException $e) {
			\bdump($e);
		}

		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function handleDemand(Invoice $invoice): void
	{
		try {
			$mail = $this->templateRepository->createMessage('invoice.demand', $invoice->getEmailVariables(), $invoice->customer->email);
			$this->mailer->send($mail);

			$this->flashMessage('Odesláno', 'success');
		} catch (\Throwable $e) {
			\bdump($e);

			$this->flashMessage('Nelze odeslat email!', 'error');
		}

		$this->redirect('this');
	}

	public function handleNotify(Invoice $invoice): void
	{
		try {
			$mail = $this->templateRepository->createMessage('invoice.notify', $invoice->getEmailVariables(), $invoice->customer->email);
			$this->mailer->send($mail);

			$this->flashMessage('Odesláno', 'success');
		} catch (\Throwable $e) {
			\bdump($e);

			$this->flashMessage('Nelze odeslat email!', 'error');
		}

		$this->redirect('this');
	}
	
	public function actionDetail(Invoice $invoice): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');

		$values = $invoice->toArray(['orders', 'items']);
		$values['order'] = Arrays::first($values['orders']);

		$form->setDefaults($values);
	}

	public function demandMultiple(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);

		foreach ($grid->getSelectedIds() as $id) {
			$this->demandInvoice($grid->getSource()->where('this.uuid', $id)->first());
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function notifyMultiple(Button $button): void
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);

		foreach ($grid->getSelectedIds() as $id) {
			$this->notifyInvoice($grid->getSource()->where('this.uuid', $id)->first());
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	private function demandInvoice(Invoice $invoice): void
	{
		try {
			$mail = $this->templateRepository->createMessage('invoice.demand', $invoice->getEmailVariables(), $invoice->customer->email);
			$this->mailer->send($mail);
		} catch (\Throwable $e) {
		}
	}

	private function notifyInvoice(Invoice $invoice): void
	{
		try {
			$mail = $this->templateRepository->createMessage('invoice.notify', $invoice->getEmailVariables(), $invoice->customer->email);
			$this->mailer->send($mail);
		} catch (\Throwable $e) {
		}
	}
}
