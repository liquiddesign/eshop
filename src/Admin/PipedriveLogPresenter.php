<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminGrid;
use Eshop\DB\PipedriveLog;
use Eshop\DB\PipedriveLogRepository;
use Nette\Utils\Json;
use StORM\ICollection;

class PipedriveLogPresenter extends BackendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public PipedriveLogRepository $pipedriveLogRepository;

	public function createComponentGrid(): AdminGrid
	{
		$source = $this->pipedriveLogRepository->many()->orderBy(['this.createdTs' => 'DESC']);

		$grid = $this->gridFactory->create($source, 20, 'createdTs', 'DESC', true);

		$grid->addColumnText('Čas', 'createdTs', '%s', 'createdTs', ['class' => 'fit']);

		$grid->addColumn('Status', function (PipedriveLog $log): string {
			if ($log->success) {
				return '<span class="badge badge-success">OK</span>';
			}

			return '<span class="badge badge-danger">FAIL</span>';
		}, '%s', null, ['class' => 'fit']);

		$grid->addColumnText('Entita', 'entityType', '%s', 'entityType', ['class' => 'fit']);
		$grid->addColumnText('Akce', 'action', '%s', 'action', ['class' => 'fit']);
		$grid->addColumnText('Pipedrive ID', 'pipedriveId', '%s', 'pipedriveId', ['class' => 'fit']);
		$grid->addColumnText('Zdroj', 'source', '%s', 'source', ['class' => 'fit']);

		$grid->addColumn('Zpráva', function (PipedriveLog $log): string {
			$message = $log->resultMessage ?? '';

			if (\mb_strlen($message) > 80) {
				return \htmlspecialchars(\mb_substr($message, 0, 80)) . '...';
			}

			return \htmlspecialchars($message);
		});

		$grid->addColumnLinkDetail('detail');

		$grid->addFilterTextInput('search', ['this.pipedriveId', 'this.resultMessage'], null, 'Pipedrive ID, zpráva');

		$grid->addFilterSelectInput('success', 'this.success = :q', 'Status', '- Status -', null, [
			'1' => 'OK',
			'0' => 'FAIL',
		]);

		$grid->addFilterSelectInput('entityType', 'this.entityType = :q', 'Entita', '- Entita -', null, [
			'organization' => 'organization',
			'person' => 'person',
		]);

		$grid->addFilterSelectInput('source', 'this.source = :q', 'Zdroj', '- Zdroj -', null, [
			'webhook' => 'webhook',
			'minimal_organization' => 'minimal_organization',
			'minimal_person' => 'minimal_person',
		]);

		$grid->addFilterDate(function (ICollection $source, string $value): void {
			$source->where('DATE(this.createdTs) >= DATE(:date_from)', ['date_from' => $value]);
		}, '', 'date_from')->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Datum od');

		$grid->addFilterDate(function (ICollection $source, string $value): void {
			$source->where('DATE(this.createdTs) <= DATE(:date_to)', ['date_to' => $value]);
		}, '', 'date_to')->setHtmlAttribute('class', 'form-control form-control-sm flatpicker')->setHtmlAttribute('placeholder', 'Datum do');

		$grid->addFilterButtons();

		return $grid;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Pipedrive Webhook Log';
		$this->template->headerTree = [['Pipedrive Log']];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function actionDetail(PipedriveLog $pipedriveLog): void
	{
		$this->template->pipedriveLog = $pipedriveLog;

		/** @var array<array<string, mixed>> $decodedMessages */
		$decodedMessages = [];

		if ($pipedriveLog->messages !== null && $pipedriveLog->messages !== '') {
			try {
				/** @var array<array<string, mixed>> $decoded */
				$decoded = Json::decode($pipedriveLog->messages, forceArrays: true);
				$decodedMessages = $decoded;
			} catch (\Throwable) {
				// Invalid JSON — show raw string
			}
		}

		$this->template->decodedMessages = $decodedMessages;

		/** @var array<string, mixed> $decodedPayload */
		$decodedPayload = [];

		if ($pipedriveLog->requestPayload !== null && $pipedriveLog->requestPayload !== '') {
			try {
				/** @var array<string, mixed> $decoded */
				$decoded = Json::decode($pipedriveLog->requestPayload, forceArrays: true);
				$decodedPayload = $decoded;
			} catch (\Throwable) {
				// Invalid JSON
			}
		}

		$this->template->decodedPayload = $decodedPayload;
	}

	public function renderDetail(PipedriveLog $pipedriveLog): void
	{
		unset($pipedriveLog);

		$this->template->headerLabel = 'Detail webhook záznamu';
		$this->template->headerTree = [
			['Pipedrive Log', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [];
		$this->template->setFile(__DIR__ . '/templates/PipedriveLog.detail.latte');
	}
}
