<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\BackendPresenter;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\DisplayDeliveryRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\SupplierAttribute;
use Eshop\DB\SupplierAttributeCategoryAssignRepository;
use Eshop\DB\SupplierAttributeRepository;
use Eshop\DB\SupplierAttributeValue;
use Eshop\DB\SupplierAttributeValueRepository;
use Eshop\DB\SupplierCategory;
use Eshop\DB\SupplierCategoryRepository;
use Eshop\DB\SupplierDisplayAmount;
use Eshop\DB\SupplierDisplayAmountRepository;
use Eshop\DB\SupplierMappingRepository;
use Eshop\DB\SupplierProducer;
use Eshop\DB\SupplierProducerRepository;
use Eshop\DB\SupplierRepository;
use Nette\Http\Session;
use Nette\Utils\Arrays;
use Nette\Utils\Random;
use StORM\Entity;
use StORM\Expression;
use StORM\ICollection;
use StORM\Repository;

class SupplierMappingPresenter extends BackendPresenter
{
	protected const CONFIGURATION = [
		'attributes' => false,
	];

	/**
	 * @var string[]
	 */
	public array $TABS = [
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
	public SupplierAttributeRepository $supplierAttributeRepository;

	/** @inject */
	public SupplierAttributeValueRepository $supplierAttributeValueRepository;

	/** @inject */
	public SupplierAttributeCategoryAssignRepository $supplierAttributeCategoryAssignRepository;

	/** @inject */
	public AttributeRepository $attributeRepository;

	/** @inject */
	public AttributeValueRepository $attributeValueRepository;

	/** @inject */
	public SupplierRepository $supplierRepository;

	/** @inject */
	public CategoryTypeRepository $categoryTypeRepository;

	/** @inject */
	public Session $session;

	/** @persistent */
	public string $tab = 'category';

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->getMappingRepository()->many(), 20, 'createdTs', 'ASC');
		$grid->addColumnSelector();

		$grid->addColumn(
			'Zdroj',
			function (Entity $supplierMapping) {
				/** @var \Eshop\DB\SupplierMapping $supplierMapping */
				$link = $this->admin->isAllowed(':Eshop:Admin:Supplier:detail') ?
					$this->link(':Eshop:Admin:Supplier:detail', [$supplierMapping->supplier, 'backLink' => $this->storeRequest(),]) : '#';

				return "<a href='$link'>" . ($supplierMapping->supplier->name ?: 'Detail dodavatele') . '</a>';
			},
			'%s',
			null,
			['class' => 'fit'],
		)->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumnText('Importováno', "createdTs|date:'d.m.Y G:i'", '%s', 'createdTs', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];
		$grid->addColumnText('Změněno', "updateTs|date:'d.m.Y G:i'", '%s', 'updatedTs', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNumber'];

		$property = null;

		if ($this->tab === 'category') {
			$grid->addColumnText('Název', 'getNameTree', '%s', 'categoryNameL1');
			$dir = \explode('-', $this->getHttpRequest()->getQuery('grid-order') ?? '')[1] ?? 'ASC';
			$grid->setSecondaryOrder(['categoryNameL2' => $dir, 'categoryNameL3' => $dir, 'categoryNameL4' => $dir, 'categoryNameL5' => $dir, 'categoryNameL6' => $dir]);

			$grid->addColumn('Napárovano', function (SupplierCategory $mapping) {
				$link = $mapping->category && $this->admin->isAllowed(':Eshop:Admin:Category:detail') ?
					$this->link(':Eshop:Admin:Category:detail', [$mapping->category, 'backLink' => $this->storeRequest(),]) : '#';

				return $mapping->category ? "<a href='$link'>" . ($mapping->category->name ?: 'Detail kategorie') . '</a>' : '-';
			});

			$property = 'category';
			$grid->addFilterText(function (ICollection $source, $value): void {
				$parsed = \explode('>', $value);
				$expression = new Expression();
				$orExpression = '';

				for ($i = 1; $i !== 7; $i++) {
					if (isset($parsed[$i - 1])) {
						$expression->add('AND', "categoryNameL$i=%s", [\trim($parsed[$i - 1])]);
					}
				}

				for ($i = 1; $i !== 7; $i++) {
					$orExpression .= " OR categoryNameL$i LIKE :value";
				}

				$source->where($expression->getSql() . $orExpression, $expression->getVars() + ['value' => "$value%"]);
			}, '', 'category')->setHtmlAttribute('placeholder', 'Název')->setHtmlAttribute('class', 'form-control form-control-sm');
		}

		if ($this->tab === 'producer') {
			$grid->addColumnText('Název', 'name', '%s', 'name');
			$grid->addColumn('Napárovano', function (SupplierProducer $mapping) {
				$link = $mapping->producer && $this->admin->isAllowed(':Eshop:Admin:Producer:detail') ?
					$this->link(':Eshop:Admin:Producer:detail', [$mapping->producer, 'backLink' => $this->storeRequest(),]) : '#';

				return $mapping->producer ? "<a href='$link'>" . ($mapping->producer->name ?: 'Detail výrobce') . '</a>' : '-';
			});

			$property = 'producer';
			$grid->addFilterTextInput('search', ['name'], null, 'Název');
		}

		if ($this->tab === 'attribute') {
			$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'minimal']);
			$grid->addColumnText('Název', 'name', '%s', 'name');
			$grid->addColumn('Napárovano', function (SupplierAttribute $mapping) {
				$link = $mapping->attribute && $this->admin->isAllowed(':Eshop:Admin:Attribute:attributeDetail') ?
					$this->link(':Eshop:Admin:Attribute:attributeDetail', [$mapping->attribute, 'backLink' => $this->storeRequest(),]) : '#';

				return $mapping->attribute ? "<a href='$link'>" . ($mapping->attribute->name ?: 'Detail atributu') . '</a>' : '-';
			});

