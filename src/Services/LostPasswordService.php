<?php

namespace Eshop\Services;

use Base\Services\ValidateEmailService;
use Base\ShopsConfig;
use Exception;
use Messages\DB\TemplateRepository;
use Nette\Application\LinkGenerator;
use Nette\Mail\Mailer;
use Ramsey\Uuid\Uuid;
use Security\DB\Account;
use Security\DB\AccountRepository;
use StORM\DIConnection;

class LostPasswordService implements \Base\Bridges\AutoWireService
{
	public function __construct(
		protected readonly DIConnection $connection,
		protected readonly TemplateRepository $templateRepository,
		/** @var \Security\DB\AccountRepository<\Security\DB\Account> $accountRepository */
		protected readonly AccountRepository $accountRepository,
		protected readonly LinkGenerator $linkGenerator,
		protected readonly Mailer $mailer,
		protected readonly ShopsConfig $shopsConfig,
		protected readonly ValidateEmailService $validateEmailService,
	) {
	}

	public function sendResetLink(Account $account): void
	{
		$login = $account->login;

		$token = Uuid::uuid7();

		$account->update(['confirmationToken' => $token->toString()]);

		if (!$this->validateEmailService->validate($login)) {
			throw new \Exception('Login is not valid e-mail.', 422);
		}

		$accountShop = $account->shop;

		$mail = $this->templateRepository->createMessage(
			'lostPassword',
			[
				'email' => $login,
				'link' => $this->linkGenerator->link('Eshop:User:setNewPassword', [$token->toString()]),
			],
			$login,
			checkShops: (bool) $accountShop,
			shops: $accountShop ? [$accountShop] : null,
		);

		if (!$mail) {
			throw new \Exception('Unable to create e-mail template.', 400);
		}

		$this->mailer->send($mail);
	}

	public function setNewPassword(string $token, string $newPassword): void
	{
		$account = $this->accountRepository->one(['token' => $token]);

		if (!$account) {
			throw new Exception("Token '$token' not found.", 404);
		}

		$account->changePassword($newPassword);
	}
}
