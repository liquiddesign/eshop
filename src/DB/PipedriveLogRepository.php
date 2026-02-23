<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Utils\Json;
use StORM\Repository;

/**
 * @extends \StORM\Repository<\Eshop\DB\PipedriveLog>
 */
class PipedriveLogRepository extends Repository
{
	/**
	 * @param array<array<string, mixed>> $messages
	 * @param array<string, mixed> $requestPayload
	 */
	public function createLog(
		bool $success,
		string|null $resultMessage = null,
		string|null $entityType = null,
		string|null $action = null,
		string|null $pipedriveId = null,
		string|null $source = null,
		array $messages = [],
		array $requestPayload = [],
	): PipedriveLog {
		/** @var \Eshop\DB\PipedriveLog $log */
		$log = $this->createOne([
			'entityType' => $entityType,
			'action' => $action,
			'pipedriveId' => $pipedriveId,
			'success' => $success,
			'resultMessage' => $resultMessage,
			'source' => $source,
			'messages' => $messages !== [] ? Json::encode($messages, \JSON_UNESCAPED_UNICODE) : null,
			'requestPayload' => $requestPayload !== [] ? Json::encode($requestPayload, \JSON_UNESCAPED_UNICODE) : null,
		]);

		return $log;
	}
}
