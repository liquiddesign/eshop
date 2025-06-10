<?php

namespace Eshop\Actions\Offer\Code;

use Base\BaseAction;
use Carbon\Carbon;
use Eshop\DB\OfferRepository;

class GenerateOfferCode extends BaseAction
{
	public function __construct(private readonly GetOfferCodeFormat $getOfferCodeFormat, private readonly OfferRepository $offerRepository)
	{
	}

	public function execute(): string
	{
		$year = Carbon::now()->format('Y');

		return \vsprintf(
			$this->getOfferCodeFormat->execute(),
			[$this->offerRepository->many()->where('YEAR(this.createdTs)', $year)->enum()],
		);
	}
}
