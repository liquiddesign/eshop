<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\BaseAction;
use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;
use Eshop\Services\Offer\OfferService;
use Messages\DB\TemplateRepository;
use Nette\Application\LinkGenerator;
use StORM\DIConnection;
use Tracy\Debugger;

class SendOffer extends BaseAction
{
	public function __construct(
		private readonly GetOfferState $getOfferState,
		private readonly DIConnection $storm,
		private readonly TemplateRepository $templateRepository,
		private readonly LinkGenerator $linkGenerator,
		private readonly OfferService $offerService,
	) {
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function execute(Offer $offer): void
	{
		$this->canSendOffer($offer);

		$this->storm->getLink()->beginTransaction();

		try {
			$offer->update([
				'sentTs' => Carbon::now()->toDateTimeString(),
				'canceledTs' => null,
			]);

			$this->templateRepository->sendMessage(
				'offers.create',
				[
					'publicUrl' => $this->linkGenerator->link('//:Eshop:Offer:offerPublic', [
						$offer->code,
						$offer->getPK(),
					]),
				] + $this->offerService->getEmailVariables($offer),
				$offer->order->purchase->accountEmail
			);

			$this->storm->getLink()->commit();
		} catch (\Exception $exception) {
			Debugger::barDump($exception);
			$this->storm->getLink()->rollBack();

			throw $exception;
		}

		$this->onOfferSent($offer);
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function canSendOffer(Offer $offer): void
	{
		$state = $this->getOfferState->execute($offer);

		if ($state === OfferState::Created) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}

	protected function onOfferSent(Offer $offer): void
	{
		unset($offer);
	}
}
