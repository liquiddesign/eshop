<?php

declare(strict_types=1);

namespace Eshop\DB;

use Common\DB\IGeneralRepository;
use Eshop\Shopper;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Utils\Arrays;
use Nette\Utils\Random;
use Pages\Helpers;
use StORM\Expression;
use Web\DB\Page;
use Web\DB\PageRepository;
use StORM\Collection;
use StORM\DIConnection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Category>
 */
class CategoryRepository extends \StORM\Repository implements IGeneralRepository
{
	private Cache $cache;

	private Shopper $shopper;

	private PageRepository $pageRepository;

	private ProducerRepository $producerRepository;

	public function __construct(
		DIConnection       $connection,
		SchemaManager      $schemaManager,
		Shopper            $shopper,
		Storage            $storage,
		PageRepository     $pageRepository,
		ProducerRepository $producerRepository
	)
	{
		parent::__construct($connection, $schemaManager);
		$this->cache = new Cache($storage);
		$this->shopper = $shopper;
		$this->pageRepository = $pageRepository;
		$this->producerRepository = $producerRepository;
	}

	public function clearCategoriesCache()
	{
		$this->cache->clean([
			Cache::TAGS => ['categories'],
		]);
	}

	public function getProducerPages(bool $includeInactive = true): array
	{
		return $this->cache->load('categoryProducerPages', function (&$dependencies) use ($includeInactive) {
			$dependencies = [
				Cache::TAGS => 'categories',
			];

			$mutationSuffix = $this->pageRepository->getConnection()->getMutationSuffix();

			$pages = $this->pageRepository->many()->where('type', 'product_list')->setOrderBy(['this.priority']);

			if (!$includeInactive) {
				$pages->where("active$mutationSuffix", true);
			}

			$producerPages = [];

			/** @var Page $page */
			while ($page = $pages->fetch()) {
				$params = $page->getParsedParameters();

				if (!isset($params['category']) || !isset($params['producer'])) {
					continue;
				}

				$producerPages[$params['category']][] = [$page, $this->producerRepository->one($params['producer'])];
			}

			return $producerPages;
		});
	}

	/**
	 * @param string|null $typeId
	 * @param bool $cache
	 * @return \Eshop\DB\Category[]
	 * @throws \Throwable
	 */
	public function getTree(?string $typeId = null, bool $cache = true, bool $includeHidden = false): array
	{
		$repository = $this;

		// @TODO: jen viditelne kategorie pro daneho uzivatele, cistit cache po prihlaseni, take dle jazyku (muze byt jine poradi)

		if ($cache) {
			return $this->cache->load("categoryTree-" . ($typeId ?: ''), function (&$dependencies) use ($repository, $typeId, $includeHidden) {
				$dependencies = [
					Cache::TAGS => 'categories',
				];

				return $this->getTreeHelper($typeId, $repository, $includeHidden);
			});
		} else {
			return $this->getTreeHelper($typeId, $repository, $includeHidden);
		}
	}

	private function getTreeHelper($typeId, CategoryRepository $repository, bool $includeHidden = false)
	{
		$collection = $repository->getCategories($includeHidden)->where('LENGTH(path) <= 40');

		if ($typeId) {
			$collection->where('this.fk_type', $typeId);
		}

		return $repository->buildTree($collection->toArray(), null);
	}
	
