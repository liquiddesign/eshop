<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminGrid;
use Eshop\DB\SupplierProductPhoto;
use Eshop\DB\SupplierProductPhotoRepository;
use Eshop\DB\SupplierRepository;
use Nette\DI\Attributes\Inject;

class SupplierProductPhotoPresenter extends \Eshop\BackendPresenter
{
	protected const SUPPLIER_IMAGES_DIR = 'supplier_images';

	#[Inject]
	public SupplierProductPhotoRepository $supplierProductPhotoRepository;

	#[Inject]
	public SupplierRepository $supplierRepository;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create(
			$this->supplierProductPhotoRepository->many()
				->join(['supplierProduct' => 'eshop_supplierproduct'], 'this.fk_supplierProduct = supplierProduct.uuid')
				->join(['supplier' => 'eshop_supplier'], 'supplierProduct.fk_supplier = supplier.uuid')
				->select(['supplierProductCode' => 'supplierProduct.code'])
				->select(['supplierProductName' => 'supplierProduct.name'])
				->select(['supplierName' => 'supplier.name'])
				->select(['supplierCode' => 'supplier.code']),
			20,
			'createdTs',
			'DESC',
			true
		);

		// Thumbnail obrázku (origin - thumb a detail neexistují)
		$grid->addColumnImage('fileName', self::SUPPLIER_IMAGES_DIR, 'origin', 'Obrázek');

		// Kód dodavatelského produktu
		$grid->addColumn('Kód produktu', function (SupplierProductPhoto $photo): string {
			return $photo->getValue('supplierProductCode') ?? '-';
		}, '%s', 'supplierProduct.code');

		// Název dodavatelského produktu
		$grid->addColumn('Název produktu', function (SupplierProductPhoto $photo): string {
			return $photo->getValue('supplierProductName') ?? '-';
		}, '%s', 'supplierProduct.name');

		// Dodavatel
		$grid->addColumn('Dodavatel', function (SupplierProductPhoto $photo): string {
			return $photo->getValue('supplierName') ?? '-';
		}, '%s', 'supplier.name');

		// URL zdroje
		$grid->addColumnText('URL zdroje', 'sourceUrl', '%s', 'sourceUrl');

		// Priorita
		$grid->addColumnText('Priorita', 'priority', '%s', 'priority');

		// Datum vytvoření
		$grid->addColumnText('Vytvořeno', "createdTs|date:'d.m.Y G:i'", '%s', 'createdTs', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		// Filtry
		$grid->addFilterTextInput('search', ['supplierProduct.code', 'supplierProduct.name'], null, 'Kód produktu, název');

		$suppliers = $this->supplierRepository->many()
			->orderBy(['name' => 'ASC'])
			->toArrayOf('name');

		$grid->addFilterDataSelect(function (\StORM\Collection $source, $value): void {
			$source->where('supplier.uuid', $value);
		}, '', 'supplier', null, $suppliers)->setPrompt('- Dodavatel -');

		$grid->addFilterButtons();

		return $grid;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Dodavatelské obrázky';
		$this->template->headerTree = [
			['Dodavatelské obrázky', 'default'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
}
