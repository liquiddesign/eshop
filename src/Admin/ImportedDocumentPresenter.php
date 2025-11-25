<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminGrid;
use Eshop\DB\ImportedDocument;
use Eshop\DB\ImportedDocumentRepository;
use Eshop\DB\OrderRepository;

class ImportedDocumentPresenter extends BackendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public ImportedDocumentRepository $importedDocumentRepository;

	#[\Nette\DI\Attributes\Inject]
	public OrderRepository $orderRepository;

	public function createComponentImportedDocumentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->importedDocumentRepository->many(), 20, 'createdTs', 'DESC', true);

		$grid->addColumnSelector();

		$grid->addColumnText('ID', 'id', '%s', 'id', ['class' => 'minimal']);

		$grid->addColumnText('Název souboru', 'filename', '%s', 'filename');

		$grid->addColumn('Typ', function (ImportedDocument $document): string {
			return match ($document->type) {
				'invoice' => 'Faktura',
				'deposit' => 'Záloha',
				default => $document->type,
			};
		}, '%s', 'type', ['class' => 'minimal']);

		$grid->addColumnText('Vytvořeno', "createdTs|date:'d.m.Y G:i'", '%s', 'createdTs', ['class' => 'minimal'])
			->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumnText('Importováno', "importedTs|date:'d.m.Y G:i'", '%s', 'importedTs', ['class' => 'minimal'])
			->onRenderCell[] = [$grid, 'decoratorEmpty'];

		$grid->addColumnText('Exportováno', "exportedTs|date:'d.m.Y G:i'", '%s', 'exportedTs', ['class' => 'minimal'])
			->onRenderCell[] = [$grid, 'decoratorEmpty'];

		$grid->addColumn('Objednávky', function (ImportedDocument $document): string {
			$count = $document->getOrders()->count();

			if ($count === 0) {
				return '0';
			}

			$orderCodes = [];

			foreach ($document->getOrders()->setTake(5) as $order) {
				$orderCodes[] = $order->code;
			}

			$text = \implode(', ', $orderCodes);

			if ($count > 5) {
				$text .= ' ... (+' . ($count - 5) . ')';
			}

			return $text . ' (' . $count . ')';
		}, '%s', null);

		$grid->addFilterTextInput('search', ['id', 'filename'], null, 'ID, název souboru');

		$grid->addFilterSelectInput('type', 'type = :type', '', 'Typ', null, [
			'invoice' => 'Faktura',
			'deposit' => 'Záloha',
		]);

		$grid->addFilterExpression('orderCodes', function (\StORM\ICollection $collection, string $value): void {
			$orderCodes = \array_filter(\array_map('trim', \explode(';', $value)));

			if (\count($orderCodes) === 0) {
				return;
			}

			$orders = $this->orderRepository->many()->where('code', $orderCodes)->toArrayOf('uuid');

			if (\count($orders) === 0) {
				$collection->where('1=0');

				return;
			}

			$collection->join(
				['nxn_order' => 'eshop_importeddocument_nxn_eshop_order'],
				'nxn_order.fk_importedDocument = this.uuid'
			);
			$collection->where('nxn_order.fk_order', $orders);
		}, '');

		$grid->addFilterTextInput('orderCodes', [], 'Objednávky (kódy oddělené středníkem)');

		$grid->addFilterButtons();

		return $grid;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Dokumenty';
		$this->template->headerTree = [
			['Zdroje', 'default'],
			['Dokumenty'],
		];
		$this->template->displayControls = [$this->getComponent('importedDocumentGrid')];
	}
}
