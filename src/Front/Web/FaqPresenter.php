<?php

declare(strict_types=1);

namespace Eshop\Front\Web;

use Messages\DB\TemplateRepository;
use Pages\Pages;
use Web\DB\FaqItemTag;
use Web\DB\FaqItemTagRepository;
use Web\DB\FaqRepository;

abstract class FaqPresenter extends \Eshop\FrontendPresenter
{
	/** @inject */
	public Pages $pages;

	/** @inject */
	public TemplateRepository $templateRepository;

	/** @inject */
	public FaqRepository $faqRepository;

	/** @inject */
	public FaqItemTagRepository $faqItemTagRepository;

	public function renderDefault(?string $tag = null): void
	{
		$faqItemTag = $tag ? $this->faqItemTagRepository->one($tag) : null;

		$this->breadcrumbDefault($faqItemTag);

		$this->template->faqs = $this->faqRepository->getCollection();
		$this->template->activeTag = $faqItemTag ?? null;
		$this->template->tags = $this->faqItemTagRepository->getActiveTags();
	}

	public function breadcrumbDefault(?FaqItemTag $tag = null): void
	{
		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		$breadcrumb->addItem($this->translator->translate('faq.faq', 'Online poradna'), $tag ? $this->link('default') : null);

		if (!$tag) {
			return;
		}

		$breadcrumb->addItem((string) $tag->name, null);
	}
}
