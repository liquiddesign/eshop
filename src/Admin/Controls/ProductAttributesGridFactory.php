<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\DB\Attribute;
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
use Nette\Application\UI\Presenter;
use Nette\Forms\Controls\HiddenField;
use Nette\Forms\Controls\MultiSelectBox;
use Nette\Forms\Controls\SelectBox;
use Nette\Http\Session;
use Web\DB\PageRepository;
use Grid\Datagrid;
use Nette\DI\Container;
use Admin\Controls\AdminGridFactory;
use function Clue\StreamFilter\fun;

class ProductAttributesGridFactory
{
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
	)
	{
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

//		$grid->addColumn('Kód a EAN', function (Product $product) {
//			return $product->getFullCode() . ($product->ean ? "<br><small>EAN $product->ean</small>" : '');
//		}, '%s', 'code', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumn('Název', function (Product $product, $grid) {
			return [$grid->getPresenter()->link(':Eshop:Product:detail', ['product' => (string)$product]), $product->name];
		}, '<a style="" href="%s" target="_blank"> %s</a>', 'name')->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->onLoadState[] = function (Datagrid $grid, array $params) {
			$filters = $this->session->getSection('admingrid-' . $grid->getPresenter()->getName() . $grid->getName())->filters;

			$category = $grid->getPresenter()->getHttpRequest()->getQuery('productAttributesGrid-category') ?? ($filters['category'] ?? null);

			if (!$category) {
				return;
			}

			/** @var Attribute[] $attributes */
			$attributes = $this->attributeRepository->getAttributesByCategories([$category], true);

			foreach ($attributes as $attribute) {
				$values = $this->attributeRepository->getAttributeValues($attribute, true);

				$grid->addColumnInput($attribute->name, $attribute->getPK(), function () use ($values, $grid, $attribute) {
					$selectBox = new MultiSelectBox(null, $values->toArrayOf('internalLabel'));
					$selectBox->setHtmlAttribute('class', 'form-control form-control-sm');
					$selectBox->setHtmlAttribute('style', 'max-width: 50px;');
					$selectBox->checkDefaultValue(false);

					return $selectBox;
				}, function (MultiSelectBox $selectBox, Product $product) use ($attribute) {
					if ($product->attributeValues) {
						$selectBox->setDefaultValue(\explode(',', $product->attributeValues));
					}
				});
			}
		};

		$grid->addButtonSaveAll([], [], null, false, null, null, true, function () use ($grid) {
			$data = $grid->getPresenter()->getHttpRequest()->getPost();

			foreach ($data as $column => $row) {
				if (!\is_array($row)) {
					continue;
				}

				$possibleValues = $this->attributeValueRepository->many()->where('fk_attribute', $column)->toArray();

				foreach ($row as $product => $values) {
					$this->attributeAssignRepository->many()->where('fk_product', $product)->where('fk_value', \array_keys($possibleValues))->delete();

					foreach ($values as $value) {
						$this->attributeAssignRepository->syncOne([
							'value' => $value,
							'product' => $product
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
