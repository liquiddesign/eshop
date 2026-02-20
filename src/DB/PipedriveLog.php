<?php

declare(strict_types=1);

namespace Eshop\DB;

use StORM\Entity;

/**
 * @table
 */
class PipedriveLog extends Entity
{
	/**
	 * Typ entity (organization, person)
	 * @column
	 */
	public string|null $entityType = null;

	/**
	 * Akce (create, update, delete)
	 * @column
	 */
	public string|null $action = null;

	/**
	 * Pipedrive entity ID
	 * @column
	 */
	public string|null $pipedriveId = null;

	/**
	 * Zda zpracovani probehlo uspesne
	 * @column
	 */
	public bool $success = false;

	/**
	 * Lidsky citelna zprava o vysledku
	 * @column{"type":"text"}
	 */
	public string|null $resultMessage = null;

	/**
	 * Zdroj (webhook, minimal_organization, minimal_person)
	 * @column
	 */
	public string|null $source = null;

	/**
	 * JSON pole vsech log zprav
	 * @column{"type":"json"}
	 */
	public string|null $messages = null;

	/**
	 * Celý příchozí request payload
	 * @column{"type":"json"}
	 */
	public string|null $requestPayload = null;

	/**
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $createdTs;
}