	public function getCountsGrouped(string $groupBy, array $filters = [], ?array $pricelists = null): array
	{
		if ($pricelists === null) {
			$pricelists = $this->shopper->getPricelists()->toArray();
		}
		
		\ksort($filters);
		$cacheIndex = $groupBy . \implode('_', \array_keys($pricelists)) . \http_build_query($filters);
		$rows = $this->many();
		$productRepository = $this->getConnection()->findRepository(Product::class);
		
		return $this->cache->load($cacheIndex, static function (&$dependencies) use ($rows, $productRepository, $pricelists, $filters) {
			$dependencies = [
				Cache::TAGS => ['categories', 'products', 'pricelists', 'attributes'],
			];
			
			$rows->setFrom(['category' => 'eshop_category']);
			$rows->setSmartJoin(true, Product::class);
			$rows->setFetchClass(\stdClass::class);
			
			$rows->join(['subs' => 'eshop_category'], 'subs.path LIKE CONCAT(category.path,"%")')
				->join(['nxn' => 'eshop_product_nxn_eshop_category'], 'nxn.fk_category=subs.uuid')
				->join(['this' => 'eshop_product'],
					"nxn.fk_product=this.uuid AND this.hidden = 0")
				->setSelect(['category' => 'category.uuid', 'grouped' => $groupBy, 'count' => 'COUNT(this.uuid)'])
				->setGroupBy(['category.uuid', $groupBy]);
			
			if ($groupBy === 'assign.fk_value') {
				$rows->join(['assign' => 'eshop_attributeassign'],
					"assign.fk_product=this.uuid");
			}
			
			$priceWhere = new Expression();
			
			foreach (\array_keys($pricelists) as $id => $pricelist) {
				$rows->join(["prices$id" => 'eshop_price'],
					"prices$id.fk_product=this.uuid AND prices$id.fk_pricelist = '" . $pricelist . "'");
				$priceWhere->add('OR', "prices$id.price IS NOT NULL");
			}
			
			if ($priceWhere->getSql()) {
				$rows->where($priceWhere->getSql());
			}
			
			$productRepository->filter($rows, $filters);
			
			$results = [];
			
			foreach ($rows->toArray() as $result) {
				$results[$result->category]['total'] ??= 0;
				$results[$result->category]['grouped'] ??= [];
				
				$results[$result->category]['total'] += $result->count;
				
				if ($result->grouped) {
					$results[$result->category]['grouped'][$result->grouped] = $result->count;
				}
			}
			
			return $results;
		});
	}

	/**
	 * @deprecated User getCountsByAttributes instead
	 */
	public function getCounts(array $pricelists = []): array
	{
		$currency = $this->shopper->getCurrency();
		$suffix = $this->getConnection()->getMutationSuffix();
		$pricelists = $pricelists ? $pricelists : \array_values($this->shopper->getPricelists($currency->isConversionEnabled() ? $currency->convertCurrency : null)->toArray());

		if (\count($pricelists) == 0) {
			return [];
		}

		$cacheIndex = "catagories_counts$suffix";

		foreach ($pricelists as $pricelist) {
			$cacheIndex .= '_' . $pricelist->getPK();
		}

		$rows = $this->many();

		return $this->cache->load($cacheIndex, static function (&$dependencies) use ($rows, $suffix, $pricelists) {
			$dependencies = [
				Cache::TAGS => ['categories', 'products', 'pricelists'],
			];

			$rows->join(['subs' => 'eshop_category'], 'subs.path LIKE CONCAT(this.path,"%")')
				->join(['nxn' => 'eshop_product_nxn_eshop_category'], 'nxn.fk_category=subs.uuid')
				->join(['product' => 'eshop_product'],
					"nxn.fk_product=product.uuid AND product.hidden = 0 AND product.fk_alternative IS NULL")
				->setSelect(['count' => 'COUNT(product.uuid)'])
				->setGroupBy(['this.uuid']);

			$priceWhere = [];

			foreach ($pricelists as $id => $pricelist) {
				$rows->join(["prices$id" => 'eshop_price'],
					"prices$id.fk_product=product.uuid AND prices$id.fk_pricelist = '" . $pricelist->getPK() . "'");
				$priceWhere[] = "prices$id.price IS NOT NULL";
			}

			if ($priceWhere) {
				$rows->where(\implode(' OR ', $priceWhere));
			}

			$rows->setIndex('this.uuid');
			$rows->setFetchClass(\stdClass::class);

			return $rows->toArrayOf('count');
		});
	}

