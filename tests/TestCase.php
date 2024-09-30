<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	protected function tearDown(): void
	{
		parent::tearDown();

		restore_error_handler();
		restore_exception_handler();
	}
}
