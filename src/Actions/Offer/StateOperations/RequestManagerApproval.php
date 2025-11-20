<?php

declare(strict_types=1);

namespace Eshop\Actions\Offer\StateOperations;

use Base\BaseAction;
use Carbon\Carbon;
use Eshop\Actions\Offer\GetOfferState;
use Eshop\DB\Merchant;
use Eshop\DB\MerchantRepository;
use Eshop\DB\Offer;
use Eshop\DB\OfferState;
use Eshop\Integration\FreeloNotificationService;
use Messages\DB\TemplateRepository;
use Nette\Application\LinkGenerator;
use Tracy\Debugger;
use Tracy\ILogger;

class RequestManagerApproval extends BaseAction
{
	public function __construct(
		private readonly GetOfferState $getOfferState,
		private readonly MerchantRepository $merchantRepository,
		private readonly TemplateRepository $templateRepository,
		private readonly FreeloNotificationService $freeloNotificationService,
		private readonly LinkGenerator $linkGenerator,
	) {
	}

	/**
	 * Request manager approval for offer
	 * @throws \Exception
	 */
	public function execute(Offer $offer): void
	{
		$currentState = $this->getOfferState->execute($offer);

		if ($currentState !== OfferState::Created) {
			throw new \Exception('Nabídku lze odeslat ke schválení pouze ze stavu "Vytvořena"');
		}

		// Set timestamp
		$offer->update([
			'managerApprovalRequestedTs' => Carbon::now()->toDateTimeString(),
		]);

		// Send email to all managers with approval permission
		$managerCount = $this->notifyManagers($offer);

		// Send notification to Freel for automatic ticket creation
		$this->notifyFreelo($offer, $managerCount);
	}

	/**
	 * Notify all managers with approval permission
	 * @return int Number of managers notified
	 */
	private function notifyManagers(Offer $offer): int
	{
		/** @var array<\Eshop\DB\Merchant> $managers */
		$managers = $this->merchantRepository->many()
			->where('this.approveOfferPermission', true)
			->toArray();

		if (\count($managers) === 0) {
			return 0;
		}

		$notifiedCount = 0;

		foreach ($managers as $manager) {
			if ($manager->email === '') {
				continue;
			}

			try {
				$this->templateRepository->sendMessage(
					'offers.manager_approval_requested',
					$this->getEmailVariables($offer, $manager),
					$manager->email
				);
				$notifiedCount++;
			} catch (\Throwable $e) {
				// Log error but don't fail the operation
				Debugger::log($e, ILogger::ERROR);
			}
		}

		return $notifiedCount;
	}

	/**
	 * Send notification to Freel for automatic ticket creation
	 */
	private function notifyFreelo(Offer $offer, int $managerCount): void
	{
		$author = $offer->order->purchase->merchant;

		if ($author === null) {
			return;
		}

		$this->freeloNotificationService->notifyApprovalRequest($offer, $author, $managerCount);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getEmailVariables(Offer $offer, Merchant $manager): array
	{
		$merchant = $offer->order->purchase->merchant;
		$customer = $offer->order->purchase->customer;

		return [
			'offerCode' => $offer->code,
			'managerName' => $manager->fullname,
			'merchantName' => $merchant->fullname ?? 'Neznámý obchodník',
			'customerName' => $customer->company ?? $customer->fullname ?? 'Neznámý zákazník',
			'offerLink' => $this->linkGenerator->link('Eshop:AssistantInterface:offer', ['code' => $offer->code]),
			'createdDate' => Carbon::parse($offer->createdTs)->format('d.m.Y H:i'),
		];
	}
}