	/**
	 * Updates all paths of children of category.
	 * @param \Eshop\DB\Category $category
	 * @throws \Throwable
	 */
	public function updateCategoryChildrenPath(Category $category): void
	{
		$tree = $this->getTree(null, false, true);

		foreach ($tree as $item) {
			if ($item->getPK() == $category->getPK()) {
				$startCategory = $item;
				break;
			}

			if (\str_contains($category->path, $item->path)) {
				$startCategory = $this->findCategoryInTree($item, $category);

				if ($startCategory) {
					break;
				}
			}
		}

		if (isset($startCategory)) {
			$startCategory->setParent($this);
			$startCategory->update(['path' => $category->path]);

			foreach ($startCategory->children as $child) {
				$child->setParent($this);
				$child->update(['path' => $startCategory->path . \substr($child->path, -4)]);

				if (\count($child->children) > 0) {
					$this->doUpdateCategoryChildrenPath($child);
				}
			}
		}

		$this->clearCategoriesCache();
	}

	private function findCategoryInTree(Category $category, Category $targetCategory): ?Category
	{
		foreach ($category->children as $child) {
			if ($child->getPK() == $targetCategory->getPK()) {
				return $child;
			}

			$returnCategory = $this->findCategoryInTree($child, $targetCategory);

			if ($returnCategory) {
				return $returnCategory;
			}
		}

		return null;
	}

	private function doUpdateCategoryChildrenPath(Category $category): void
	{
		foreach ($category->children as $child) {
			$child->setParent($this);
			$child->update(['path' => $category->path . \substr($child->path, -4)]);

			if (\count($child->children) > 0) {
				$this->doUpdateCategoryChildrenPath($child);
			}
		}
	}

	/**
	 * @param \Eshop\DB\Category[] $elements
	 * @param string|null $ancestorId
	 * @return \Eshop\DB\Category[]
	 */
	private function buildTree(array $elements, ?string $ancestorId = null): array
	{
		$branch = [];

		foreach ($elements as $element) {
			if ($element->getValue('ancestor') === $ancestorId) {
				if ($children = $this->buildTree($elements, $element->getPK())) {
					$element->children = $children;
				}

				$branch[] = $element;
			}
		}

		return $branch;
	}

	public function getArrayForSelect(bool $includeHidden = true): array
	{
		$suffix = $this->getConnection()->getMutationSuffix();

		return $this->many()->orderBy(["name$suffix"])->toArrayOf('name');
	}

	public function getTreeArrayForSelect(bool $includeHidden = true, string $type = null): array
	{
		$collection = $this->getCategories($includeHidden)->where('LENGTH(path) <= 40');

		if ($type) {
			$collection->where('fk_type', $type);
		}

		$list = [];
		$this->buildTreeArrayForSelect($collection->toArray(), null, $list);

		return $list;
	}

	/**
	 * @param \Eshop\DB\Category[] $elements
	 * @param string|null $ancestorId
	 * @param array $list
	 * @return \Eshop\DB\Category[]
	 */
	private function buildTreeArrayForSelect(array $elements, ?string $ancestorId = null, array &$list = []): array
	{
		$branch = [];

		foreach ($elements as $element) {
			if ($element->getValue('ancestor') === $ancestorId) {
				$list[$element->getPK()] = \str_repeat('--', (\strlen($element->path) / 4) - 1) . " $element->name";

				if ($children = $this->buildTreeArrayForSelect($elements, $element->getPK(), $list)) {
					$element->children = $children;
				}

				$branch[] = $element;
			}
		}

		return $branch;
	}

	public function getCollection(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();
		$collection = $this->many();

		if (!$includeHidden) {
			$collection->where('hidden', false);
		}

		return $collection->orderBy(['priority', "name$suffix"]);
	}

	/**
	 * @param bool $includeHidden
	 * @return \StORM\Collection<\Eshop\DB\Category>|\Eshop\DB\Category[]
	 */
	public function getCategories(bool $includeHidden = false): Collection
	{
		$suffix = $this->getConnection()->getMutationSuffix();

		$collection = $this->many()->orderBy(['priority', "name$suffix"]);

		if (!$includeHidden) {
			$collection->where('hidden', false);
		}

		return $collection;
	}

