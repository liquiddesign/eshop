<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use App\Admin\Controls\AdminGridFactory;
use Eshop\DB\Customer;
use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Forms\Form;
use Forms\Forms;
use Grid\Datagrid;
use League\Csv\Writer;
use Messages\DB\TemplateRepository;
use Nette\Application\Responses\FileResponse;
use Nette\Forms\Controls\Button;
use Nette\Localization\Translator;
use Nette\Mail\Mailer;
use Nette\Utils\DateTime;
use StORM\Collection;
use StORM\ICollection;

class OrderGridFactory
{
	private OrderRepository $orderRepository;

	private AdminGridFactory $gridFactory;

	private TemplateRepository $templateRepository;

	private Mailer $mailer;

	private Translator $translator;

	public function __construct(AdminGridFactory $adminGridFactory, OrderRepository $orderRepository, TemplateRepository $templateRepository, Mailer $mailer, Translator $translator)
	{
		$this->orderRepository = $orderRepository;
		$this->gridFactory = $adminGridFactory;
		$this->templateRepository = $templateRepository;
		$this->mailer = $mailer;
		$this->translator = $translator;
	}

	private function getCollectionByState(string $state): Collection
	{
		if ($state === 'received') {
			return $this->orderRepository->many()->where('this.completedTs IS NULL AND this.canceledTs IS NULL');
		}

		if ($state === 'finished') {
			return $this->orderRepository->many()->where('this.completedTs IS NOT NULL AND this.canceledTs IS NULL');
		}

		if ($state === 'canceled') {
			return $this->orderRepository->many()->where('this.completedTs IS NULL AND this.canceledTs IS NOT NULL');
		}

		throw new \DomainException("Invalid state: $state");
	}

