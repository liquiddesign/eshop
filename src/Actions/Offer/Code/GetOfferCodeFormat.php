<?php

namespace Eshop\Actions\Offer\Code;

use Base\BaseAction;

class GetOfferCodeFormat extends BaseAction
{
	public function execute(): string
	{
		return 'NB-%1$s%2$05d';
	}
}
