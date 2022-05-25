<?php

namespace Eshop\Integration;

use Nette\DI\Container;
use Nette\DI\MissingServiceException;

final class Integrations
{
	public const SERVICES = [
		'dpd' => 'integrations.dpd',
		'ppl' => 'integrations.ppl',
	];

	protected Container $container;

	public function __construct(Container $container)
	{
		$this->container = $container;
	}

	public function getService(string $name): ?object
	{
		if (!isset($this::SERVICES[$name])) {
			return null;
		}

		try {
			return $this->container->getByName($this::SERVICES[$name]);
		} catch (MissingServiceException $e) {
			return null;
		}
	}
}
