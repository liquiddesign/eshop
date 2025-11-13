<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\BaseAction;
use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Merchant;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;
use Messages\DB\TemplateRepository;
use Nette\Mail\Mailer;
use Tracy\Debugger;
use Tracy\ILogger;

class RejectByManager extends BaseAction
{
	public function __construct(
		private readonly GetOfferState $getOfferState,
		private readonly TemplateRepository $templateRepository,
		private readonly Mailer $mailer,
	) {
	}

	/**
	 * Reject offer as manager
	 * @throws \Exception
	 */
	public function execute(Offer $offer, Merchant $manager, string $rejectionNote): void
	{
		if (!$manager->approveOfferPermission) {
			throw new \Exception('Nemáte oprávnění zamítat nabídky');
		}

		$currentState = $this->getOfferState->execute($offer);

		if ($currentState !== OfferState::AwaitingManagerApproval) {
			throw new \Exception('Zamítnout lze pouze nabídku čekající na schválení');
		}

		// Set canceled timestamp
		$offer->update([
			'canceledTs' => Carbon::now()->toDateTimeString(),
		]);

		// Send notification to offer author with rejection note
		$this->notifyAuthor($offer, $manager, $rejectionNote);
	}

	/**
	 * Send email notification to offer author about rejection
	 */
	private function notifyAuthor(Offer $offer, Merchant $rejectingManager, string $rejectionNote): void
	{
		$author = $offer->order->purchase->merchant;

		if ($author === null || $author->email === '') {
			return;
		}

		try {
			$message = $this->templateRepository->createMessage(
				'offers.manager_rejected',
				$this->getEmailVariables($offer, $rejectingManager, $rejectionNote),
				$author->email
			);

			// Set reply-to as the rejecting manager so author can respond directly
			if ($message !== null && $rejectingManager->email !== '') {
				$message->addReplyTo($rejectingManager->email, $rejectingManager->fullname);
			}

			if ($message !== null) {
				$this->mailer->send($message);
			}
		} catch (\Throwable $e) {
			// Log error but don't fail the rejection operation
			Debugger::log(
				\json_encode([
					'type' => 'offer_rejection_notification_failed',
					'offerCode' => $offer->code,
					'authorEmail' => $author->email,
					'error' => $e->getMessage(),
				], \JSON_UNESCAPED_UNICODE),
				ILogger::ERROR
			);
		}
	}

	/**
	 * Prepare email template variables
	 * @return array<string, mixed>
	 */
	private function getEmailVariables(Offer $offer, Merchant $rejectingManager, string $rejectionNote): array
	{
		return [
			'offerCode' => $offer->code,
			'managerName' => $rejectingManager->fullname,
			'rejectionNote' => $rejectionNote,
			'rejectionDate' => Carbon::now()->format('d.m.Y H:i'),
			// TODO: Add proper link to offer in admin
			'offerLink' => '',
		];
	}
}