	public function getRootCategoryOfCategory(Category $category): Category
	{
		if ($category->ancestor == null) {
			return $category;
		}

		return $this->many()
			->where('path', \substr($category->path, 0, 4))
			->first();
	}

	public function getBranch($category): array
	{
		if (!$category instanceof Category) {
			if (!$category = $this->one($category)) {
				return [];
			}
		}

		if ($category->ancestor == null) {
			return [$category->getPK() => $category];
		}

		$categories = [];

		do {
			$categories[$category->getPK()] = $category;
			$category = $category->ancestor;
		} while ($category != null);

		return \array_reverse($categories);
	}

	public function getParameterCategoriesOfCategory($category): ?Collection
	{
		if (!$category instanceof Category) {
			if (!$category = $this->one($category)) {
				return null;
			}
		}

		if ($category->ancestor == null) {
			return $category->parameterCategories;
		}

		do {
			if (\count($category->parameterCategories->toArray()) > 0) {
				return $category->parameterCategories;
			}

			$category = $category->ancestor;
		} while ($category != null);

		return null;
	}

	public function generateCategoryProducerPages(?array $activeProducers = null)
	{
		/** @var Category[] $categories */
		$categories = $this->getCollection(true);

		foreach ($categories as $category) {
			/** @var Producer[] $producers */
			$producers = $this->producerRepository->many()
				->join(['product' => 'eshop_product'], 'product.fk_producer = this.uuid')
				->join(['nxnCategory' => 'eshop_product_nxn_eshop_category'], 'nxnCategory.fk_product = product.uuid')
				->where('nxnCategory.fk_category', \array_keys($this->getBranch($category)));

			foreach ($producers as $producer) {
				$page = $this->pageRepository->getPageByTypeAndParams('product_list', null, ['category' => $category->getPK(), 'producer' => $producer->getPK()]);

				if ($page) {
					continue;
				}

				$values = [];

				$activeProducer = \array_search($producer->code, $activeProducers);

				foreach ($this->getConnection()->getAvailableMutations() as $mutation => $suffix) {
					$urlMutation = $category->getValue('name', $mutation) && $producer->getValue('name', $mutation) ?
						$category->getValue('name', $mutation) . '-' . $producer->getValue('name', $mutation) :
						$category->name . '-' . $producer->name;
					$urlMutation = \strtolower($this->removeAccents($urlMutation));
					$urlMutation = \preg_replace('~[^a-z0-9_/-]+~', '-', $urlMutation);
					$urlMutation = \preg_replace('~-+~', '-', $urlMutation);
					$urlMutation = \preg_replace('~^-~', '', $urlMutation);
					$urlMutation = \preg_replace('~-$~', '', $urlMutation);
					$urlMutation = \urlencode($urlMutation);

					while (!$this->pageRepository->isUrlAvailable($urlMutation, $mutation)) {
						$urlMutation .= '-' . Random::generate(4);
					}

					$values['url'][$mutation] = $urlMutation;

					$nameTitle = $category->getValue('name', $mutation) && $producer->getValue('name', $mutation) ?
						$category->getValue('name', $mutation) . ' ' . $producer->getValue('name', $mutation) :
						$category->name . '-' . $producer->name;

					$values['name'][$mutation] = $nameTitle;
					$values['title'][$mutation] = $nameTitle;
					$values['active'][$mutation] = $activeProducers === null || $activeProducer !== false;
				}


				$values['type'] = 'product_list';
				$values['priority'] = $activeProducers === null || $activeProducer === false ? 10 : $activeProducer;
				$values['params'] = Helpers::serializeParameters(['category' => $category->getPK(), 'producer' => $producer->getPK()]);

				$this->pageRepository->syncOne($values);
			}
		}

		$this->clearCategoriesCache();
	}

