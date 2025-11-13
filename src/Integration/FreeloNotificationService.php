<?php

declare(strict_types=1);

namespace Eshop\Integration;

use Eshop\DB\Merchant;
use Eshop\DB\Offer;
use Nette\Mail\Mailer;
use Nette\Mail\Message;
use Tracy\Debugger;
use Tracy\ILogger;

/**
 * Service for sending notifications to Freel project management system
 * Freelo automatically creates tickets from emails sent to special inbox addresses
 */
readonly class FreeloNotificationService
{
	public function __construct(private string $freeloApprovalEmail, private Mailer $mailer,)
	{
	}

	/**
	 * Notify Freel about new offer requiring manager approval
	 * Creates automatic ticket in Freel via email
	 * @param \Eshop\DB\Offer $offer Offer requiring approval
	 * @param \Eshop\DB\Merchant $author Merchant who created the offer
	 * @param int $managerCount Number of managers notified
	 */
	public function notifyApprovalRequest(Offer $offer, Merchant $author, int $managerCount): void
	{
		if ($this->freeloApprovalEmail === '') {
			Debugger::log(
				\json_encode([
					'type' => 'freel_notification_skipped',
					'reason' => 'Freel email not configured',
					'offerCode' => $offer->code,
				], \JSON_UNESCAPED_UNICODE),
				ILogger::INFO
			);

			return;
		}

		$customer = $offer->order->purchase->customer;
		$offerValue = $offer->order->getTotalPriceVat();
		$offerLink = $this->generateOfferAdminLink($offer);

		$subject = \sprintf(
			'Nabídka ke schválení: %s - %s',
			$offer->code,
			$customer?->company ?? $customer?->fullname ?? 'Neznámý zákazník'
		);

		$body = $this->buildEmailBody($offer, $author, $customer, $offerValue, $offerLink, $managerCount);

		$message = new Message();
		$message->setFrom('noreply@rajtiskaren.cz', 'ABEL Systém')
			->addTo($this->freeloApprovalEmail)
			->setSubject($subject)
			->setBody($body);

		try {
			$this->mailer->send($message);

			Debugger::log(
				\json_encode([
					'type' => 'freel_notification_sent',
					'offerCode' => $offer->code,
					'author' => $author->fullname,
					'customer' => $customer?->company ?? $customer?->fullname,
					'freeloEmail' => $this->freeloApprovalEmail,
				], \JSON_UNESCAPED_UNICODE),
				ILogger::INFO
			);
		} catch (\Throwable $e) {
			Debugger::log(
				\json_encode([
					'type' => 'freel_notification_failed',
					'offerCode' => $offer->code,
					'error' => $e->getMessage(),
				], \JSON_UNESCAPED_UNICODE),
				ILogger::ERROR
			);
		}
	}

	/**
	 * Build plain text email body for Freel ticket
	 * @param \Eshop\DB\Offer $offer
	 * @param \Eshop\DB\Merchant $author
	 * @param \Eshop\DB\Customer|null $customer
	 * @param float $offerValue
	 * @param string $offerLink
	 * @param int $managerCount
	 */
	private function buildEmailBody(
		Offer $offer,
		Merchant $author,
		?\Eshop\DB\Customer $customer,
		float $offerValue,
		string $offerLink,
		int $managerCount
	): string {
		$lines = [];
		$lines[] = 'Nová nabídka vyžaduje schválení manažerem.';
		$lines[] = '';
		$lines[] = '=== INFORMACE O NABÍDCE ===';
		$lines[] = \sprintf('Kód nabídky: %s', $offer->code);
		$lines[] = \sprintf('Autor: %s (%s)', $author->fullname, $author->email);
		$lines[] = \sprintf('Zákazník: %s', $customer?->company ?? $customer?->fullname ?? 'Neznámý');
		$lines[] = \sprintf('Hodnota nabídky: %s Kč s DPH', \number_format($offerValue, 2, ',', ' '));
		$lines[] = \sprintf('Datum vytvoření: %s', $offer->createdTs);
		$lines[] = '';
		$lines[] = '=== AKCE ===';
		$lines[] = \sprintf('Notifikováno manažerů: %d', $managerCount);
		$lines[] = \sprintf('Odkaz na nabídku: %s', $offerLink);
		$lines[] = '';
		$lines[] = '---';
		$lines[] = 'Tato zpráva byla automaticky vygenerována systémem ABEL.';

		return \implode("\n", $lines);
	}

	/**
	 * Generate admin link to offer detail
	 * Note: This is a simplified version - adjust based on your routing
	 * @param \Eshop\DB\Offer $offer
	 */
	private function generateOfferAdminLink(Offer $offer): string
	{
		// TODO: Use LinkGenerator if needed for proper URL generation
		// For now, returning a simple path - adjust based on your admin routing
		return \sprintf('https://admin.rajtiskaren.cz/admin/eshop/offer/detail?offer=%s', $offer->getPK());
	}
}
