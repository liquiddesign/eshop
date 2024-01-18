<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\CustomerRole;
use Eshop\DB\OrderRepository;
use Eshop\Integration\Integrations;
use Forms\Form;
use Nette\Application\Responses\FileResponse;

class DPDOrdersErrorsPresenter extends BackendPresenter
{
	/** @inject */
	public OrderRepository $orderRepository;

	/** @inject */
	public Integrations $integrations;

	public function createComponentGrid(): AdminGrid
	{
		$collection = $this->orderRepository->many()
			->where('this.dpdCode IS NOT NULL OR this.dpdError = 1');

		$grid = $this->gridFactory->create(
			$collection,
			20,
			'this.createdTs',
			'DESC',
			true,
		);
		$grid->addColumnSelector();

		$grid->addColumnText('Datum vytvoření', 'createdTs', '%s', 'createdTs');
		$grid->addColumnText('Kód', 'code', '%s', 'code');
		$grid->addColumnText('DPD čísla', 'dpdCode', '%s', 'dpdCode');
		$grid->addColumnText('DPD chyba', 'dpdError', '%s', 'dpdError');

		$grid->addButtonSaveAll();

		$grid->addFilterTextInput('search', ['code'], null, 'Kód');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addDate('from', 'Od')->setRequired()->setHtmlAttribute('data-info', 'Zadejte nejlépe +- 1 pracovní den od požadované objednávky.<br>
Pokud je blízko víkend, zadejte větší rozsah.');
		$toInput = $form->addDate('to', 'Do')->setRequired();

		$form->addSubmits(false, false);

		if ($foundHoles = $this->request->getParameter('foundHoles')) {
			$toInput->setHtmlAttribute('data-info', '<b>Nalezené díry:</b><br>' . \implode('<br>', $foundHoles));
		}

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			\bdump($values);

			$orders = $this->orderRepository->many()
				->where('this.dpdCode IS NOT NULL')
				->where('this.createdTs >= :from AND this.createdTs <= :to', ['from' => $values['from'], 'to' => $values['to']])
				->setOrderBy(['this.dpdCode' => 'DESC']);

			$allDPDCodes = $this->orderRepository->many()->where('this.dpdCode IS NOT NULL')->toArrayOf('dpdCode', toArrayValues: true);

			$foundHoles = [];

			$min = \PHP_INT_MAX;
			$max = \PHP_INT_MIN;

			foreach ($orders as $order) {
				if ($order->dpdCode < $min) {
					$min = $order->dpdCode;
				}

				if ($order->dpdCode <= $max) {
					continue;
				}

				$max = $order->dpdCode;
			}

			for ($i = $min; $i <= $max; $i++) {
				if (!\in_array($i, $allDPDCodes)) {
					$foundHoles[] = $i;
				}
			}

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('this', ['foundHoles' => $foundHoles]);
		};

		return $form;
	}

	public function createComponentLabelForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addText('dpdCode', 'Kód DPD')->setRequired();

		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			/** @var \Eshop\Services\DPD|null $dpd */
			$dpd = $this->integrations->getService(Integrations::DPD);

			if (!$dpd) {
				return;
			}

			$label = $dpd->getLabelByCode((int) $values['dpdCode']);

			if (!$label) {
				$this->flashMessage('Nenalezeno', 'error');
				$this->redirect('this');
			}

			$this->sendResponse(new FileResponse($label, 'labels.pdf', 'application/pdf'));
		};

		return $form;
	}

	public function createComponentOrderForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addText('orderCode', 'Kód objednávky')->setRequired();
		$form->addText('dpdCode', 'Kód DPD')->setRequired();

		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$order = $this->orderRepository->many()->where('this.code', $values['orderCode'])->first();

			if (!$order) {
				$this->flashMessage('Objednávka nenalazena!', 'error');
				$this->redirect('this');
			}

			$order->update([
				'dpdCode' => $values['dpdCode'],
				'dpdError' => false,
			]);

			$this->flashMessage('Provedeno', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Chybné DPD objednávky';
		$this->template->headerTree = [
			['Chybné DPD objednávky'],
		];
		$this->template->displayButtons = [
			$this->createButton('new', 'Vyhledat chybějící čísla v řadě'),
			$this->createButton('label', 'Test DPD štítku'),
			$this->createButton('order', 'Opravit objednávku'),
		];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderLabel(): void
	{
		$this->template->headerLabel = 'DPD';
		$this->template->headerTree = [
			['Chybné DPD objednávky', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('labelForm')];
	}

	public function renderOrder(): void
	{
		$this->template->headerLabel = 'DPD';
		$this->template->headerTree = [
			['Chybné DPD objednávky', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('orderForm')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'DPD';
		$this->template->headerTree = [
			['Chybné DPD objednávky', 'default'],
			['Nový'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Chybné DPD objednávky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(CustomerRole $role): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');
		$values = $role->toArray();
		$form->setDefaults($values);
	}
}
