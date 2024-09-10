<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\DB\Category;

interface ICategoryFormFactory
{
	/**
	 * @param bool $showDefaultViewType
	 * @param \Eshop\DB\Category|null $category
	 * @param bool $showDescendantProducts
	 * @param array<mixed> $configuration
	 */
	public function create(bool $showDefaultViewType, ?Category $category = null, bool $showDescendantProducts = false, array $configuration = []): CategoryForm;
}
