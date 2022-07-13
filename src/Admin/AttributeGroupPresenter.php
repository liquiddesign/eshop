<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Eshop\DB\AttributeGroupRepository;

class AttributeGroupPresenter extends BackendPresenter
{
	/** @inject */
	public AttributeGroupRepository $attributeGroupRepository;
}
