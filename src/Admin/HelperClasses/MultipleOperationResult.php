<?php

namespace Eshop\Admin\HelperClasses;

/**
 * @template T
 */
class MultipleOperationResult
{
	/**
	 * @var array<T>
	 */
	private array $completed = [];

	/**
	 * @var array<T>
	 */
	private array $failed = [];

	/**
	 * @var array<T>
	 */
	private array $ignored = [];

	/**
	 * @return array<T>
	 */
	public function getCompleted(): array
	{
		return $this->completed;
	}

	/**
	 * @return array<T>
	 */
	public function getFailed(): array
	{
		return $this->failed;
	}

	/**
	 * @return array<T>
	 */
	public function getIgnored(): array
	{
		return $this->ignored;
	}

	/**
	 * @param T $item
	 */
	public function addCompleted($item): void
	{
		$this->completed[] = $item;
	}

	/**
	 * @param T $item
	 */
	public function addFailed($item): void
	{
		$this->failed[] = $item;
	}

	/**
	 * @param T $item
	 */
	public function addIgnored($item): void
	{
		$this->ignored[] = $item;
	}

	/**
	 * @return int<0, max>
	 */
	public function getCompletedCount(): int
	{
		return \count($this->getCompleted());
	}

	/**
	 * @return int<0, max>
	 */
	public function getFailedCount(): int
	{
		return \count($this->getFailed());
	}

	/**
	 * @return int<0, max>
	 */
	public function getIgnoredCount(): int
	{
		return \count($this->getIgnored());
	}
}
