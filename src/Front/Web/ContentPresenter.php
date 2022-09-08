<?php

declare(strict_types=1);

namespace Eshop\Front\Web;

use Forms\Form;
use Messages\Control\ContactForm;
use Messages\DB\TemplateRepository;
use Nette;
use Nette\Application\BadRequestException;
use Pages\Pages;
use Web\DB\ContactItemRepository;
use Web\DB\MenuItemRepository;
use Web\DB\SettingRepository;

abstract class ContentPresenter extends \Eshop\Front\FrontendPresenter
{
	/** @inject */
	public Pages $pages;

	/** @inject */
	public SettingRepository $settingRepository;

	/** @inject */
	public ContactItemRepository $contactItemRepository;

	/** @inject */
	public TemplateRepository $templateRepository;

	/** @inject */
	public MenuItemRepository $menuItemRepository;
	
	/** @inject */
	public Nette\Mail\Mailer $mailer;

	public function renderDefault(string $page): void
	{
		/** @var \Web\DB\Page|null $page */
		$page = $this->pages->getPage();

		if (!$page) {
			throw new BadRequestException();
		}

		$menuItem = $this->menuItemRepository->one(['fk_page' => $page->getPK()]);
		$parents = $this->menuItemRepository->getBreadcrumbStructure($menuItem);

		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		foreach ($parents as $item) {
			if ($item->name) {
				$breadcrumb->addItem($item->name, $item->getUrl());
			}
		}

		$breadcrumb->addItem($page->name ?? '');
	}

	public function createComponentContactForm(): ContactForm
	{
		$form = new ContactForm($this, 'contactForm', $this->templateRepository, $this->mailer);

		/** @var \Nette\Forms\Controls\TextInput $email */
		$email = $form['email'];
		$email->caption = 'E-mail';

		unset($form['message']);
		$form->addTextArea('message', $this->translator->translate('contactForm.msg', 'Zpráva'))
			->setHtmlAttribute('rows', 10)
			->setRequired();

		/** @var \Nette\Forms\Controls\SubmitButton $submit */
		$submit = $form['submit'];

		$submit->setCaption($this->translator->translate('contactForm.send', 'Odeslat'));

		$form->onSuccess[] = function (Form $form): void {
			$this->flashMessage($this->translator->translate('contactForm.success', 'Zpráva byla úspěšně odeslána'), 'info');
			$this->redirect('this');
		};

		return $form;
	}

	public function renderContact(): void
	{
		$this->template->settings = $this->settingRepository->getValues();
		$this->template->contactItems = $this->contactItemRepository->getCollection();
	}
}
