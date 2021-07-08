<?php

declare(strict_types=1);

namespace Eshop\Bridges;

use Eshop\Integration\Comgate;
use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

class ComgateDI extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'merchant' => Expect::string(),
			'test' => Expect::string(),
			'country' => Expect::string(),
			'curr' => Expect::string(),
			'method' => Expect::string(),
			'lang' => Expect::string(),
		]);
	}

	public function loadConfiguration(): void
	{
		$config = (array) $this->getConfig();

		/** @var \Nette\DI\ContainerBuilder $builder */
		$builder = $this->getContainerBuilder();

		$comgate = $builder->addDefinition($this->prefix('comgate'))->setType(Comgate::class);
		$comgate->addSetup('setConfig', [$config]);

		return;
	}
}
