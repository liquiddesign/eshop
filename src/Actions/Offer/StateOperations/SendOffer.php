<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\BaseAction;
use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Offer;
use Eshop\DB\OfferLogItem;
use Eshop\DB\OfferLogItemRepository;
use Eshop\DB\OfferState;
use Eshop\Services\Offer\OfferService;
use Eshop\Services\Offer\OfferTypeStrategyResolver;
use Messages\DB\TemplateRepository;
use Nette\Utils\Arrays;
use StORM\DIConnection;
use Tracy\Debugger;
use Tracy\ILogger;

class SendOffer extends BaseAction
{
	public function __construct(
		private readonly GetOfferState $getOfferState,
		private readonly DIConnection $storm,
		private readonly TemplateRepository $templateRepository,
		private readonly OfferService $offerService,
		private readonly OfferLogItemRepository $offerLogItemRepository,
		private readonly OfferTypeStrategyResolver $strategyResolver,
	) {
	}

	/**
	 * @throws \Eshop\Actions\Offer\StateOperations\UnauthorizedStateChangeException
	 */
	public function execute(Offer $offer, bool $sendEmail = true): void
	{
		$this->canSendOffer($offer);

		$this->storm->getLink()->beginTransaction();

		try {
			$offer->update([
				'sentTs' => Carbon::now()->toDateTimeString(),
				'canceledTs' => null,
			]);

			if ($sendEmail) {
				$recipientEmail = $offer->accountEmail ?? $offer->email;

				if ($recipientEmail !== null) {
					$this->templateRepository->sendMessage(
						'offers.create',
						$this->offerService->getEmailVariables($offer),
						$recipientEmail
					);
				} else {
					Debugger::log(
						'Cannot send offer email: no recipient email for offer ' . $offer->code,
						ILogger::WARNING,
					);
				}
			}

			$this->offerLogItemRepository->createLog(
				$offer,
				OfferLogItem::SENT,
				$sendEmail ? null : 'Odesláno bez emailu',
				$offer->merchant
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
		$strategy = $this->strategyResolver->resolve($offer);
		$allowedTransitions = $strategy->getAllowedTransitions($state);

		if (Arrays::contains($allowedTransitions, OfferState::Sent)) {
			return;
		}

		throw new UnauthorizedStateChangeException();
	}

	protected function onOfferSent(Offer $offer): void
	{
		unset($offer);
	}
}
