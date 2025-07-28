<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\BaseAction;
use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;
use Messages\DB\TemplateRepository;
use Nette\Application\LinkGenerator;
use Nette\Mail\Mailer;
use StORM\DIConnection;
use Tracy\Debugger;

class SendOffer extends BaseAction
{
	public function __construct(
		private readonly GetOfferState $getOfferState,
		private readonly DIConnection $storm,
		private readonly TemplateRepository $templateRepository,
		private readonly Mailer $mailer,
		private readonly LinkGenerator $linkGenerator,
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

			$message = $this->templateRepository->createMessage(
				'offers.create',
				[
					'publicUrl' => $this->linkGenerator->link('//:Eshop:Offer:offerPublic', [
						$offer->code,
						$offer->getPK(),
					]),
				],
				$offer->order->purchase->accountEmail
			);

			$this->mailer->send($message);
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
