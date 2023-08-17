<?php

namespace Eshop\DTO;

use DVDoug\BoxPacker\Box;

class EmptyBox implements Box
{
	public function getReference(): string
	{
		return 'DEFAULT';
	}

	public function getOuterWidth(): int
	{
		return 0;
	}

	public function getOuterLength(): int
	{
		return 0;
	}

	public function getOuterDepth(): int
	{
		return 0;
	}

	public function getEmptyWeight(): int
	{
		return 0;
	}

	public function getInnerWidth(): int
	{
		return 0;
	}

	public function getInnerLength(): int
	{
		return 0;
	}

	public function getInnerDepth(): int
	{
		return 0;
	}

	public function getMaxWeight(): int
	{
		return 0;
	}
}
