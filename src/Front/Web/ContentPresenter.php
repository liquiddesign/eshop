<?php

declare(strict_types=1);

namespace Eshop\Front\Web;

use Forms\Form;
use Messages\Control\ContactForm;
use Messages\DB\TemplateRepository;
use Nette;
use Pages\Pages;
use Web\DB\ContactItemRepository;
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

	public function renderDefault(string $page): void
	{
		/** @var \Web\DB\Page|null $page */
		$page = $this->pages->getPage();

		$this->template->breadcrumb = $page ? [(object)[
			'name' => $page->name,
			'link' => null,
		]] : [];
	}

	public function createComponentContactForm(): ContactForm
	{
		$form = new ContactForm($this, 'contactForm', $this->templateRepository);

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
			$values = $form->getValues();
			$mailer = new Nette\Mail\SendmailMailer();

			$mail = $this->templateRepository->createMessage('contact', ['text' => $values['message']], null, null, $values['email']);

			if ($mail) {
				$mailer->send($mail);
			}

			$mail = $this->templateRepository->createMessage('contactInfo', ['text' => $values['message']], $values['email']);

			if ($mail) {
				$mailer->send($mail);
			}

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
