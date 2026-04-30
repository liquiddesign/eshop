<?php

declare(strict_types=1);

namespace Eshop\DB;

enum OfferType: string
{
	case Normal = 'normal';
	case Ckp = 'ckp';
}
