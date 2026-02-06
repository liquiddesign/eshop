<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Carbon\Carbon;
use Nette\Application\BadRequestException;
use Nette\Application\UI\Form;
use Tracy\Debugger;

class PipedriveLogPresenter extends BackendPresenter
{
	/** @persistent */
	public ?string $filterLevel = null;

	/** @persistent */
	public ?string $filterDateFrom = null;

	/** @persistent */
	public ?string $filterDateTo = null;

	private const LOG_FILE = 'pipedrive-webhook.log';

	public function renderDefault(): void
	{
		$logPath = $this->tempDir . '/log/' . self::LOG_FILE;

		$this->template->headerLabel = 'Pipedrive Webhook Log';
		$this->template->headerTree = [['Pipedrive Log']];
		$this->template->displayButtons = [];

		$this->template->logExists = \is_file($logPath);
		$this->template->logEntries = [];
		$this->template->totalEntries = 0;

		if ($this->template->logExists) {
			$content = \file_get_contents($logPath);
			$allEntries = $this->parseLogContent($content);
			$this->template->totalEntries = \count($allEntries);
			$this->template->logEntries = $this->filterEntries($allEntries);
			$this->template->logSize = \filesize($logPath);
			$this->template->logModified = \filemtime($logPath);
		}

		$this->template->filterLevel = $this->filterLevel;
		$this->template->filterDateFrom = $this->filterDateFrom;
		$this->template->filterDateTo = $this->filterDateTo;

		$this->template->setFile(__DIR__ . '/templates/PipedriveLog.default.latte');
	}

	protected function createComponentFilterForm(): Form
	{
		$form = new Form();

		$form->addSelect('level', 'Úroveň:', [
			'' => 'Vše',
			'error' => 'Error',
			'warning' => 'Warning',
			'success' => 'Success',
			'info' => 'Info',
		])->setDefaultValue($this->filterLevel ?? '');

		$form->addText('dateFrom', 'Od:')
			->setHtmlAttribute('type', 'datetime-local')
			->setDefaultValue($this->filterDateFrom);

		$form->addText('dateTo', 'Do:')
			->setHtmlAttribute('type', 'datetime-local')
			->setDefaultValue($this->filterDateTo);

		$form->addSubmit('filter', 'Filtrovat');
		$form->addSubmit('reset', 'Zrušit filtr');

		$form->onSuccess[] = function (Form $form, array $values): void {
			/** @var \Nette\Forms\Controls\SubmitButton $resetButton */
			$resetButton = $form['reset'];

			if ($resetButton->isSubmittedBy()) {
				$this->filterLevel = null;
				$this->filterDateFrom = null;
				$this->filterDateTo = null;
			} else {
				$this->filterLevel = $values['level'] ?: null;
				$this->filterDateFrom = $values['dateFrom'] ?: null;
				$this->filterDateTo = $values['dateTo'] ?: null;
			}

			$this->redirect('this');
		};

		return $form;
	}

	/**
	 * @param array<array{timestamp: string|null, level: string, message: string}> $entries
	 * @return array<array{timestamp: string|null, level: string, message: string}>
	 */
	private function filterEntries(array $entries): array
	{
		Debugger::barDump($this->filterDateTo);
		Debugger::barDump($this->filterDateFrom);
		Debugger::barDump($entries);

		return \array_filter($entries, function (array $entry): bool {
			// Filter by level
			if ($this->filterLevel !== null && $entry['level'] !== $this->filterLevel) {
				return false;
			}

			// Filter by date range
			if ($this->filterDateFrom !== null || $this->filterDateTo !== null) {
				if ($entry['timestamp'] === null) {
					return false;
				}

				$entryTime = Carbon::createFromFormat('Y-m-d H-i-s', $entry['timestamp']);

				if ($this->filterDateFrom !== null) {
					$fromTime = Carbon::parse($this->filterDateFrom);

					if ($entryTime->lt($fromTime)) {
						return false;
					}
				}

				if ($this->filterDateTo !== null) {
					$toTime = Carbon::parse($this->filterDateTo);

					if ($entryTime->gt($toTime)) {
						return false;
					}
				}
			}

			return true;
		});
	}

	/**
	 * @return array<array{timestamp: string|null, level: string, message: string}>
	 */
	private function parseLogContent(string $content): array
	{
		$entries = [];
		$lines = \explode("\n", $content);

		foreach ($lines as $line) {
			$line = \Nette\Utils\Strings::trim($line);

			if ($line === '') {
				continue;
			}

			$entry = ['timestamp' => null, 'level' => 'info', 'message' => $line];

			// Parse various timestamp formats
			// Format: [YYYY-MM-DD HH:MM:SS] or YYYY-MM-DD HH:MM:SS or YYYY-MM-DDTHH:MM:SS
			if (\preg_match('/^\[?(\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2})\]?\s*(.*)$/', $line, $m)) {
				$entry['timestamp'] = $m[1];
				$entry['message'] = $m[2];
			// Format: DD.MM.YYYY HH:MM:SS (European)
			} elseif (\preg_match('/^\[?(\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}:\d{2})\]?\s*(.*)$/', $line, $m)) {
				$entry['timestamp'] = $m[1];
				$entry['message'] = $m[2];
			// Format: timestamp anywhere in brackets at start: [anything with date-like pattern]
			} elseif (\preg_match('/^\[([^\]]+)\]\s*(.*)$/', $line, $m)) {
				$entry['timestamp'] = $m[1];
				$entry['message'] = $m[2];
			}

			// Detect log level
			$lower = \Nette\Utils\Strings::lower($entry['message']);

			if (\str_contains($lower, 'error') || \str_contains($lower, 'exception')) {
				$entry['level'] = 'error';
			} elseif (\str_contains($lower, 'warning')) {
				$entry['level'] = 'warning';
			} elseif (\str_contains($lower, 'success')) {
				$entry['level'] = 'success';
			}

			$entries[] = $entry;
		}

		// Newest first
		return \array_reverse($entries);
	}
}