	public function create(string $state): Datagrid
	{
		$btnSecondary = 'btn btn-sm btn-outline-primary';

		$grid = $this->gridFactory->create($this->getCollectionByState($state), 20, 'this.createdTs', 'DESC', true);

		$grid->addColumnSelector();
		$grid->addColumnText('Číslo a datum', ['code', "createdTs|date:'d.m.Y G:i"], '%s<br><small>%s</small>', 'this.code', ['class' => 'fit']);
		$grid->addColumn('Zákazník a adresa', [$this, 'renderCustomerColumn']);
		$contacts = '<a href="mailto:%1$s"><i class="far fa-envelope"></i> %1$s</a><br><small><a href="tel:%2$s"><i class="fa fa-phone-alt"></i> %2$s</a></small>';
		$grid->addColumnText('Kontakt', ['purchase.email', 'purchase.phone'], $contacts)->onRenderCell[] = [$grid, 'decoratorEmpty'];
		$grid->addColumnText('Voucher', ['purchase.coupon.label', 'purchase.coupon.code'], '%s<br><small>%s</small>');

		$grid->addColumn('Doprava', [$this, 'renderDeliveryColumn']);
		$grid->addColumn('Platba', [$this, 'renderPaymentColumn']);

		$properties = ["getTotalPrice|price:currency.code", 'getTotalPriceVat|price:currency.code'];
		$grid->addColumnText('Cena', $properties, '%s<br><small>%s s DPH</small>',null, ['class' => 'text-right fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];

		if ($state !== 'finished') {
			$actionIco = "<a href='%s' class='$btnSecondary' onclick='return confirm(\"Opravdu?\")' title='Zpracovat'><i class='fa fa-sm fa-check'></i></a>";
			$grid->addColumnAction('', $actionIco, [$this, 'completeOrder'], [], null, ['class' => 'minimal']);
		}

		if ($state !== 'canceled') {
			$actionIco = "<a href='%s' class='$btnSecondary' onclick='return confirm(\"Opravdu?\")' title='Stornovat'><i class='fa fa-sm fa-times'></i></a>";
			$grid->addColumnAction('', $actionIco, [$this, 'cancelOrder'], [], null, ['class' => 'minimal']);
		}

		$grid->addColumnLink('orderEmail', '<i class="far fa-envelope"></i>', null, ['class' => 'minimal']);

		$downloadIco = "<a href='%s' class='$btnSecondary' title='Stáhnout'><i class='fa fa-sm fa-download'></i></a>";
		$grid->addColumnAction('EDI', $downloadIco,  [$this, 'downloadEdi'], [], null, ['class' => 'minimal']);
		$grid->addColumnAction('CSV', $downloadIco,  [$this, 'downloadCsv'], [], null, ['class' => 'minimal']);

		$grid->addColumnLink('orderItems','Položky', null, ['class' => 'minimal']);

		$grid->addColumn('',  function (Order $order) use ($grid) {
			return $grid->getPresenter()->link(':User:Order:order', $order->getPK());
		}, "<a class='$btnSecondary' href='%s' target='_blank'><i class='fa fa-print'></i> Detail</a>", null, ['class' => 'minimal']);


		$grid->addColumnLink('detail','<i class="fa fa-edit"></i>', null, ['class' => 'minimal']);

		// filters
		$grid->addFilterTextInput('search_order', ['this.code'], null, 'Č. objednávky');
		$searchExpressions = ['customer.fullname', 'purchase.fullname', 'customer.ic', 'purchase.ic', 'customer.email', 'purchase.email', 'customer.phone', 'purchase.phone',];
		$grid->addFilterTextInput('search_q', $searchExpressions, null, 'Jméno zákazníka, IČO, email, telefon');
		$grid->addFilterButtons(['default']);

		$grid->addFilterDatetime(function (ICollection $source, $value) {
			$source->where('createdTs >= :created_from', ['created_from' => $value]);
		}, '', 'date_from', null)->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Datum od');

		$grid->addFilterDatetime(function (ICollection $source, $value) {
			$source->where('createdTs <= :created_to', ['created_from' => $value]);
		}, '', 'created_to', null)->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Datum do');


		$grid->addButtonBulkEdit('form', ['completedTs', 'canceledTs'], 'ordersGrid');

		if ($state !== 'finished') {
			$submit = $grid->getForm()->addSubmit('completeMultiple');
			$submit->setHtmlAttribute('class', $btnSecondary)->getControlPrototype()->setName('button')->setHtml('<i class="fa fa-check"></i> Zpracovat');
			$submit->onClick[] = [$this, 'completeOrderMultiple'];
		}

		if ($state !== 'canceled') {
			$submit = $grid->getForm()->addSubmit('cancelMultiple');
			$submit->setHtmlAttribute('class', $btnSecondary)->getControlPrototype()->setName('button')->setHtml('<i class="fa fa-times"></i> Stornovat');
			$submit->onClick[] = [$this, 'cancelOrderMultiple'];
		}

		return $grid;
	}

	public function renderPaymentColumn(Order $order, Datagrid $grid)
	{
		$link = $grid->getPresenter()->link('payment', [$order]);

		if (!$payment = $order->getPayment()) {
			return '<a href="' . $link . '" class="btn btn-sm btn-outline-primary"><i class="fa fa-sm fa-plus m-1"></i>Zvolte platbu</a>';
		}

		$linkPay = $grid->getPresenter()->link('changePayment!', ['payment' => (string) $payment, 'paid' => true]);
		$linkPayPlusEmail = $grid->getPresenter()->link('changePayment!', ['payment' => (string) $payment, 'paid' => true, 'email' => true]);
		$linkCancel = $grid->getPresenter()->link('changePayment!', ['payment' => (string) $payment, 'paid' => false]);

		if ($payment->paidTs) {
			$date = $grid->template->getLatte()->invokeFilter('date', [$payment->paidTs]);
			$paymentInfo = "<br><small title='Zaplaceno'><i class='fas fa-check fa-xs' style='color: green;'></i> $date <a href='$linkCancel'><i class='far fa-times-circle'></i></a></small>";
		} else {
			$paymentInfo = "<br><small title='Nezaplaceno'><i class='fas fa-stop fa-xs' style='color: gray'></i> <a href='$linkPay'>Zaplatit</a> | <a href='$linkPayPlusEmail'>Zaplatit + email</a></small>";
		}

		return "<a href='$link'>".$payment->getTypeName()."</a>" . $paymentInfo;
	}

	public function renderDeliveryColumn(Order $order, Datagrid $grid)
	{
		$link = $grid->getPresenter()->link('delivery', [$order]);

		if (!$delivery = $order->getLastDelivery()) {
			return '<a href="' . $link . '" class="btn btn-sm btn-outline-primary"><i class="fa fa-sm fa-plus m-1"></i>Zvolte dopravu</a>';
		}

		$linkShip = $grid->getPresenter()->link('changeDelivery!', ['delivery' => (string) $delivery, 'shipped' => true, 'email' => false]);
		$linkShipPlusEmail = $grid->getPresenter()->link('changeDelivery!', ['delivery' => (string) $delivery, 'shipped' => true, 'email' => true]);
		$linkCancel = $grid->getPresenter()->link('changeDelivery!', ['delivery' => (string) $delivery, 'shipped' => false]);

		if ($delivery->shippedTs) {
			$from  = $order->deliveries->clear(true)->where('shippedTs IS NOT NULL')->enum();
			$to = $order->deliveries->clear(true)->enum();
			$date = $grid->template->getLatte()->invokeFilter('date', [$delivery->shippedTs]);
			$deliveryInfo = "<br><small title='Expedováno'><i class='fas fa-play fa-xs' style='color: gray;'></i> $from / $to | $date <a href='$linkCancel'><i class='far fa-times-circle'></i></a></small>";
		} else {
			$deliveryInfo = "<br><small title='Neexpedováno'><i class='fas fa-stop fa-xs' style='color: gray'></i> <a href='$linkShip'>Expedovat</a>  | <a href='$linkShipPlusEmail'>Expedovat + email</a></small>";
		}
		
		$date = $delivery->shippingDate ? '<i style=\'color: gray;\' class=\'fa fa-shipping-fast\'></i> ' . $grid->template->getLatte()->invokeFilter('date', [$delivery->shippingDate]) : '';
		
		return "<a href='$link'>".$delivery->getTypeName()."</a> <small> $date</small>" . $deliveryInfo;
	}

	public function renderCustomerColumn(Order $order, Datagrid $grid)
	{
		if ($order->customer) {
			$fullName = $order->customer && $order->customer->fullname ? $order->customer->fullname : ($order->purchase->fullname ?: '');
			$link = $grid->getPresenter()->link(':Eshop:Admin:Customer:edit', [$order->customer]);

			return "<a href='$link' style='white-space: nowrap;'>$fullName</a><br><small>Erbenova 808, Třebíč 67401</small>";
		}

		return $order->purchase->fullname ? "<span style='white-space: nowrap;'>".$order->purchase->fullname."</span>" : '';
	}

	public function cancelOrderMultiple(Button $button)
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);

		foreach ($grid->getSelectedIds() as $id) {
			$grid->getSource()->where($grid->getSourceIdName(), $id)->update(['canceledTs' => new DateTime()]);

			$order = $this->orderRepository->one($id, true);

			$mail = $this->templateRepository->createMessage('order.canceled', ['orderCode' => $order->code], $order->purchase->email);
			$this->mailer->send($mail);
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function cancelOrder(Order $object, Datagrid $grid)
	{
		$object->update(['canceledTs' => (string)new DateTime()]);

		$mail = $this->templateRepository->createMessage('order.canceled', ['orderCode' => $object->code], $object->purchase->email);
		$this->mailer->send($mail);

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function completeOrderMultiple(Button $button)
	{
		/** @var \Grid\Datagrid $grid */
		$grid = $button->lookup(Datagrid::class);

		foreach ($grid->getSelectedIds() as $id) {
			$grid->getSource()->where($grid->getSourceIdName(), $id)->update(['completedTs' => new DateTime()]);

			/** @var \Eshop\DB\Order $order */
			$order = $this->orderRepository->one($id, true);

			$mail = $this->templateRepository->createMessage('order.changed', [
				'orderCode' => $order->code,
				'orderState' => $this->translator->translate('order.statusCompleted','vyřízena')
			], $order->purchase->email);

			$this->mailer->send($mail);
		}

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function completeOrder(Order $object, Datagrid $grid)
	{
		$object->update(['completedTs' => (string)new DateTime()]);

		$mail = $this->templateRepository->createMessage('order.changed', [
			'orderCode' => $object->code,
			'orderState' => $this->translator->translate('order.statusCompleted','vyřízena')
		], $object->purchase->email);

		$this->mailer->send($mail);

		$grid->getPresenter()->flashMessage('Provedeno', 'success');
		$grid->getPresenter()->redirect('this');
	}

	public function downloadEdi(Order $object, Datagrid $grid)
	{
		$presenter = $grid->getPresenter();

		$tempFilename = \tempnam($presenter->context->parameters['tempDir'], "xml");
		$fh = \fopen($tempFilename, 'w+');
		\fwrite($fh, $this->orderRepository->ediExport($object));
		\fclose($fh);
		$presenter->context->getService('application')->onShutdown[] = function () use ($tempFilename) {
			unlink($tempFilename);
		};
		$presenter->sendResponse(new FileResponse($tempFilename, 'order.txt', 'text/plain'));
	}

	public function downloadCsv(Order $object, Datagrid $grid)
	{
		$presenter = $grid->getPresenter();

		$tempFilename = \tempnam($presenter->context->parameters['tempDir'], "csv");
		$presenter->context->getService('application')->onShutdown[] = function () use ($tempFilename) {
			\unlink($tempFilename);
		};
		$this->orderRepository->csvExport($object, Writer::createFromPath($tempFilename, 'w+'));
		$response = new FileResponse($tempFilename, "objednavka-$object->code.csv", 'text/csv');
		$presenter->sendResponse($response);
	}
}