	protected function removeAccents(string $string): string
	{
		if (!preg_match('/[\x80-\xff]/', $string))
			return $string;

		$chars = array(
			// Decompositions for Latin-1 Supplement
			chr(195) . chr(128) => 'A', chr(195) . chr(129) => 'A',
			chr(195) . chr(130) => 'A', chr(195) . chr(131) => 'A',
			chr(195) . chr(132) => 'A', chr(195) . chr(133) => 'A',
			chr(195) . chr(135) => 'C', chr(195) . chr(136) => 'E',
			chr(195) . chr(137) => 'E', chr(195) . chr(138) => 'E',
			chr(195) . chr(139) => 'E', chr(195) . chr(140) => 'I',
			chr(195) . chr(141) => 'I', chr(195) . chr(142) => 'I',
			chr(195) . chr(143) => 'I', chr(195) . chr(145) => 'N',
			chr(195) . chr(146) => 'O', chr(195) . chr(147) => 'O',
			chr(195) . chr(148) => 'O', chr(195) . chr(149) => 'O',
			chr(195) . chr(150) => 'O', chr(195) . chr(153) => 'U',
			chr(195) . chr(154) => 'U', chr(195) . chr(155) => 'U',
			chr(195) . chr(156) => 'U', chr(195) . chr(157) => 'Y',
			chr(195) . chr(159) => 's', chr(195) . chr(160) => 'a',
			chr(195) . chr(161) => 'a', chr(195) . chr(162) => 'a',
			chr(195) . chr(163) => 'a', chr(195) . chr(164) => 'a',
			chr(195) . chr(165) => 'a', chr(195) . chr(167) => 'c',
			chr(195) . chr(168) => 'e', chr(195) . chr(169) => 'e',
			chr(195) . chr(170) => 'e', chr(195) . chr(171) => 'e',
			chr(195) . chr(172) => 'i', chr(195) . chr(173) => 'i',
			chr(195) . chr(174) => 'i', chr(195) . chr(175) => 'i',
			chr(195) . chr(177) => 'n', chr(195) . chr(178) => 'o',
			chr(195) . chr(179) => 'o', chr(195) . chr(180) => 'o',
			chr(195) . chr(181) => 'o', chr(195) . chr(182) => 'o',
			chr(195) . chr(182) => 'o', chr(195) . chr(185) => 'u',
			chr(195) . chr(186) => 'u', chr(195) . chr(187) => 'u',
			chr(195) . chr(188) => 'u', chr(195) . chr(189) => 'y',
			chr(195) . chr(191) => 'y',
			// Decompositions for Latin Extended-A
			chr(196) . chr(128) => 'A', chr(196) . chr(129) => 'a',
			chr(196) . chr(130) => 'A', chr(196) . chr(131) => 'a',
			chr(196) . chr(132) => 'A', chr(196) . chr(133) => 'a',
			chr(196) . chr(134) => 'C', chr(196) . chr(135) => 'c',
			chr(196) . chr(136) => 'C', chr(196) . chr(137) => 'c',
			chr(196) . chr(138) => 'C', chr(196) . chr(139) => 'c',
			chr(196) . chr(140) => 'C', chr(196) . chr(141) => 'c',
			chr(196) . chr(142) => 'D', chr(196) . chr(143) => 'd',
			chr(196) . chr(144) => 'D', chr(196) . chr(145) => 'd',
			chr(196) . chr(146) => 'E', chr(196) . chr(147) => 'e',
			chr(196) . chr(148) => 'E', chr(196) . chr(149) => 'e',
			chr(196) . chr(150) => 'E', chr(196) . chr(151) => 'e',
			chr(196) . chr(152) => 'E', chr(196) . chr(153) => 'e',
			chr(196) . chr(154) => 'E', chr(196) . chr(155) => 'e',
			chr(196) . chr(156) => 'G', chr(196) . chr(157) => 'g',
			chr(196) . chr(158) => 'G', chr(196) . chr(159) => 'g',
			chr(196) . chr(160) => 'G', chr(196) . chr(161) => 'g',
			chr(196) . chr(162) => 'G', chr(196) . chr(163) => 'g',
			chr(196) . chr(164) => 'H', chr(196) . chr(165) => 'h',
			chr(196) . chr(166) => 'H', chr(196) . chr(167) => 'h',
			chr(196) . chr(168) => 'I', chr(196) . chr(169) => 'i',
			chr(196) . chr(170) => 'I', chr(196) . chr(171) => 'i',
			chr(196) . chr(172) => 'I', chr(196) . chr(173) => 'i',
			chr(196) . chr(174) => 'I', chr(196) . chr(175) => 'i',
			chr(196) . chr(176) => 'I', chr(196) . chr(177) => 'i',
			chr(196) . chr(178) => 'IJ', chr(196) . chr(179) => 'ij',
			chr(196) . chr(180) => 'J', chr(196) . chr(181) => 'j',
			chr(196) . chr(182) => 'K', chr(196) . chr(183) => 'k',
			chr(196) . chr(184) => 'k', chr(196) . chr(185) => 'L',
			chr(196) . chr(186) => 'l', chr(196) . chr(187) => 'L',
			chr(196) . chr(188) => 'l', chr(196) . chr(189) => 'L',
			chr(196) . chr(190) => 'l', chr(196) . chr(191) => 'L',
			chr(197) . chr(128) => 'l', chr(197) . chr(129) => 'L',
			chr(197) . chr(130) => 'l', chr(197) . chr(131) => 'N',
			chr(197) . chr(132) => 'n', chr(197) . chr(133) => 'N',
			chr(197) . chr(134) => 'n', chr(197) . chr(135) => 'N',
			chr(197) . chr(136) => 'n', chr(197) . chr(137) => 'N',
			chr(197) . chr(138) => 'n', chr(197) . chr(139) => 'N',
			chr(197) . chr(140) => 'O', chr(197) . chr(141) => 'o',
			chr(197) . chr(142) => 'O', chr(197) . chr(143) => 'o',
			chr(197) . chr(144) => 'O', chr(197) . chr(145) => 'o',
			chr(197) . chr(146) => 'OE', chr(197) . chr(147) => 'oe',
			chr(197) . chr(148) => 'R', chr(197) . chr(149) => 'r',
			chr(197) . chr(150) => 'R', chr(197) . chr(151) => 'r',
			chr(197) . chr(152) => 'R', chr(197) . chr(153) => 'r',
			chr(197) . chr(154) => 'S', chr(197) . chr(155) => 's',
			chr(197) . chr(156) => 'S', chr(197) . chr(157) => 's',
			chr(197) . chr(158) => 'S', chr(197) . chr(159) => 's',
			chr(197) . chr(160) => 'S', chr(197) . chr(161) => 's',
			chr(197) . chr(162) => 'T', chr(197) . chr(163) => 't',
			chr(197) . chr(164) => 'T', chr(197) . chr(165) => 't',
			chr(197) . chr(166) => 'T', chr(197) . chr(167) => 't',
			chr(197) . chr(168) => 'U', chr(197) . chr(169) => 'u',
			chr(197) . chr(170) => 'U', chr(197) . chr(171) => 'u',
			chr(197) . chr(172) => 'U', chr(197) . chr(173) => 'u',
			chr(197) . chr(174) => 'U', chr(197) . chr(175) => 'u',
			chr(197) . chr(176) => 'U', chr(197) . chr(177) => 'u',
			chr(197) . chr(178) => 'U', chr(197) . chr(179) => 'u',
			chr(197) . chr(180) => 'W', chr(197) . chr(181) => 'w',
			chr(197) . chr(182) => 'Y', chr(197) . chr(183) => 'y',
			chr(197) . chr(184) => 'Y', chr(197) . chr(185) => 'Z',
			chr(197) . chr(186) => 'z', chr(197) . chr(187) => 'Z',
			chr(197) . chr(188) => 'z', chr(197) . chr(189) => 'Z',
			chr(197) . chr(190) => 'z', chr(197) . chr(191) => 's'
		);

		$string = strtr($string, $chars);

		return $string;
	}
}
