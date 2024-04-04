<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\DB\Category;

interface ICategoryFormFactory
{
	public function create(bool $showDefaultViewType, ?Category $category = null, bool $showDescendantProducts = false): CategoryForm;
}
