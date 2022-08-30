<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\BackendPresenter;
use Eshop\DB\Invoice;
use Eshop\DB\InvoiceRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\PaymentTypeRepository;
use Eshop\Shopper;
use Forms\Form;
use Grid\Datagrid;
use Messages\DB\TemplateRepository;
use Nette\Application\LinkGenerator;
use Nette\Application\UI\Presenter;
use Nette\Forms\Controls\Button;
use Nette\Mail\Mailer;
use Nette\Utils\Arrays;
use Nette\Utils\Html;
use StORM\Collection;
use StORM\ICollection;

class InvoicesPresenter extends BackendPresenter
{
	/** @inject */
	public InvoiceRepository $invoiceRepository;
	
	/** @inject */
	public OrderRepository $orderRepository;

	/** @inject */
	public LinkGenerator $linkGenerator;

	/** @inject */
	public PaymentTypeRepository $paymentTypeRepository;

	/** @inject */
	public Mailer $mailer;

	/** @inject */
	public TemplateRepository $templateRepository;

	/** @inject */
	public Shopper $shopper;
	
	public function createComponentGrid(): AdminGrid
	{
		$btnSecondary = 'btn btn-sm btn-outline-primary';

		$grid = $this->gridFactory->create($this->invoiceRepository->getCollection(true), 20, 'this.code', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'code');
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
				$link = $this->linkGenerator->link('Eshop:Export:invoice', ['hash' => $invoice->hash]);

				return "<a href=\"$link\" target=\"_blank\">$link</a>";
			} catch (\Nette\Application\UI\InvalidLinkException $e) {
				\bdump($e);

				return null;
			}
		});

		$grid->addColumn('Tisk', function (Invoice $invoice, AdminGrid $datagrid) {
			return '<i class="fas fa-' . ($invoice->printed ? 'check' : 'times') . '"></i>';
		}, '%s', 'this.dpdCode', ['class' => 'fit']);
		
		$grid->addColumnLinkDetail('detail');
		$grid->addColumnActionDelete();

		$grid->addButtonBulkEdit('form', ['printed']);
		$grid->addButtonDeleteSelected(null, false, null, 'this.uuid');

		$grid->addBulkAction('printMultiple', 'printMultiple', '<i class="fas fa-print"></i>');

		$grid->getForm()->addSubmit('demandMultiple', Html::fromHtml('<i class="fa fa-meteor"></i>&nbsp;Urgovat'))
			->setHtmlAttribute('class', $btnSecondary)
			->onClick[] = [$this, 'demandMultiple'];

		$grid->getForm()->addSubmit('notifyMultiple', Html::fromHtml('<i class="fa fa-bell"></i>&nbsp;Notifikovat'))
			->setHtmlAttribute('class', $btnSecondary)
			->onClick[] = [$this, 'notifyMultiple'];
		
		$grid->addFilterTextInput('search', ['this.code'], null, 'Kód');
		
		$grid->addFilterDate(function (ICollection $source, $value): void {
			$source->where('DATE(this.exposed) >= DATE(:date_from)', ['date_from' => $value]);
		}, '', 'date_from')->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Datum od');
		
		$grid->addFilterDate(function (ICollection $source, $value): void {
			$source->where('DATE(this.exposed) <= DATE(:date_to)', ['date_to' => $value]);
		}, '', 'date_to')->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Datum do');
		
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function createComponentForm(): Form
	{
		/** @var \Eshop\DB\Invoice|null $invoice */
		$invoice = $this->getParameter('invoice');

		$form = $this->formFactory->create();

		$form->monitor(Presenter::class, function () use ($form, $invoice): void {
			$form->addText('code', 'Kód')->setNullable()->setHtmlAttribute('data-info', 'Pokud nevyplníte, bude použit kód objednávky.')->setDisabled((bool) $invoice);
			$form->addText('exposed', 'Datum vystavení')
				->setHtmlType('date')
				->setNullable()
				->setHtmlAttribute('data-info', 'Pokud nevyplníte, bude použito dnešní datum.');
			$form->addText('taxDate', 'Datum zdanitelného plnění')
				->setHtmlType('date')
				->setNullable()
				->setHtmlAttribute('data-info', 'Pokud nevyplníte, bude použito nastavení posunu. Aktuální posun: + ' . $this->shopper->getInvoicesAutoTaxDateInDays() . ' dní vůči datu vystavení');
			$form->addText('dueDate', 'Datum splatnosti')
				->setHtmlType('date')
				->setNullable()
				->setHtmlAttribute('data-info', 'Pokud nevyplníte, bude použito nastavení posunu. Aktuální posun: + ' . $this->shopper->getInvoicesAutoDueDateInDays() . ' dní vůči datu vystavení');

			$input = $form->addSelectAjax('order', 'Objednávka', '- Vyberte objednávku -', Order::class)->setDisabled((bool) $invoice);

			if ($invoice && $invoice->orders->count() === 0) {
				$input->setPrompt('Objednávka smazána!');
				$input->setHtmlAttribute('data-info', 'Objednávka přiřazená k teté faktuře již neexistuje. Tato faktura je neplatná!');
			}

			$form->addSelect2('paymentType', 'Typ úhrady', $this->paymentTypeRepository->getArrayForSelect())->setPrompt('- Z objednávky -')->setDisabled((bool) $invoice);
			$form->addText('variableSymbol', 'Variabilní symbol pro platbu')->setNullable();
			$form->addText('constantSymbol', 'Konstantní symbol pro platbu')->setNullable();

			$form->addGroup('Stav faktury');
			$form->addText('paidDate', 'Zaplaceno')
				->setHtmlType('date')
				->setNullable()
				->setHtmlAttribute('data-info', 'Pokud nevyplníte, bude použit stav objednávky.');
			$form->addText('canceled', 'Storno')
				->setHtmlType('date')
				->setNullable()
				->setHtmlAttribute('data-info', 'Pokud nevyplníte, bude použit stav objednávky.');

			$form->addCheckbox('printed', 'Vytisknuto');

			$form->addSubmits();
		});

		$form->onValidate[] = function (AdminForm $form) use ($invoice): void {
			if (!$form->isValid()) {
				return;
			}

			$data = $form->getHttpData();

			if (isset($data['order']) || $invoice) {
				return;
			}

			/** @var \Nette\Forms\Controls\SelectBox $input */
			$input = $form['order'];
			$input->addError('Toto pole je povinné!');
		};
		
		$form->onSuccess[] = function (AdminForm $form) use ($invoice): void {
			$values = $form->getValuesWithAjax();

			if (isset($values['order'])) {
				$order = $this->orderRepository->one(Arrays::pick($values, 'order'));
			}

			$invoice = $invoice || !isset($order) ? $this->invoiceRepository->syncOne($values) :
				$this->invoiceRepository->createFromOrder($order, $values);

			
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

		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('form');

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$form];
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

		/** @var \Nette\Forms\Controls\SelectBox $orderInput */
		$orderInput = $form['order'];

		if ($order = $invoice->orders->first()) {
			$this->template->select2AjaxDefaults[$orderInput->getHtmlId()] = [$order->getPK() => $order->code];
		}

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

	public function renderPrintMultiple(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Tisknout hromadně';
		$this->template->headerTree = [
			['Faktury', 'default'],
			['Tisknout hromadně'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('printMultipleForm')];
	}

	public function createComponentPrintMultipleForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('grid'), function (array $values, Collection $collection): void {
			$hashes = $collection->toArrayOf('hash', [], true);

			$this->redirect(':Eshop:Export:invoiceMultiple', [$hashes]);
		}, $this->getBulkFormActionLink(), $this->invoiceRepository->many(), $this->getBulkFormIds());
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
