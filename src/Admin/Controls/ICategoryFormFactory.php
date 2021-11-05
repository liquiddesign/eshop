<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\DB\Category;

interface ICategoryFormFactory
{
	/**
	 * @param \Eshop\DB\Category|null $category
	 */
	public function create(?Category $category = null): CategoryForm;
}
