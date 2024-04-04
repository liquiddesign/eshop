<?php

declare(strict_types=1);

namespace Eshop\DB;

/**
 * @extends \StORM\Repository<\Eshop\DB\BannedEmail>
 */
class BannedEmailRepository extends \StORM\Repository
{
	public function isEmailBanned(string $email): bool
	{
		return (bool) $this->one(['email' => $email]);
	}
}
