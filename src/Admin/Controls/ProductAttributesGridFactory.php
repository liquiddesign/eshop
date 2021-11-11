<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminGrid;
use Admin\Controls\AdminGridFactory;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\AttributeRepository;
use Eshop\DB\AttributeValueRepository;
use Eshop\DB\CategoryRepository;
use Eshop\DB\DisplayAmountRepository;
use Eshop\DB\InternalRibbonRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\ProducerRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\DB\RibbonRepository;
use Eshop\DB\SupplierCategoryRepository;
use Eshop\DB\SupplierRepository;
use Eshop\DB\TagRepository;
use Grid\Datagrid;
use Nette\DI\Container;
use Nette\Forms\Controls\MultiSelectBox;
use Nette\Http\Session;
use Web\DB\PageRepository;

class ProductAttributesGridFactory
{
	public ?string $category;

	private ProductRepository $productRepository;

	private AdminGridFactory $gridFactory;

	private ProducerRepository $producerRepository;

	private SupplierRepository $supplierRepository;

	private SupplierCategoryRepository $supplierCategoryRepository;

	private CategoryRepository $categoryRepository;

	private RibbonRepository $ribbonRepository;

	private InternalRibbonRepository $internalRibbonRepository;

	private TagRepository $tagRepository;

	private PageRepository $pageRepository;

	private Container $container;

	private PricelistRepository $pricelistRepository;

	private DisplayAmountRepository $displayAmountRepository;

	private ProductGridFiltersFactory $productGridFiltersFactory;

	private AttributeRepository $attributeRepository;

	private AttributeValueRepository $attributeValueRepository;

	private AttributeAssignRepository $attributeAssignRepository;

	private Session $session;

	private array $attributes = [];

	public function __construct(
		\Admin\Controls\AdminGridFactory $gridFactory,
		Container $container,
		PageRepository $pageRepository,
		ProductRepository $productRepository,
		ProducerRepository $producerRepository,
		SupplierRepository $supplierRepository,
		SupplierCategoryRepository $supplierCategoryRepository,
		CategoryRepository $categoryRepository,
		RibbonRepository $ribbonRepository,
		InternalRibbonRepository $internalRibbonRepository,
		TagRepository $tagRepository,
		PricelistRepository $pricelistRepository,
		DisplayAmountRepository $displayAmountRepository,
		ProductGridFiltersFactory $productGridFiltersFactory,
		AttributeRepository $attributeRepository,
		AttributeValueRepository $attributeValueRepository,
		Session $session,
		AttributeAssignRepository $attributeAssignRepository
	) {
		$this->productRepository = $productRepository;
		$this->gridFactory = $gridFactory;
		$this->producerRepository = $producerRepository;
		$this->supplierRepository = $supplierRepository;
		$this->supplierCategoryRepository = $supplierCategoryRepository;
		$this->categoryRepository = $categoryRepository;
		$this->ribbonRepository = $ribbonRepository;
		$this->internalRibbonRepository = $internalRibbonRepository;
		$this->tagRepository = $tagRepository;
		$this->pageRepository = $pageRepository;
		$this->container = $container;
		$this->pricelistRepository = $pricelistRepository;
		$this->displayAmountRepository = $displayAmountRepository;
		$this->productGridFiltersFactory = $productGridFiltersFactory;
		$this->attributeRepository = $attributeRepository;
		$this->attributeValueRepository = $attributeValueRepository;
		$this->session = $session;
		$this->attributeAssignRepository = $attributeAssignRepository;
	}

	public function create(array $configuration): Datagrid
	{
		$connection = $this->attributeRepository->getConnection();
		$mutationSuffix = $connection->getMutationSuffix();

		$source = $this->productRepository->many()->setGroupBy(['this.uuid'])
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_product')
			->join(['attributeValue' => 'eshop_attributevalue'], 'attributeValue.uuid = assign.fk_value')
			->select(['attributeValues' => "GROUP_CONCAT(DISTINCT attributeValue.uuid SEPARATOR ',')"]);

		$grid = $this->gridFactory->create($source, 20, 'this.priority', 'ASC', false);
		$grid->setItemsPerPage([5, 10, 20, 50, 100]);

//		$grid->addColumn('Kód a EAN', function (Product $product) {
//			return $product->getFullCode() . ($product->ean ? "<br><small>EAN $product->ean</small>" : '');
//		}, '%s', 'code', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumn('Název', function (Product $product, $grid) {
			return [$grid->getPresenter()->link(':Eshop:Product:detail', ['product' => (string)$product]), $product->name];
		}, '<a style="" href="%s" target="_blank"> %s</a>', 'name')->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->onAnchor[] = function (AdminGrid $grid): void {
			$grid->template->setFile(__DIR__ . '/productAttributesGrid.latte');
		};

		$grid->onLoadState[] = function (Datagrid $grid, array $params): void {
			$filters = $this->session->getSection('admingrid-' . $grid->getPresenter()->getName() . $grid->getName())->filters;

			$this->category = $category = $grid->getPresenter()->getHttpRequest()->getQuery('productAttributesGrid-category') ?? ($filters['category'] ?? null);
			$grid->template->category = $category;

			if (!$category) {
				return;
			}

			/** @var \Eshop\DB\Attribute[] $attributes */
			$this->attributes = $attributes = $this->attributeRepository->getAttributesByCategories([$category], true)->toArray();

			foreach ($attributes as $attribute) {
				$values = $this->attributeRepository->getAttributeValues($attribute, true);

				$column = $grid->addColumnInput($attribute->name, $attribute->getPK(), function () use ($values) {
					$selectBox = new MultiSelectBox(null, $values->toArrayOf('internalLabel'));
					$selectBox->setHtmlAttribute('class', 'form-control form-control-sm');
					$selectBox->setHtmlAttribute('style', 'max-width: 50px;');
					$selectBox->checkDefaultValue(false);

					return $selectBox;
				}, function (MultiSelectBox $selectBox, Product $product): void {
					if ($product->attributeValues) {
						$selectBox->setDefaultValue(\explode(',', $product->attributeValues));
					}
				});

//				$column->onRenderCell[] = function (\Nette\Utils\Html $td, $object) {
//					$el = $td->getChildren()[0];
//					$td->removeChildren();
//					$startPos = \strpos($el, 'name="');
//					$endPos = \strpos($el, '"', $startPos + 6);
//					$name = \substr($el, $startPos + 6, $endPos - $startPos - 6);
//					$td->addHtml('<input type="hidden" value="" name="' . $name . '">');
//					$td->addHtml($el);
//				};
			}
		};

		$grid->addButtonSaveAll([], [], null, false, null, null, true, function () use ($grid): void {
			$data = $grid->getPresenter()->getHttpRequest()->getPost();

			foreach ($data as $column => $row) {
				if (!\is_array($row)) {
					continue;
				}

				foreach ($row as $product => $values) {
					$this->attributeAssignRepository->many()
						->join(['attributeValues' => 'eshop_attributevalue'], 'this.fk_value = attributeValues.uuid')
						->where('this.fk_product', $product)
						->where('attributeValues.fk_attribute', \array_keys($this->attributes))
						->delete();
				}
			}

			foreach ($data as $column => $row) {
				if (!\is_array($row)) {
					continue;
				}

				foreach ($row as $product => $values) {
					foreach ($values as $value) {
						$this->attributeAssignRepository->syncOne([
							'value' => $value,
							'product' => $product,
						]);
					}
				}
			}
		});

		$this->productGridFiltersFactory->addFilters($grid);
		$grid->addFilterButtons();

		return $grid;
	}
}
