<?php

declare(strict_types=1);

namespace Eshop\Front\Web;

use Messages\DB\TemplateRepository;
use Pages\Pages;
use Web\DB\FaqItemTag;
use Web\DB\FaqItemTagRepository;
use Web\DB\FaqRepository;
use Web\DB\MenuItemRepository;
use Web\DB\Page;
use Web\DB\PageRepository;

abstract class FaqPresenter extends \Eshop\Front\FrontendPresenter
{
	/** @inject */
	public Pages $pages;
	
	/** @inject */
	public TemplateRepository $templateRepository;
	
	/** @inject */
	public FaqRepository $faqRepository;
	
	/** @inject */
	public FaqItemTagRepository $faqItemTagRepository;
	
	/** @inject */
	public PageRepository $pageRepository;
	
	/** @inject */
	public MenuItemRepository $menuItemRepository;
	
	protected ?FaqItemTag $tag = null;
	
	protected ?Page $page = null;
	
	public function actionDefault(?string $tag = null): void
	{
		/** @var \Web\DB\Page|null $page */
		$page = $this->pages->getPage();
		
		$this->page = $page;
		$this->tag = $tag ? $this->faqItemTagRepository->one($tag) : null;
	}
	
	public function renderDefault(): void
	{
		$this->breadcrumbDefault();
		
		$this->template->faqs = $this->faqRepository->getFaqsWithItems(false, $this->tag);
		$this->template->activeTag = $this->tag ?: null;
		$this->template->tags = $this->faqItemTagRepository->getActiveTags();
		
		if ($this->template->activeTag) {
			/** @var \Web\DB\Page|null $rootPage */
			$rootPage = $this->pageRepository->getPageByTypeAndParams('faq', '');
			$this->template->content = $rootPage ? $rootPage->content : null;
		} else {
			$this->template->content = $this->page ? $this->page->content : null;
		}
	}
	
	public function breadcrumbDefault(): void
	{
		/** @var \Web\DB\Page|null $page */
		$page = $this->tag ? $this->pageRepository->getPageByTypeAndParams('faq', '') : $this->page;
		
		if (!$page) {
			return;
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
		
		$breadcrumb->addItem($page->name ?? '', $this->tag ? $this->link('//this', ['tag' => null,]) : null);
		
		if (!$this->tag) {
			return;
		}
		
		$breadcrumb->addItem((string) $this->tag->name, null);
	}
}
