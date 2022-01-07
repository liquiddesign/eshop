<?php
declare(strict_types=1);

namespace Eshop;

use Latte\Engine;
use Latte\Loaders\StringLoader;
use Latte\Policy;
use Latte\Sandbox\SecurityPolicy;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Bridges\ApplicationLatte\UIMacros;

class FrontendPresenter extends Presenter
{
	/** @inject */
	public LatteFactory $latteFactory;

	protected Engine $latte;

	public function compileLatte(string $string, array $params): string
	{
		return $this->latte->renderToString($string, $params);
	}

	protected function startup(): void
	{
		parent::startup();

		$this->latte = $this->createLatteEngine();
	}

	protected function createLatteEngine(): Engine
	{
		$latte = $this->latteFactory->create();
		UIMacros::install($latte->getCompiler());
		$latte->setLoader(new StringLoader());
		$latte->setPolicy($this->getLatteSecurityPolicy());
		$latte->setSandboxMode();

		return $latte;
	}

	protected function getLatteSecurityPolicy(): Policy
	{
		$policy = SecurityPolicy::createSafePolicy();
		$policy->allowFilters(['price', 'date']);

		return $policy;
	}
}
