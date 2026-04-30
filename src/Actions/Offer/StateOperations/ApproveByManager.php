<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\BaseAction;
use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Merchant;
use Eshop\DB\Offer;
use Eshop\DB\OfferLogItem;
use Eshop\DB\OfferLogItemRepository;
use Eshop\DB\OfferState;
use Messages\DB\TemplateRepository;
use Nette\Mail\Mailer;
use Tracy\Debugger;
use Tracy\ILogger;

class ApproveByManager extends BaseAction
{
	public function __construct(
		private readonly GetOfferState $getOfferState,
		private readonly TemplateRepository $templateRepository,
		private readonly Mailer $mailer,
		private readonly OfferLogItemRepository $offerLogItemRepository,
	) {
	}

	/**
	 * Approve offer as manager
	 * @throws \Exception
	 */
	public function execute(Offer $offer, Merchant $manager): void
	{
		if (!$manager->approveOfferPermission) {
			throw new \Exception('Nemáte oprávnění schvalovat nabídky');
		}

		$currentState = $this->getOfferState->execute($offer);

		if ($currentState !== OfferState::AwaitingManagerApproval) {
			throw new \Exception('Schválit lze pouze nabídku čekající na schválení');
		}

		// Set timestamp + backfill preceding timestamps
		$offer->update([
			'managerApprovedTs' => Carbon::now()->toDateTimeString(),
			'managerApprovalRequestedTs' => $offer->managerApprovalRequestedTs ?? Carbon::now()->toDateTimeString(),
		]);

		$this->offerLogItemRepository->createLog(
			$offer,
			OfferLogItem::MANAGER_APPROVED,
			null,
			$manager,
		);

		// Send notification to offer author
		$this->notifyAuthor($offer, $manager);
	}

	/**
	 * Prepare email template variables
	 * @return array<string, mixed>
	 */
	protected function getEmailVariables(Offer $offer, Merchant $approvingManager): array
	{
		return [
			'offerCode' => $offer->code,
			'managerName' => $approvingManager->fullname,
			'approvalDate' => Carbon::parse($offer->managerApprovedTs)->format('d.m.Y H:i'),
			'offerLink' => '',
		];
	}

	/**
	 * Send email notification to offer author about approval
	 */
	private function notifyAuthor(Offer $offer, Merchant $approvingManager): void
	{
		$author = $offer->merchant;

		if ($author === null || $author->email === '') {
			return;
		}

		try {
			$message = $this->templateRepository->createMessage(
				'offers.manager_approved',
				$this->getEmailVariables($offer, $approvingManager),
				$author->email
			);

			// Set reply-to as the approving manager so author can respond directly
			if ($message !== null && $approvingManager->email !== '') {
				$message->addReplyTo($approvingManager->email, $approvingManager->fullname);
			}

			if ($message !== null) {
				$this->mailer->send($message);
			}
		} catch (\Throwable $e) {
			// Log error but don't fail the approval operation
			Debugger::log(
				\json_encode([
					'type' => 'offer_approval_notification_failed',
					'offerCode' => $offer->code,
					'authorEmail' => $author->email,
					'error' => $e->getMessage(),
				], \JSON_UNESCAPED_UNICODE),
				ILogger::ERROR
			);
		}
	}
}
