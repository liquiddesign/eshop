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
		$format = $this->getOfferCodeFormat->execute();
		$counter = $this->offerRepository->many()->where('YEAR(this.createdTs)', $year)->enum() + 1;

		return \vsprintf(
			$format,
			[$year, $counter],
		);
	}
}
