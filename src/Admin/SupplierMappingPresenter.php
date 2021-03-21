<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\CategoryRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\SupplierCategory;
use Eshop\DB\SupplierCategoryRepository;
use Eshop\DB\SupplierDisplayAmount;
use Eshop\DB\SupplierDisplayAmountRepository;
use Eshop\DB\SupplierMappingRepository;
use Eshop\DB\SupplierProducer;
use Eshop\DB\SupplierProducerRepository;
use Eshop\DB\SupplierRepository;
use Forms\Form;
use Nette\Http\Session;
use Nette\Utils\Arrays;
use Nette\Utils\Random;
use StORM\Entity;
use StORM\ICollection;
use StORM\Repository;

class SupplierMappingPresenter extends BackendPresenter
{
	public const TABS = [
		'category' => 'Kategorie',
		'producer' => 'Výrobce',
		'amount' => 'Dostupnost',
	];

	/** @inject */
	public SupplierMappingRepository $supplierMappingRepository;

	/** @inject */
	public CategoryRepository $categoryRepository;

	/** @inject */
	public ProducerRepository $producerRepository;

	/** @inject */
	public DisplayAmountRepository $displayAmountRepository;

	/** @inject */
	public DisplayDeliveryRepository $displayDeliveryRepository;

	/** @inject */
	public SupplierCategoryRepository $supplierCategoryRepository;

	/** @inject */
	public SupplierProducerRepository $supplierProducerRepository;

	/** @inject */
	public SupplierDisplayAmountRepository $supplierDisplayAmountRepository;

	/** @inject */
	public SupplierRepository $supplierRepository;

	/** @inject */
	public Session $session;

