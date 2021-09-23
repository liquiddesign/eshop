<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

interface ICategoryFormFactory
{
	/**
	 * @param \Eshop\DB\Category|null $category
	 * @param mixed[] $configuration
	 * @return \Eshop\Admin\Controls\CategoryForm
	 */
	public function create(?\Eshop\DB\Category $category = null, array $configuration = []): CategoryForm;
}