			$property = 'attribute';
			$grid->addFilterTextInput('search', ['name'], null, 'Název');
		}

		if ($this->tab === 'attributeValue') {
			$grid->addColumn('Atribut', function (SupplierAttributeValue $mapping) {
				$link = $mapping->supplierAttribute && $this->admin->isAllowed(':Eshop:Admin:SupplierMapping:detail') ?
					$this->link(':Eshop:Admin:SupplierMapping:detail', [$mapping->getValue('supplierAttribute'), 'backLink' => $this->storeRequest(), 'tab' => 'attribute']) : '#';

				return $mapping->supplierAttribute ? "<a href='$link'>" . ($mapping->supplierAttribute->name ?: 'Detail atributu') . '</a>' : '-';
			});
			$grid->addColumnText('Název', 'label', '%s', 'label');
			$grid->addColumn('Napárovano', function (SupplierAttributeValue $mapping) {
				$link = $mapping->attributeValue && $this->admin->isAllowed(':Eshop:Admin:Attribute:valueDetail') ?
					$this->link(':Eshop:Admin:Attribute:valueDetail', [$mapping->attributeValue, 'backLink' => $this->storeRequest(),]) : '#';
				$attributeLink = $mapping->attributeValue && $this->admin->isAllowed(':Eshop:Admin:Attribute:attributeDetail') ?
					$this->link(':Eshop:Admin:Attribute:attributeDetail', [$mapping->attributeValue->attribute, 'backLink' => $this->storeRequest(),]) : '#';

				return $mapping->attributeValue ? "<a href='$attributeLink'>" .
					($mapping->attributeValue->attribute->name ?: 'Detail atributu') .
					"</a> - <a href='$link'>" .
					($mapping->attributeValue->label ?: 'Detail hodnoty') . '</a>' : '-';
			});

			$property = 'attributeValue';
			$grid->addFilterTextInput('search', ['label'], null, 'Název');
		}

		if ($this->tab === 'amount') {
			$grid->addColumnText('Hodnota', 'name', '%s', 'name');
			$grid->addColumn('Napárovano', function (SupplierDisplayAmount $mapping) {
				$link = $mapping->displayAmount && $this->admin->isAllowed(':Eshop:Admin:DisplayAmount:detail') ?
					$this->link(':Eshop:Admin:DisplayAmount:detail', [$mapping->displayAmount, 'backLink' => $this->storeRequest(),]) : '#';

				return $mapping->displayAmount ? "<a href='$link'>" . ($mapping->displayAmount->label ?: 'Detail dostupnosti') . '</a>' : '-';
			});

			$property = 'displayAmount';
			$grid->addFilterTextInput('search', ['name'], null, 'Název');
		}

		$grid->addColumn('', function ($object, $datagrid) {
			return $datagrid->getPresenter()->link('detail', $object->getPK());
		}, '<a class="btn btn-primary btn-sm text-xs" href="%s" title="Upravit"><i class="far fa-edit"></i></a>', null, ['class' => 'minimal']);

		$grid->addButtonBulkEdit(
			'form',
			[$property],
			'grid',
			'bulkEdit',
			'Hromadná úprava',
			'bulkEdit',
			'default',
			null,
			null,
			$property === 'attribute' || $property === 'attributeValue' ? [$property => $property] : [],
		);
		//      $grid->addButtonBulkEdit('mappingForm', [], 'grid', 'bulkMapping', 'Vytvořit strukturu');

		$submit = $grid->getForm()->addSubmit('submit', 'Vytvořit strukturu')->setHtmlAttribute('class', 'btn btn-outline-primary btn-sm');
		$submit->onClick[] = function ($button) use ($grid): void {
			$this->session->getSection('bulkEdit')->set('totalIds', \array_keys($grid->getFilteredSource()->toArray()));
			$this->redirect('mapping', \serialize($grid->getSelectedIds()));
		};

		if ($suppliers = $this->supplierRepository->getArrayForSelect()) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
				$source->where('fk_supplier', $value);
			}, null, 'supplier', null, $suppliers, ['placeholder' => '- Dodavatel -']);
		}

		if ($this->tab === 'attributeValue' && $this->supplierAttributeRepository->many()->toArrayOf('name')) {
			$grid->addFilterTextInput('supplierAttributeCode', ['supplierAttribute.code'], null, 'Kód atributu', null, '%s');
		}

		$grid->addFilterDatetime(function (ICollection $source, $value): void {
			$source->where('this.createdTs >= :created_from', ['created_from' => $value]);
		}, '', 'date_from', null)->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Importováno od');

		$grid->addFilterDatetime(function (ICollection $source, $value): void {
			$source->where('this.createdTs <= :created_to', ['created_to' => $value]);
		}, '', 'created_to', null)->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Importováno do');

		$grid->addFilterCheckboxInput('notmapped', "fk_$property IS NOT NULL", 'Napárované');


		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentForm(): AdminForm
	{
		$form = $this->formFactory->create();

		if ($this->tab === 'category') {
			$form->addDataSelect('category', 'Kategorie', $this->categoryRepository->getTreeArrayForSelect())->setPrompt('Nepřiřazeno');
		}

		if ($this->tab === 'producer') {
			$form->addText('name', 'Název / hodnota')->setHtmlAttribute('readonly', 'readonly');
			$form->addDataSelect('producer', 'Výrobce', $this->producerRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
		}

		if ($this->tab === 'attribute') {
			$form->addText('name', 'Název / hodnota')->setHtmlAttribute('readonly', 'readonly');
			$form->addSelect2Ajax('attribute', $this->link('getAttributes!'), 'Atribut', [], 'Nepřiřazeno')->setPrompt('Nepřiřazeno');
		}

		if ($this->tab === 'attributeValue') {
			$form->addText('label', 'Popisek')->setHtmlAttribute('readonly', 'readonly');
			$form->addSelect2Ajax('attributeValue', $this->link('getAttributeValues!'), 'Hodnota', [], 'Nepřiřazeno')->setPrompt('Nepřiřazeno');
		}

		if ($this->tab === 'amount') {
			$form->addText('name', 'Název / hodnota')->setHtmlAttribute('readonly', 'readonly');
			$form->addDataSelect('displayAmount', 'Dostupnost', $this->displayAmountRepository->getArrayForSelect())->setPrompt('Nepřiřazeno');
			$form->addInteger('storeAmount', 'Skladová zásoba')->setNullable();
		}

		$form->addHidden('supplier');

		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if ($this->tab === 'attribute' || $this->tab === 'attributeValue') {
				$rawValues = $this->getHttpRequest()->getPost();
				$values[$this->tab] = $rawValues[$this->tab] !== '' ? $rawValues[$this->tab] : null;
			}

			$supplierMapping = $this->getMappingRepository()->syncOne($values, null, true);

			$this->categoryRepository->clearCategoriesCache();

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$supplierMapping]);
		};

		return $form;
	}

	public function handleGetAttributes(?string $q = null, ?int $page = null): void
	{
		if (!$q) {
			$this->payload->results = [];
			$this->sendPayload();
		}

		$payload = $this->attributeRepository->getAttributesForAdminAjax($q, $page);

		$this->payload->results = $payload['results'];
		$this->payload->pagination = $payload['pagination'];

		$this->sendPayload();
	}

	public function createComponentMappingForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$totalIds = $this->session->getSection('bulkEdit')->get('totalIds');
		$ids = \unserialize($this->getParameter('selectedIds'));
		$totalNo = \count($totalIds);
		$selectedNo = \count($ids);

		$form->addRadioList('bulkType', 'Upravit', [
			'selected' => "vybrané ($selectedNo)",
			'all' => "celý výsledek ($totalNo)",
		])->setDefaultValue('selected');

		$form->addCheckbox('overwrite', 'Přepsat');

		if ($this->tab === 'category') {
			$categoryInput = $form->addDataSelect('category', 'Nadřazená kategorie', $this->categoryRepository->getArrayForSelect())->setPrompt('Žádná');
			$categoryTypeInput = $form->addSelect('categoryType', 'Typ kategorií', $this->categoryTypeRepository->getArrayForSelect());

			$categoryInput->addCondition($form::BLANK)->toggle($categoryTypeInput->getHtmlId() . '-toogle');
		}

		if ($this->tab === 'attribute') {
			$form->addCheckbox('mapValues', 'Přiřadit hodnoty');
			$form->addCheckbox('overwriteValues', 'Přepsat hodnoty');
		}

		if ($this->tab === 'attributeValue') {
			$form->addSelect2Ajax('attribute', $this->link('getAttributes!'), 'Atribut');
		}

		$form->addSubmits(false, false);

		$form->onValidate[] = function (AdminForm $form): void {
			if ($form->hasErrors()) {
				return;
			}

			$rawValues = $this->getHttpRequest()->getPost();

			if (isset($rawValues['attribute']) || !isset($form['attribute'])) {
				return;
			}

			/** @var \Nette\Forms\Controls\SelectBox $attributeInput */
			$attributeInput = $form['attribute'];
			$attributeInput->addError('Toto pole je povinné!');
		};

		$form->onSuccess[] = function (AdminForm $form) use ($ids, $totalIds): void {
			$values = $form->getValues('array');
			$rawValues = $this->getHttpRequest()->getPost();

			$overwrite = $values['overwrite'];
			$data = $values['bulkType'] === 'selected' ? $ids : $totalIds;

			if ($this->tab === 'producer') {
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
							'name' => ['cs' => $supplierProducer->name, 'en' => null],
						]);

						$supplierProducer->update(['producer' => $producer->getPK()]);
					}
				}
			} elseif ($this->tab === 'amount') {
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
							'label' => ['cs' => $supplierAmount->name, 'en' => null],
						]);

						$supplierAmount->update(['displayAmount' => $displayAmount->getPK()]);
					}
				}
			} elseif ($this->tab === 'attribute') {
				foreach ($data as $uuid) {
					/** @var \Eshop\DB\SupplierAttribute $supplierAttribute */
					$supplierAttribute = $this->supplierAttributeRepository->many()
						->join(['assign' => 'eshop_supplierattributecategoryassign'], 'this.uuid = assign.fk_supplierAttribute')
						->join(['supplierCategory' => 'eshop_suppliercategory'], 'assign.fk_supplierCategory = supplierCategory.uuid')
						->select(['categoryPK' => 'supplierCategory.fk_category'])
						->where('this.uuid', $uuid)
						->first();

					if (!$supplierAttribute->name) {
						continue;
					}

					if ($supplierAttribute->attribute) {
						if ($supplierAttribute->getValue('categoryPK')) {
							$supplierAttribute->attribute->categories->relate([$supplierAttribute->getValue('categoryPK')]);
						}

						if ($overwrite) {
							$supplierAttribute->attribute->update([
								'name' => ['cs' => $supplierAttribute->name],
								'supplier' => $supplierAttribute->getValue('supplier'),
							]);
						}
					} else {
						$tempAttribute = $supplierAttribute->code ? $this->attributeRepository->many()->where('code', $supplierAttribute->code)->first() : true;
						$code = $tempAttribute ? '' : $supplierAttribute->code;

						while ($tempAttribute !== null) {
							$code .= Random::generate(4, '0-9');
							$tempAttribute = $this->attributeRepository->many()->where('code', $code)->first();
						}

						/** @var \Eshop\DB\Attribute $attribute */
						$attribute = $this->attributeRepository->createOne([
							'code' => $code,
							'name' => ['cs' => $supplierAttribute->name, 'en' => null],
							'supplier' => $supplierAttribute->getValue('supplier'),
						]);

						if ($supplierAttribute->getValue('categoryPK')) {
							$attribute->categories->relate([$supplierAttribute->getValue('categoryPK')]);
						}

						$supplierAttribute->update(['attribute' => $attribute->getPK()]);

						if ($values['mapValues']) {
							/** @var \Eshop\DB\SupplierAttributeValue $supplierAttributeValue */
							foreach ($this->supplierAttributeValueRepository->many()->where('fk_supplierAttribute', $supplierAttribute->getPK()) as $supplierAttributeValue) {
								if ($supplierAttributeValue->attributeValue) {
									if ($values['overwriteValues']) {
										$supplierAttributeValue->attributeValue->update(['label' => ['cs' => $supplierAttributeValue->label, 'en' => null]]);
									}
								} else {
									$tempAttribute = $supplierAttributeValue->code ? $this->attributeValueRepository->many()->where('code', $supplierAttributeValue->code)->first() : true;
									$code = $tempAttribute ? '' : $supplierAttributeValue->code;

									while ($tempAttribute !== null) {
										$code .= Random::generate(4, '0-9');
										$tempAttribute = $this->attributeValueRepository->many()->where('code', $code)->first();
									}

									/** @var \Eshop\DB\AttributeValue $attributeValue */
									$attributeValue = $this->attributeValueRepository->createOne([
										'code' => $code,
										'label' => ['cs' => $supplierAttributeValue->label, 'en' => null],
										'attribute' => $attribute,
									]);

									$supplierAttributeValue->update(['attributeValue' => $attributeValue->getPK()]);
								}
							}
						}
					}
				}
			} elseif ($this->tab === 'attributeValue') {
				/** @var \Eshop\DB\Attribute $attribute */
				$attribute = $this->attributeRepository->one($rawValues['attribute'], true);

				foreach ($data as $uuid) {
					/** @var \Eshop\DB\SupplierAttributeValue $supplierAttributeValue */
					$supplierAttributeValue = $this->supplierAttributeValueRepository->one($uuid);

					if (!$supplierAttributeValue->label) {
						continue;
					}

					if ($supplierAttributeValue->attributeValue) {
						if ($overwrite) {
							$supplierAttributeValue->attributeValue->update(['label' => ['cs' => $supplierAttributeValue->label, 'en' => null]]);
						}
					} else {
						$tempAttribute = $supplierAttributeValue->code ? $this->attributeValueRepository->many()->where('code', $supplierAttributeValue->code)->first() : true;
						$code = $tempAttribute ? '' : $supplierAttributeValue->code;

						while ($tempAttribute !== null) {
							$code .= Random::generate(4, '0-9');
							$tempAttribute = $this->attributeValueRepository->many()->where('code', $code)->first();
						}

						/** @var \Eshop\DB\AttributeValue $attributeValue */
						$attributeValue = $this->attributeValueRepository->createOne([
							'code' => $code,
							'label' => ['cs' => $supplierAttributeValue->label, 'en' => null],
							'attribute' => $attribute,
						]);

						$supplierAttributeValue->update(['attributeValue' => $attributeValue->getPK()]);
					}
				}
			} elseif ($this->tab === 'category') {
				/** @var \Eshop\DB\Category|null $insertToCategory */
				$insertToCategory = $values['category'] ? $this->categoryRepository->one($values['category']) : null;
				$type = $insertToCategory ? $insertToCategory->getValue('type') : $values['categoryType'];

				foreach ($data as $uuid) {
					/** @var \Eshop\DB\SupplierCategory $supplierCategory */
					$supplierCategory = $this->supplierCategoryRepository->one($uuid);

					if (!$supplierCategory->categoryNameL1) {
						continue;
					}

					$newTree = [$supplierCategory->categoryNameL1];

					if ($supplierCategory->categoryNameL2) {
						$newTree[] = $supplierCategory->categoryNameL2;
					}

					if ($supplierCategory->categoryNameL3) {
						$newTree[] = $supplierCategory->categoryNameL3;
					}

					if ($supplierCategory->categoryNameL4) {
						$newTree[] = $supplierCategory->categoryNameL4;
					}

					if ($supplierCategory->categoryNameL5) {
						$newTree[] = $supplierCategory->categoryNameL5;
					}

					if ($supplierCategory->categoryNameL6) {
						$newTree[] = $supplierCategory->categoryNameL6;
					}

					$currentCategory = $insertToCategory;
					$path = $insertToCategory ? $insertToCategory->path : null;
					$first = true;

					foreach ($newTree as $cKey) {
						$originalPath = $currentCategory ? $currentCategory->path : '';

						do {
							$tempPath = $path . Random::generate(4, '0-9a-z');
							$tempCategory = $this->categoryRepository->many()->where('path', $tempPath)->first();
						} while ($tempCategory);

						/** @var \Eshop\DB\Category|null $existingCategory */
						$existingCategory = $this->categoryRepository->many()->where('fk_type', $type)->where('path LIKE :s', ['s' => "$originalPath%"])->where('name_cs', $cKey)->first();

						$path = $existingCategory ? $existingCategory->path : $tempPath;

						$newCategoryData = [
							'name' => ['cs' => $cKey, 'en' => null],
							'path' => $path,
							'ancestor' => $currentCategory ? $currentCategory->getPK() : null,
							'type' => $type,
						];

						if ($existingCategory) {
							$existingCategory->update($newCategoryData);
							$currentCategory = $existingCategory;
						} elseif ($supplierCategory->category && Arrays::last($newTree) === $cKey) {
							if ($supplierCategory->category->getValue('ancestor') && $supplierCategory->category->getValue('ancestor') === $newCategoryData['ancestor']) {
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

						if (!$first) {
							continue;
						}

						$newFirstCategory = $currentCategory;
						$first = false;
					}

					$supplierCategory->update(['category' => $currentCategory->getPK()]);
				}

				if (isset($newFirstCategory)) {
					$this->categoryRepository->updateCategoryChildrenPath($newFirstCategory);
				}

				$this->categoryRepository->clearCategoriesCache();
			}

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('default');
		};

		return $form;
	}

	public function actionMapping($selectedIds): void
	{
		unset($selectedIds);
	}

	public function renderMapping($selectedIds): void
	{
		unset($selectedIds);

		$this->template->headerLabel = 'Vytvořit strukturu';
		$this->template->headerTree = [
			['Mapování', 'default'],
			['Vytvořit strukturu'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('mappingForm')];
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Rozřazení';
		$this->template->headerTree = [
			['Rozřazení'],
		];

		$this->template->tabs = $this->TABS;
		$this->template->displayButtons = [];

		if ($this->tab === 'mapping') {
			$this->template->displayControls = [$this->getComponent('mappingGrid')];
			$this->template->displayButtons = [$this->createNewItemButton('newMapping')];
		} else {
			$this->template->displayControls = [$this->getComponent('grid')];
		}
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Mapování', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function renderDetail(string $uuid): void
	{
		unset($uuid);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Mapování', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function actionDetail(string $uuid): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');

		/** @var \Eshop\DB\SupplierAttribute|\Eshop\DB\SupplierAttributeValue|\Eshop\DB\SupplierCategory|\Eshop\DB\SupplierProducer|\Eshop\DB\SupplierDisplayAmount|\Eshop\DB\SupplierMapping $object */
		$object = $this->getMappingRepository()->one($uuid);

		if ($this->tab === 'attribute' && $object->attribute) {
			/** @var \Nette\Forms\Controls\SelectBox $attributeInput */
			$attributeInput = $form['attribute'];

			$this->getPresenter()->template->select2AjaxDefaults[$attributeInput->getHtmlId()] = [$object->attribute->getPK() => $object->attribute->name ?? $object->attribute->code];
		}

		if ($this->tab === 'attributeValue' && $object->attributeValue) {
			/** @var \Nette\Forms\Controls\SelectBox $attributeValueInput */
			$attributeValueInput = $form['attributeValue'];

			$this->getPresenter()->template->select2AjaxDefaults[$attributeValueInput->getHtmlId()] = [
				$object->attributeValue->getPK() => ($object->attributeValue->attribute->name ?? $object->attributeValue->attribute->code) .
					' - ' .
					($object->attributeValue->label ?? $object->attributeValue->code),
			];
		}

		$form->setDefaults($object->toArray());
	}

	protected function startup(): void
	{
		parent::startup();

		if (!isset($this::CONFIGURATION['attributes']) || !$this::CONFIGURATION['attributes']) {
			return;
		}

		$this->TABS['attribute'] = 'Atributy';
		$this->TABS['attributeValue'] = 'Hodnoty atributů';
	}

	private function getMappingRepository(): Repository
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

		if ($this->tab === 'attribute') {
			return $this->supplierAttributeRepository;
		}

		if ($this->tab === 'attributeValue') {
			return $this->supplierAttributeValueRepository;
		}

		throw new \DomainException('Invalid state');
	}
}