	/** @persistent */
	public string $tab = 'category';

	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->getMappingRepository()->many(), 20, 'createdTs', 'ASC');
		$grid->addColumnSelector();

		$grid->addColumn('Dodavatel', function (Entity $supplierMapping) {
			$link = $supplierMapping->supplier && $this->admin->isAllowed(':Eshop:Admin:Supplier:detail') ? $this->link(':Eshop:Admin:Supplier:detail', [$supplierMapping->supplier, 'backLink' => $this->storeRequest(),]) : '#';

			return $supplierMapping->supplier ? "<a href='$link'>" . ($supplierMapping->supplier->name ?: 'Detail dodavatele') . '</a>' : 'Nenamapováno';
		}, '%s', null, ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumnText('Název / hodnota', 'name', '%s', 'name');

		if ($this->tab === 'category') {
			$grid->addColumn('Kategorie', function (SupplierCategory $mapping) {
				$link = $mapping->category && $this->admin->isAllowed(':Eshop:Admin:Category:detail') ? $this->link(':Eshop:Admin:Category:detail', [$mapping->category, 'backLink' => $this->storeRequest(),]) : '#';

				return $mapping->category ? "<a href='$link'>" . ($mapping->category->name ?: 'Detail kategorie') . '</a>' : '-';
			});

			$property = 'category';
		}

		if ($this->tab === 'producer') {
			$grid->addColumn('Výrobce', function (SupplierProducer $mapping) {
				$link = $mapping->producer && $this->admin->isAllowed(':Eshop:Admin:Producer:detail') ? $this->link(':Eshop:Admin:Producer:detail', [$mapping->producer, 'backLink' => $this->storeRequest(),]) : '#';

				return $mapping->producer ? "<a href='$link'>" . ($mapping->producer->name ?: 'Detail výrobce') . '</a>' : '-';
			});

			$property = 'producer';
		}

		if ($this->tab === 'amount') {
			$grid->addColumn('Dostupnost', function (SupplierDisplayAmount $mapping) {
				$link = $mapping->displayAmount && $this->admin->isAllowed(':Eshop:Admin:DisplayAmount:detail') ? $this->link(':Eshop:Admin:DisplayAmount:detail', [$mapping->displayAmount, 'backLink' => $this->storeRequest(),]) : '#';

				return $mapping->displayAmount ? "<a href='$link'>" . ($mapping->displayAmount->label ?: 'Detail dostupnosti') . '</a>' : '-';
			});

			$property = 'displayAmount';
		}

		$grid->addColumn('', function ($object, $datagrid) {
			return $datagrid->getPresenter()->link('detail', $object->getPK());
		}, '<a class="btn btn-primary btn-sm text-xs" href="%s" title="Upravit"><i class="far fa-edit"></i></a>', null, ['class' => 'minimal']);

		$grid->addButtonBulkEdit('form', [$property]);
//		$grid->addButtonBulkEdit('mappingForm', [], 'grid', 'bulkMapping', 'Vytvořit strukturu');

		$submit = $grid->getForm()->addSubmit('submit', 'Vytvořit strukturu')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');
		$submit->onClick[] = function ($button) use ($grid) {
			$this->session->getSection('bulkEdit')->totalIds = \array_keys($grid->getFilteredSource()->toArray());
			$this->redirect('mapping', \serialize($grid->getSelectedIds()));
		};

		if ($suppliers = $this->supplierRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value) {
				$source->where('fk_supplier', $value);
			}, null, 'supplier', null, $suppliers, ['placeholder' => '- Dodavatel -']);
		}

		$grid->addFilterTextInput('search', ['name'], null, 'Název');

		$grid->addFilterCheckboxInput('notmapped', "fk_$property IS NULL", 'Nenapárované');

		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addText('name', 'Název / hodnota')->setHtmlAttribute('readonly', 'readonly');

		if ($this->tab === 'category') {
			$form->addDataSelect('category', 'Kategorie', $this->categoryRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
		}

		if ($this->tab === 'producer') {
			$form->addDataSelect('producer', 'Výrobce', $this->producerRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
		}

		if ($this->tab === 'amount') {
			$form->addDataSelect('displayAmount', 'Dostupnost', $this->displayAmountRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
		}

		$form->addHidden('supplier');

		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$supplierMapping = $this->getMappingRepository()->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$supplierMapping]);
		};

		return $form;
	}

	public function createComponentMappingForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$totalIds = $this->session->getSection('bulkEdit')->totalIds;
		$ids = \unserialize($this->getParameter('selectedIds'));
		$totalNo = \count($totalIds);
		$selectedNo = \count($ids);

		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addCheckbox('overwrite', 'Přepsat');

		if ($this->tab == 'category') {
			$form->addDataSelect('category', 'Nadřazená kategorie', $this->categoryRepository->getArrayForSelect())->setPrompt('Žádná');
		}

		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $totalIds) {
			$values = $form->getValues('array');

			$overwrite = $values['overwrite'];
			$data = $values['bulkType'] == 'selected' ? $ids : $totalIds;

			if ($this->tab == 'producer') {
				foreach ($data as $uuid) {
					/** @var \Eshop\DB\SupplierProducer $supplierProducer */
					$supplierProducer = $this->supplierProducerRepository->one($uuid);

					if (!$supplierProducer->name) {
						continue;
					}

					if ($supplierProducer->producer) {
						if ($overwrite) {
							$supplierProducer->producer->update(['name' => ['cs' => $supplierProducer->name, 'en' => null]]);
						}
					} else {
						/** @var \Eshop\DB\Producer $producer */
						$producer = $this->producerRepository->createOne([
							'name' => ['cs' => $supplierProducer->name, 'en' => null]
						]);

						$supplierProducer->update(['producer' => $producer->getPK()]);
					}
				}
			} elseif ($this->tab == 'amount') {
				foreach ($data as $uuid) {
					/** @var \Eshop\DB\SupplierDisplayAmount $supplierAmount */
					$supplierAmount = $this->supplierDisplayAmountRepository->one($uuid);

					if (!$supplierAmount->name) {
						continue;
					}

					if ($supplierAmount->displayAmount) {
						if ($overwrite) {
							$supplierAmount->displayAmount->update(['label' => ['cs' => $supplierAmount->name, 'en' => null]]);
						}
					} else {
						/** @var \Eshop\DB\DisplayAmount $displayAmount */
						$displayAmount = $this->displayAmountRepository->createOne([
							'label' => ['cs' => $supplierAmount->name, 'en' => null]
						]);

						$supplierAmount->update(['displayAmount' => $displayAmount->getPK()]);
					}
				}
			} elseif ($this->tab == 'category') {
				/** @var \Eshop\DB\Category $insertToCategory */
				$insertToCategory = $values['category'] ? $this->categoryRepository->one($values['category']) : null;

				foreach ($data as $uuid) {
					/** @var \Eshop\DB\SupplierCategory $supplierCategory */
					$supplierCategory = $this->supplierCategoryRepository->one($uuid);

					if (!$supplierCategory->name) {
						continue;
					}

					$newTree = \array_map('trim', \explode('>', $supplierCategory->name));
					$currentCategory = $insertToCategory;
					$path = $insertToCategory ? $insertToCategory->path : null;
					$first = true;

					foreach ($newTree as $cKey) {
						$originalPath = $currentCategory->path;

						do {
							$tempPath = $path . Random::generate(4, '0-9a-z');
							$tempCategory = $this->categoryRepository->many()->where('path', $tempPath)->first();
						} while ($tempCategory);

						/** @var \Eshop\DB\Category $existingCategory */
						$existingCategory = $this->categoryRepository->many()->where('path LIKE :s', ['s' => "$originalPath%"])->where('name_cs', $cKey)->first();

						$path = $existingCategory ? $existingCategory->path : $tempPath;

						$newCategoryData = [
							'name' => ['cs' => $cKey, 'en' => null],
							'path' => $path,
							'ancestor' => $currentCategory ? $currentCategory->getPK() : null
						];

						if ($existingCategory) {
							$existingCategory->update($newCategoryData);
							$currentCategory = $existingCategory;
						} else {
							if ($supplierCategory->category && Arrays::last($newTree) == $cKey) {
								if ($supplierCategory->category->ancestor->getPK() == $newCategoryData['ancestor']) {
									if (!$overwrite) {
										unset($newCategoryData['name']);
									}

									$supplierCategory->category->update($newCategoryData);
									$currentCategory = $supplierCategory->category;
								} else {
									$currentCategory = $this->categoryRepository->createOne($newCategoryData);
									$supplierCategory->update(['category' => $currentCategory->getPK()]);
								}
							} else {
								/** @var \Eshop\DB\Category $currentCategory */
								$currentCategory = $this->categoryRepository->createOne($newCategoryData);
							}
						}

						if ($first) {
							$newFirstCategory = $currentCategory;
							$first = false;
						}
					}

					$supplierCategory->update(['category' => $currentCategory->getPK()]);
				}

				if (isset($newFirstCategory)) {
					$this->categoryRepository->updateCategoryChildrenPath($newFirstCategory);
				}
			}

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('default');
		};

		return $form;
	}

	public
	function actionMapping($selectedIds)
	{
	}

	public
	function renderMapping($selectedIds)
	{
		$this->template->headerLabel = 'Vytvořit strukturu';
		$this->template->headerTree = [
			['Mapování', 'default'],
			['Vytvořit strukturu'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('mappingForm')];
	}

	public
	function renderDefault()
	{
		$this->template->headerLabel = 'Mapování';
		$this->template->headerTree = [
			['Mapování'],
		];

		$this->template->tabs = self::TABS;
		$this->template->displayButtons = [];

		if ($this->tab == 'mapping') {
			$this->template->displayControls = [$this->getComponent('mappingGrid')];
			$this->template->displayButtons = [$this->createNewItemButton('newMapping')];
		} else {
			$this->template->displayControls = [$this->getComponent('grid')];
		}
	}

	public
	function renderNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Mapování', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public
	function renderDetail(string $uuid)
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Mapování', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public
	function actionDetail(string $uuid)
	{
		/** @var Form $form */
		$form = $this->getComponent('form');

		$form->setDefaults($this->getMappingRepository()->one($uuid)->toArray());
	}

	private
	function getMappingRepository(): Repository
	{
		if ($this->tab === 'category') {
			return $this->supplierCategoryRepository;
		}

		if ($this->tab === 'producer') {
			return $this->supplierProducerRepository;
		}

		if ($this->tab === 'amount') {
			return $this->supplierDisplayAmountRepository;
		}

		if ($this->tab === 'mapping') {
			return $this->supplierMappingRepository;
		}

		throw new \DomainException('Invalid state');
	}
}