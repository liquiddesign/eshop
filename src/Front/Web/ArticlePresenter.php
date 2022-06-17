<?php

declare(strict_types=1);

namespace Eshop\Front\Web;

use Grid\Datalist;
use StORM\Exception\NotFoundException;
use Web\DB\NewsRepository;
use Web\DB\TagRepository;

abstract class ArticlePresenter extends \Eshop\Front\FrontendPresenter
{
	/** @inject */
	public NewsRepository $newsRepository;
	
	/** @inject */
	public TagRepository $newsTagRepository;
	
	public function actionDefault(?string $tag = null, ?string $page = null): void
	{
		unset($page);

		try {
			$collection = $this->newsRepository->getCollection();
			
			if ($tag) {
				$collection->where('tags.uuid', $tag);
			}

			$datalist = new Datalist($collection, 20);
			$datalist->setAutoCanonicalize(true);

			$this['news'] = $datalist;
		} catch (NotFoundException $x) {
			throw new \Nette\Application\BadRequestException();
		}
	}
	
	public function renderDefault(?string $tag = null): void
	{
		try {
			/** @var \Web\DB\Tag|null $tag */
			$tag = $tag ? $this->newsTagRepository->one($tag, true) : null;

			/** @var \Web\Controls\Breadcrumb $breadcrumb */
			$breadcrumb = $this['breadcrumb'];

			/** @var \Grid\Datagrid $datalist */
			$datalist = $this['news'];

			$breadcrumb->addItem($this->translator->translate('news.news', 'Články'), $this->link(':Web:Article:default'));
			
			if ($tag) {
				$breadcrumb->addItem($tag->name);
			}
			
			$this->template->news = $datalist->getItemsOnPage();
			$this->template->newsTags = $this->newsTagRepository->getCollection();
			$this->template->paginator = $datalist->getPaginator();
			$this->template->activeTag = $tag ? $tag->getPK() : null;
			$this->template->perex = $tag ? $tag->perex : null;
			$this->template->content = $tag ? $tag->content : null;
		} catch (NotFoundException $x) {
			throw new \Nette\Application\BadRequestException();
		}
	}
	
	public function actionDetail(string $article): void
	{
		unset($article);
	}
	
	public function renderDetail(string $article): void
	{
		try {
			/** @var \Web\DB\News $article */
			$article = $this->newsRepository->one($article, true);

			/** @var \Web\Controls\Breadcrumb $breadcrumb */
			$breadcrumb = $this['breadcrumb'];

			$breadcrumb->addItem($this->translator->translate('news.news', 'Novinky'), $this->link(':Web:Article:default'));
			$breadcrumb->addItem($article->name);
			
			$this->template->article = $article;
		} catch (NotFoundException $x) {
			throw new \Nette\Application\BadRequestException();
		}
	}
}
