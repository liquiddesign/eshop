<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\BackendPresenter;
use Eshop\DB\PricelistRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\SupplierProduct;
use Eshop\DB\SupplierProductRepository;
use Eshop\DB\SupplierRepository;
use Eshop\Integration\Integrations;
use Eshop\Providers\IProducerSyncSupplier;
use Forms\Form;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\Expression;
use StORM\ICollection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class SupplierProductPresenter extends BackendPresenter
{
	/** @persistent */
	public ?string $tab = null;

	/**
	 * @var array<string>
	 */
	public array $tabs = [];

	#[\Nette\DI\Attributes\Inject]
	public SupplierProductRepository $supplierProductRepository;

	#[\Nette\DI\Attributes\Inject]
	public PricelistRepository $pricelistRepository;

	#[\Nette\DI\Attributes\Inject]
	public ProducerRepository $producerRepository;

	#[\Nette\DI\Attributes\Inject]
	public SupplierRepository $supplierRepository;

	#[\Nette\DI\Attributes\Inject]
	public SettingRepository $settingRepository;

	#[\Nette\DI\Attributes\Inject]
	public Integrations $integrations;

	public function beforeRender(): void
	{
		parent::beforeRender();

		$this->tabs = $this->supplierRepository->getArrayForSelect();
	}

	public function createComponentGrid(): AdminGrid
	{
		if (!$this->tab) {
			return $this->gridFactory->create($this->supplierProductRepository->many()->where('1=0'), 20, 'this.createdTs', 'ASC', true);
		}

		$supplier = $this->supplierRepository->one($this->tab, true);

		$grid = $this->gridFactory->create($this->supplierProductRepository->many()->where('this.fk_supplier', $supplier->getPK()), 20, 'this.createdTs', 'ASC', true);
		$grid->addColumnSelector();
		$grid->addColumn('Kód a EAN', function (SupplierProduct $product) {
			return $product->code . ($product->ean ? "<br><small>EAN $product->ean</small>" : '') . ($product->mpn ? "<br><small>P/N $product->mpn</small>" : '');
		}, '%s', 'updatedTs', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnText('Výrobce', 'producer.name', '%s');
		$grid->addColumnText('Kategorie', ['category.getNameTree'], '%s');

		$grid->addColumn('Napárovano', function (SupplierProduct $supplierProduct, AdminGrid $datagrid) {
			$link = $supplierProduct->product && $this->admin->isAllowed(':Eshop:Admin:Product:edit') ?
				$datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$supplierProduct->product, 'backLink' => $this->storeRequest(),]) : '#';

			return $supplierProduct->product ? "<a href='$link'>" . $supplierProduct->product->getFullCode() . '</a>' : '-ne-';
		}, '%s', 'product');

		/** @var \Eshop\Services\Algolia|null $algolia */
		$algolia = $this->integrations->getService(Integrations::ALGOLIA);

		if ($algolia && $supplier->pairWithAlgolia) {
			$grid->addColumn('Návrh Algolia', function (SupplierProduct $supplierProduct, AdminGrid $datagrid) use ($algolia) {
				if (!$supplierProduct->name) {
					return '-';
				}

				try {
					$hits = $algolia->searchProduct($supplierProduct->name)['hits'];
					$hitsCount = \count($hits);

					if ($hitsCount > 0) {
						/** @var array<string> $firstHit */
						$firstHit = Arrays::first($hits);

						$hitProduct = $this->productRepository->one($firstHit['objectID']);

						$link = $hitProduct && $this->admin->isAllowed(':Eshop:Admin:Product:edit') ?
							$datagrid->getPresenter()->link(':Eshop:Admin:Product:edit', [$hitProduct, 'backLink' => $this->storeRequest(),]) : '#';

						$acceptLink = '<a class="ml-2" title="Napárovat" href="' .
							$this->link('acceptAlgoliaSuggestion!', ['supplierProduct' => $supplierProduct->getPK(), 'product' => $hitProduct->getPK()])
							. '"><i class="fas fa-check fa-sm"></i></a>';

						$moreLink = '<a class="ml-2" title="Zobrazit další možnosti" href="' . $this->link('detailAlgolia', [$supplierProduct]) .
							'"><i class="fas fa-cog fa-sm"></i>&nbsp;(' . $hitsCount . ')</a>';

						return "<a href='$link'>$hitProduct->name (" . $hitProduct->getFullCode() . ')</a>' . $acceptLink . $moreLink;
					}
				} catch (\Throwable $e) {
				}

				return '-';
			}, '%s', 'product');
		}

		$grid->addColumnInputCheckbox('<span title="Aktivní">Aktivní</span>', 'active', 'active', '', 'this.active');
		$grid->addColumn('', function ($object, $grid) {
			return $grid->getPresenter()->link('detail', $object);
		}, '<a href="%s" class="btn btn-sm btn-outline-primary">Napárovat ručně</a>');

		$btnSecondary = 'btn btn-sm btn-outline-danger';
		$actionIco = "<a href='%s' class='$btnSecondary' onclick='return confirm(\"Opravdu?\")' title='Zrušit párování'><i class='fas fa-sm fa-unlink'></i></a>";
		$grid->addColumnAction('', $actionIco, function (SupplierProduct $supplierProduct): void {
			$supplierProduct->update(['product' => null]);
		}, [], null, ['class' => 'minimal']);

		if ($this->settingRepository->getValueByName('supplierProductDummyDefaultCategory')) {
			$grid->addColumn('', function (SupplierProduct $supplierProduct, AdminGrid $adminGrid): string {
				if ($supplierProduct->product) {
					return '<button class="btn btn-sm btn-outline-primary disabled" disabled><i class="fas fa-sm fa-plus-square"></i></button>';
				}

				$link = $adminGrid->getPresenter()->link('createDummyProduct!', [$supplierProduct->getPK()]);

				return "<a href='$link' class='btn btn-sm btn-outline-primary'><i class='fas fa-sm fa-plus-square'></i></button>";
			}, '%s', null, ['class' => 'fit']);
		}

		$grid->addColumnLinkDetail('SupplierProductDetail');

		$grid->addButtonSaveAll();

		$grid->addFilterTextInput('search', ['this.ean', 'this.code', 'this.mpn'], null, 'EAN, kód, P/N');
		$grid->addFilterTextInput('q', ['this.name'], null, 'Název produktu');

		$grid->addFilterText(function (ICollection $source, $value): void {
			$parsed = \explode('>', $value);
			$expression = new Expression();

			for ($i = 1; $i !== 5; $i++) {
				if (isset($parsed[$i - 1])) {
					$expression->add('AND', "category.categoryNameL$i=%s", [Strings::trim($parsed[$i - 1])]);
				}
			}

			$source->where('(' . $expression->getSql() . ') OR producer.name=:producer', $expression->getVars() + ['producer' => $value]);
		}, '', 'category')->setHtmlAttribute('placeholder', 'Kategorie, výrobce')->setHtmlAttribute('class', 'form-control form-control-sm');

		$grid->addFilterSelectInput(
			'notmapped',
			'IF(:mapped = "0", fk_product IS NULL, fk_product IS NOT NULL)',
			'Napárované',
			'- Napárované -',
			null,
			['0' => 'Bez párování', '1' => 'Napárované'],
			'mapped',
		);
//		$grid->addFilterCheckboxInput('notmapped', 'fk_product IS NOT NULL', 'Napárované');

		$grid->addButtonBulkEdit('form', ['active']);

		/** @var \Eshop\Services\Algolia|null $algolia */
		$algolia = $this->integrations->getService(Integrations::ALGOLIA);

		if ($algolia && $supplier->pairWithAlgolia) {
			$submit = $grid->getForm()->addSubmit('pairAlgoliaBulk', 'Párovat dle Algolia')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid): void {
				$grid->getPresenter()->redirect('pairAlgoliaBulk', [$grid->getSelectedIds()]);
			};
		}

		$grid->addBulkAction('createDummyProducts', 'createDummyProducts', 'Vytvořit produkty');

		$supplierProvider = $this->container->getByType($supplier->providerClass, false);

		if ($supplierProvider instanceof IProducerSyncSupplier) {
			$submit = $grid->getForm()->addSubmit('syncProducers', 'Synchronizovat výrobce')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');

			$submit->onClick[] = function ($button) use ($grid, $supplierProvider): void {
				$supplierProvider->syncSupplierProducers();
				$supplierProvider->syncRealProducers();

				$grid->getPresenter()->flashMessage('Provedeno', 'success');
				$grid->getPresenter()->redirect('this');
			};
		}

		$grid->addFilterButtons();

		return $grid;
	}

	public function handleCreateDummyProduct(string $supplierProduct): void
	{
		try {
			$this->productRepository->createDummyProducts(
				$this->supplierProductRepository->many()->where('this.uuid', $supplierProduct),
				$this->categoryRepository->one($this->settingRepository->getValueByName('supplierProductDummyDefaultCategory'), true),
				$this->supplierRepository->one($this->tab, true),
			);

			$this->flashMessage('Provedeno', 'success');
		} catch (\Throwable $e) {
			$this->flashMessage($e->getMessage(), 'error');
		}

		$this->redirect('this');
	}

	public function handleAcceptAlgoliaSuggestion(string $supplierProduct, string $product): void
	{
		try {
			$this->supplierProductRepository->one($supplierProduct)->update(['product' => $product]);

			$this->flashMessage('Uloženo', 'success');
		} catch (\Throwable $e) {
			$this->flashMessage('Nelze napárovat! Již existuje párování tohoto produktu a dodavatele!', 'error');
		}

		$this->redirect('this');
	}

	public function createComponentPairAlgoliaBulkForm(): AdminForm
	{
		/** @var \Admin\Controls\AdminGrid $grid */
		$grid = $this->getComponent('grid');

		$ids = $this->getParameter('ids') ?: [];
		$totalNo = $grid->getFilteredSource()->enum();
		$selectedNo = \count($ids);

		$form = $this->formFactory->create();
		$form->setAction($this->link('this', ['selected' => $this->getParameter('selected')]));
		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addCheckbox('overwrite', 'Přepsat existující párování')
			->setHtmlAttribute('data-info', 'Přepíše všechny párování produktu! Neovlivňuje jiné napárované produkty.');

		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $grid): void {
			$values = $form->getValues('array');

			$supplierProducts = $values['bulkType'] === 'selected' ? $this->supplierProductRepository->many()->where('uuid', $ids) : $grid->getFilteredSource();

			$error = 0;

			/** @var \Eshop\DB\SupplierProduct $supplierProduct */
			foreach ($supplierProducts->toArray() as $supplierProduct) {
				if (!$supplierProduct->name || ($supplierProduct->getValue('product') && !$values['overwrite'])) {
					continue;
				}

				/** @var \Eshop\Services\Algolia $algolia */
				$algolia = $this->integrations->getService(Integrations::ALGOLIA);

				$hits = $algolia->searchProduct($supplierProduct->name)['hits'];

				if (\count($hits) === 0) {
					continue;
				}

				/** @var array<string> $firstHit */
				$firstHit = Arrays::first($hits);

				$product = $this->productRepository->one($firstHit['objectID']);

				if (!$product) {
					continue;
				}

				$array = [
					'product' => $product->getPK(),
					'productCode' => $product->code,
				];

				if ($product->ean) {
					$array['ean'] = $product->ean;
				}

				try {
					$supplierProduct->update($array);
				} catch (\Throwable $e) {
					$error++;
				}
			}

			$this->flashMessage('Uloženo', 'success');

			if ($error > 0) {
				$this->flashMessage($error . ' produktů nebylo napárováno! Cílový produkt je již praděpodobně napárovaný k jinému produktu v rámci zdroje!', 'error');
			}

			$this->redirect('default');
		};

		return $form;
	}

	public function createComponentSupplierProductForm(): Form
	{
		$form = $this->formFactory->create(true);

		/** @var \Eshop\DB\SupplierProduct|null $supplierProduct */
		$supplierProduct = $this->getParameter('supplierProduct');

		$form->addTextArea('ean', 'EAN')->setNullable();

		$form->addSubmits(!$supplierProduct);

		$form->onSuccess[] = function (AdminForm $form) use ($supplierProduct): void {
			$values = $form->getValues('array');

			try {
				$supplierProduct->update($values);
			} catch (\Throwable $e) {
				if ((int) $e->getCode() === 23000 && Strings::contains($e->getMessage(), 'supplier_product_ean') !== false) {
					$this->flashMessage('Duplicitní EAN!', 'error');

					return;
				}
			}

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('supplierProductDetail', 'default', [$supplierProduct]);
		};

		return $form;
	}

	public function renderPairAlgoliaBulk(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Externí produkty', 'default'],
			['Párovat Algolia'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('pairAlgoliaBulkForm')];
	}

	public function actionSupplierProductDetail(SupplierProduct $supplierProduct): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('supplierProductForm');

		$form->setDefaults($supplierProduct->toArray());
	}

	public function renderSupplierProductDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Externí produkty', 'default'],
			['Detail dodavatelského produkty'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('supplierProductForm')];
	}

	public function renderCreateDummyProducts(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Vytvořit produkty';
		$this->template->headerTree = [
			['Externí produkty', 'default'],
			['Vytvořit produkty'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('createDummyProductsForm')];
	}

	public function createComponentCreateDummyProductsForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('grid'), function (array $values, Collection $collection): void {
			try {
				$this->productRepository->createDummyProducts($collection, $this->categoryRepository->one($values['category'], true), $this->supplierRepository->one($this->tab, true));

				$this->flashMessage('Provedeno', 'success');
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);

				$this->flashMessage('Chyba!<br>' . $e->getMessage(), 'error');
			}

			$this->redirect('this');
		}, $this->getBulkFormActionLink(), $this->supplierProductRepository->many(), $this->getBulkFormIds(), null, function (AdminForm $form): void {
			$form->addSelect2('category', 'Cílová kategorie', $this->categoryRepository->getTreeArrayForSelect())
				->setDefaultValue($this->settingRepository->getValueByName('supplierProductDummyDefaultCategory'))
				->setHtmlAttribute('data-info', 'Nově vytvořené produkty budou přidány do této kategorie.');
		});
	}

	public function createComponentForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addCheckbox('active', 'Aktivní');

		return $form;
	}

	public function createComponentPairForm(): AdminForm
	{
		$form = $this->formFactory->create();
		$form->addSelect2('productFullCode', 'Párovat k produktu', [], [
			'ajax' => [
				'url' => $this->link('getProductsForSelect2!'),
			],
			'placeholder' => 'Zvolte produkt',
		]);

		$form->addSubmits(false, false);

		$form->onValidate[] = function (AdminForm $form): void {
			$data = $this->getHttpRequest()->getPost();

			if (isset($data['productFullCode'])) {
				return;
			}

			/** @var \Nette\Forms\Controls\SelectBox $input */
			$input = $form['productFullCode'];
			$input->addError('Toto pole je povinné!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			$supplierProduct = $this->getParameter('supplierProduct');
			$product = $this->productRepository->one($form->getHttpData(Form::DATA_TEXT, 'productFullCode'));

			$update = [
				'productCode' => $product->code,
				'product' => $product,
			];

			if ($product->ean) {
				$update['ean'] = $product->ean;
			}

			try {
				$supplierProduct->update($update);
			} catch (\PDOException $e) {
				$this->flashMessage('Nelze napárovat! Pravděpodobně existuje párování cílového produktu na jiný produkt tohoto dodavatele!', 'error');
				$this->redirect('this');
			}

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$supplierProduct]);
		};

		return $form;
	}

	public function createComponentPairAlgoliaForm(): AdminForm
	{
		$form = $this->formFactory->create(false, false, false, false, false);

		/** @var \Eshop\DB\SupplierProduct|null $supplierProduct */
		$supplierProduct = $this->getParameter('supplierProduct');

		/** @var \Eshop\Services\Algolia|null $algolia */
		$algolia = $this->integrations->getService(Integrations::ALGOLIA);

		$algoliaResults = $algolia->searchProduct($supplierProduct->name, 'products');
		$results = [];

		foreach ($algoliaResults['hits'] as $result) {
			$results[$result['objectID']] = $result['name_cs'];
		}

		$form->addGroup($supplierProduct->name ? 'Hledaný produkt: ' . $supplierProduct->name : 'HLAVNÍ ÚDAJE');
		$form->addSelect2('product', 'Párovat k produktu', $results)->setRequired();

		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$supplierProduct = $this->getParameter('supplierProduct');

			$product = $this->productRepository->one($values['product']);

			$update = [
				'productCode' => $product->code,
				'product' => $product,
			];

			if ($product->ean) {
				$update['ean'] = $product->ean;
			}

			try {
				$supplierProduct->update($update);
			} catch (\PDOException $e) {
				$this->flashMessage('Nelze napárovat! Pravděpodobně existuje párování cílového produktu na jiný produkt tohoto dodavatele!', 'error');
				$this->redirect('this');
			}

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detailAlgolia', 'default', [$supplierProduct]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Externí produkty';

		$this->template->headerTree = [
			['Externí produkty'],
		];

		$this->template->tabs = $this->tabs;

		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Externí produkty', 'default'],
			['Nová položka'],
		];

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function renderDetail(SupplierProduct $supplierProduct): void
	{
		unset($supplierProduct);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Externí produkty', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('pairForm')];
	}

	public function renderDetailAlgolia(SupplierProduct $supplierProduct): void
	{
		unset($supplierProduct);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Externí produkty', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('pairAlgoliaForm')];
	}

	protected function startup(): void
	{
		parent::startup();

		if ($this->tab) {
			return;
		}

		$this->tab = \key($this->supplierRepository->getArrayForSelect());
	}
}
