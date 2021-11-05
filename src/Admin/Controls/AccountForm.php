<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

class AccountForm extends \Admin\Admin\Controls\AccountFormFactory
{
	protected const CONFIGURATIONS = [
		'preferredMutation' => false,
		'newsletter' => true,
	];
}
