<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminGrid;
use Admin\Controls\AdminGridFactory;
use Eshop\DB\AttributeAssignRepository;
use Eshop\DB\AttributeRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Grid\Datagrid;
use Nette\Forms\Controls\MultiSelectBox;
use Nette\Http\Session;

class ProductAttributesGridFactory
{
	public ?string $category;

	private ProductRepository $productRepository;

	private AdminGridFactory $gridFactory;

	private ProductGridFiltersFactory $productGridFiltersFactory;

	private AttributeRepository $attributeRepository;

	private AttributeAssignRepository $attributeAssignRepository;

	private Session $session;

	/**
	 * @var \Eshop\DB\Attribute[]
	 */
	private array $attributes = [];

	public function __construct(
		\Admin\Controls\AdminGridFactory $gridFactory,
		ProductRepository $productRepository,
		ProductGridFiltersFactory $productGridFiltersFactory,
		AttributeRepository $attributeRepository,
		Session $session,
		AttributeAssignRepository $attributeAssignRepository
	) {
		$this->productRepository = $productRepository;
		$this->gridFactory = $gridFactory;
		$this->productGridFiltersFactory = $productGridFiltersFactory;
		$this->attributeRepository = $attributeRepository;
		$this->session = $session;
		$this->attributeAssignRepository = $attributeAssignRepository;
	}

	public function create(array $configuration): Datagrid
	{
		unset($configuration);

		$source = $this->productRepository->many()->setGroupBy(['this.uuid'])
			->join(['assign' => 'eshop_attributeassign'], 'this.uuid = assign.fk_product')
			->join(['attributeValue' => 'eshop_attributevalue'], 'attributeValue.uuid = assign.fk_value')
			->select(['attributeValues' => "GROUP_CONCAT(DISTINCT attributeValue.uuid SEPARATOR ',')"]);

		$grid = $this->gridFactory->create($source, 20, 'this.priority', 'ASC', false);
		$grid->setItemsPerPage([5, 10, 20, 50, 100]);

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
			$attributes = $this->attributes = $this->attributeRepository->getAttributesByCategory($category->path, true)->toArray();

			foreach ($attributes as $attribute) {
				$values = $this->attributeRepository->getAttributeValues($attribute, true);

				$grid->addColumnInput($attribute->name, $attribute->getPK(), function () use ($values) {
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
			}
		};

		$grid->addButtonSaveAll([], [], null, false, null, null, true, function () use ($grid): void {
			$data = $grid->getPresenter()->getHttpRequest()->getPost();

			foreach ($data as $row) {
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

			foreach ($data as $row) {
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
