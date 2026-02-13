<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminGrid;
use Carbon\Carbon;
use Eshop\BackendPresenter;
use Eshop\DB\Watcher;
use Eshop\DB\WatcherRepository;
use Nette\DI\Attributes\Inject;
use StORM\Collection;

class WatcherPresenter extends BackendPresenter
{
	#[Inject]
	public WatcherRepository $watcherRepository;

	public function createComponentGrid(): AdminGrid
	{
		$source = $this->watcherRepository->many()
			->join(['customer' => 'eshop_customer'], 'this.fk_customer = customer.uuid')
			->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid');

		$grid = $this->gridFactory->create($source, 20, 'this.createdTs', 'DESC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Vytvořeno', 'createdTs|date', '%s', 'createdTs', ['class' => 'fit']);
		$grid->addColumn('Zákazník', function (Watcher $watcher): string {
			$customer = $watcher->customer;
			$link = $this->link(':Eshop:Admin:Customer:edit', ['customer' => $customer]);

			return "<a href=\"$link\"><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;$customer->fullname ($customer->email)</a>";
		});
		$grid->addColumn('Produkt', function (Watcher $watcher): string {
			$product = $watcher->product;
			$code = $product->getFullCode();
			$link = $this->link(':Eshop:Admin:Product:edit', ['product' => $product]);

			return "<a href=\"$link\"><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;$product->name ($code)</a>";
		});
		$grid->addColumn('Typ', function (Watcher $watcher): string {
			if (\property_exists($watcher, 'type')) {
				return $watcher->type === 'price'
					? 'Cena: ' . ($watcher->priceFrom !== null ? \number_format($watcher->priceFrom, 2, ',', ' ') : '—')
					: 'Dostupnost';
			}

			return $watcher->priceFrom !== null ? 'Cena: ' . \number_format($watcher->priceFrom, 2, ',', ' ') : 'Dostupnost';
		});
		$grid->addColumn('Notifikováno', function (Watcher $watcher): string {
			return $watcher->notifiedTs !== null
				? Carbon::parse($watcher->notifiedTs)->format('d. m. Y')
				: '—';
		}, '%s', null, ['class' => 'fit']);

		$grid->addColumnActionDelete();
		$grid->addButtonDeleteSelected();

		$grid->addFilterSelect2Ajax(function (Collection $source, $value): void {
			if ($value !== '') {
				$source->where('this.fk_customer', $value);
			}
		}, '', 'customer', $this->link('getCustomersForSelect2!'), null, [], 'Zákazník')
			->setPrompt('Zákazník')
			->setHtmlAttribute('class', 'form-control form-control-sm');

		$grid->addFilterSelect2Ajax(function (Collection $source, $value): void {
			if ($value !== '') {
				$source->where('this.fk_product', $value);
			}
		}, '', 'product', $this->link('getProductsForSelect2!'), null, [], 'Produkt')
			->setPrompt('Produkt')
			->setHtmlAttribute('class', 'form-control form-control-sm');

		$columns = $this->watcherRepository->getStructure()->getColumns();

		if (isset($columns['type'])) {
			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				$source->where('this.type', $value);
			}, '', 'type', null, ['availability' => 'Dostupnost', 'price' => 'Cena'])->setPrompt('- Typ -');
		}

		$grid->addFilterButtons();

		return $grid;
	}

	public function handleGetCustomersForSelect2(?string $q = null, ?int $page = null): void
	{
		if (!$q) {
			$this->payload->results = [];
			$this->sendPayload();
		}

		$customers = $this->customerRepository->getAjaxArrayForSelect(true, $q, $page);

		$results = [];

		foreach ($customers as $pk => $name) {
			$results[] = [
				'id' => $pk,
				'text' => $name,
			];
		}

		$this->payload->results = $results;
		$this->payload->pagination = ['more' => \count($customers) === 5];

		$this->sendPayload();
	}

	public function renderDefault(): void
	{
		$template = $this->getTemplate();

		$template->headerLabel = 'Hlídací psi';
		$template->headerTree = [['Hlídací psi']];

		/** @var \Admin\Controls\AdminGrid $grid */
		$grid = $this->getComponent('grid');
		$filterForm = $grid->getFilterForm();
		$customerInput = $filterForm['customer'];
		$productInput = $filterForm['product'];

		if (isset($grid->getFilters()['product'])) {
			$defaultProduct = $this->productRepository->one($grid->getFilters()['product']);

			if ($defaultProduct !== null) {
				/** @phpstan-ignore-next-line */
				$template->select2AjaxDefaults[$productInput->getHtmlId()] = [$defaultProduct->getPK() => $defaultProduct->getName()];
			}
		}

		if (isset($grid->getFilters()['customer'])) {
			/** @var ?\Eshop\DB\Customer $defaultCustomer */
			$defaultCustomer = $this->customerRepository->one($grid->getFilters()['customer']);

			if ($defaultCustomer !== null) {
				/** @phpstan-ignore-next-line */
				$template->select2AjaxDefaults[$customerInput->getHtmlId()] = [$defaultCustomer->getPK() => $defaultCustomer->getName()];
			}
		}

		$template->displayControls = [$this->getComponent('grid')];
	}
}
