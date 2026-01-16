<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Repository;

/**
 * @extends \StORM\Repository<\Eshop\DB\CustomerBonita>
 */
class CustomerBonitaRepository extends Repository
{
	/**
	 * Get bonita record by CKP_IC identifier
	 */
	public function getByCkpIc(string $ckpIc): CustomerBonita|null
	{
		return $this->one(['ckpIc' => $ckpIc]);
	}

	/**
	 * Get bonita value for given CKP_IC
	 */
	public function getBonitaByCkpIc(string $ckpIc): string|null
	{
		return $this->getByCkpIc($ckpIc)?->bonita;
	}
}
