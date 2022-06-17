<?php

declare(strict_types=1);

namespace Eshop\Front\Web;

use Nette;

abstract class Error4xxPresenter extends \Eshop\Front\FrontendPresenter
{
	public function startup(): void
	{
		$this->invalidLinkMode = Nette\Application\UI\Presenter::INVALID_LINK_SILENT;
		
		parent::startup();

		if ($this->getRequest()->isMethod(Nette\Application\Request::FORWARD)) {
			return;
		}

		$this->error();
	}

	public function renderDefault(Nette\Application\BadRequestException $exception): void
	{
		// load template 403.latte or 404.latte or ... 4xx.latte
		$file = __DIR__ . "/templates/Error/{$exception->getCode()}.latte";
		$this->template->setFile(\is_file($file) ? $file : __DIR__ . '/templates/Error/4xx.latte');
	}
}
