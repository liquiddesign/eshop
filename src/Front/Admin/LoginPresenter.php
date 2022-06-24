<?php

declare(strict_types=1);

namespace Eshop\Front\Admin;

use Admin\Administrator;
use Admin\Controls\ILoginFormFactory;
use Admin\Controls\LoginForm;

abstract class LoginPresenter extends \Eshop\Front\FrontendPresenter
{
	public Administrator $admin;
	
	/**
	 * @inject
	 */
	public ILoginFormFactory $loginFormFactory;
	
	/**
	 * @persistent
	 */
	public string $backlink = '';
	
	public function actionDefault(): void
	{
		if ($this->admin->isLoggedIn()) {
			$this->redirect($this->admin->getDefaultLink());
		}
		
		return;
	}
	
	public function createComponentLoginForm(): LoginForm
	{
		$form = $this->loginFormFactory->create();

		$form->onLogin[] = function (LoginForm $form): void {
			$this->restoreRequest($this->backlink);

			if ($this->admin->isAllowed($this->admin->getDefaultLink())) {
				if ($presenter = $form->getPresenter()) {
					$presenter->redirect($this->admin->getDefaultLink());
				} else {
					$this->error();
				}
			}

			$this->flashMessage('Nedostatečná oprávnění', 'error');
		};

		$form->onLoginFail[] = function (): void {
			$this->flashMessage('Špatný login nebo heslo', 'error');
		};
		
		return $form;
	}
}
