<?php

namespace Eshop\Services\Offer;

use Base\Bridges\AutoWireService;
use Eshop\Actions\Offer\GetOfferEmailVariables;
use Eshop\DB\Offer;
use Nette\Application\LinkGenerator;

readonly class OfferService implements AutoWireService
{
	public function __construct(
		private GetOfferEmailVariables $getOfferEmailVariables,
		private LinkGenerator $linkGenerator,
	) {
	}

	/**
	 * @param \Eshop\DB\Offer $offer
	 * @return array<string, mixed>
	 */
	public function getEmailVariables(Offer $offer): array
	{
		return [
			'publicUrl' => $this->linkGenerator->link('//:Eshop:Offer:offerPublic', [
				$offer->code,
				$offer->getPK(),
			]),
			'publicPrintUrl' => $this->linkGenerator->link('//:Eshop:Offer:offerPublic', [
				$offer->code,
				$offer->getPK(),
				'print' => 1,
			]),
			'offerCode' => $offer->code,
			'offer' => $offer,
		] + $this->getOfferEmailVariables->execute($offer);
	}
}
