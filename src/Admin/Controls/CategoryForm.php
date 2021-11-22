<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\DB\Category;
use Eshop\DB\CategoryRepository;
use Eshop\DB\ProducerRepository;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Utils\Image;
use Pages\Helpers;
use StORM\DIConnection;
use Web\DB\PageRepository;

class CategoryForm extends Control
{
	private CategoryRepository $categoryRepository;
	
	private PageRepository $pageRepository;
	
	private AdminFormFactory $formFactory;
	
	private ProducerRepository $producerRepository;
	
	private ?Category $category;
	
	public function __construct(
		CategoryRepository $categoryRepository,
		AdminFormFactory $formFactory,
		PageRepository $pageRepository,
		ProducerRepository $producerRepository,
		?Category $category
	) {
		$this->category = $category;
		$this->categoryRepository = $categoryRepository;
		$this->pageRepository = $pageRepository;
		$this->formFactory = $formFactory;
		$this->producerRepository = $producerRepository;
		
		$form = $formFactory->create(true);
		
		$form->addText('code', 'Kód');
		
		$imagePicker = $form->addImagePicker('imageFileName', 'Obrázek', [
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);
		
		$this->monitor(Presenter::class, function ($presenter) use ($imagePicker, $category): void {
			$imagePicker->onDelete[] = function (array $directories, $filename) use ($category, $presenter): void {
				$presenter->onDeleteImage($category);
				$presenter->redirect('this');
			};
		});
		
		$imagePicker = $form->addImagePicker('productFallbackImageFileName', 'Placeholder produktů', [
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);
		
		$this->monitor(Presenter::class, function ($presenter) use ($imagePicker, $category): void {
			$imagePicker->onDelete[] = function (array $directories, $filename) use ($category, $presenter): void {
				$presenter->onDeleteImage($category, 'productFallbackImageFileName');
				$presenter->redirect('this');
			};
		});
		
		$nameInput = $form->addLocaleText('name', 'Název');
		$form->addLocalePerexEdit('perex', 'Perex');
		$form->addLocaleRichEdit('content', 'Obsah');
		
		$this->monitor(Presenter::class, function ($presenter) use ($form, $category): void {
			$categories = $this->categoryRepository->getTreeArrayForSelect(true, $presenter->tab);
			
			if ($category) {
				unset($categories[$category->getPK()]);
			}
			
			$form->addDataSelect('ancestor', 'Nadřazená kategorie', $categories)->setPrompt('Žádná');
		});
		
		$form->addText('exportGoogleCategory', 'Exportní název pro Google');
		$form->addText('exportHeurekaCategory', 'Export název pro Heuréku');
		$form->addText('exportZboziCategory', 'Export název pro Zbozi');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10)->setRequired();
		$form->addCheckbox('hidden', 'Skryto');
		$form->addCheckbox('showInMenu', 'Zobrazit v menu');
		$form->addCheckbox('recommended', 'Doporučeno');
		
		$form->addPageContainer('product_list', ['category' => $category], $nameInput);
		
		$pagesCategoryAll = [];
		
		if ($category) {
			$pages = $this->pageRepository->many()->where('type', 'product_list');

			while ($page = $pages->fetch()) {
				/** @var \Web\DB\Page $page */
				$params = $page->getParsedParameters();
				
				if (!isset($params['category']) || !isset($params['producer']) || $params['category'] !== $category->getPK()) {
					continue;
				}
				
				$pagesCategoryAll[$page->getPK()] = [$page, $this->producerRepository->one($params['producer'])];
			}
			
			if (\count($pagesCategoryAll) > 0) {
				$mainContainer = $form->addContainer('categoryProducerPages');
				
				foreach ($pagesCategoryAll as [$page, $producer]) {
					$pageContainer = $mainContainer->addContainer($page->getPK());
					
					$pageContainer->addCheckbox('active')->setDefaultValue($page->active);
					$pageContainer->addText('name')->setDefaultValue($page->name);
					$pageContainer->addInteger('priority')->setRequired()->setDefaultValue($page->priority ?? 10);
				}
			}
		}
		
		$this->monitor(Presenter::class, function ($presenter) use ($pagesCategoryAll): void {
			$this->template->producerPages = $pagesCategoryAll;
		});

		if (!$category || ($category && !$category->type->isReadOnly())) {
			$form->addSubmits(!$category);
		}
		
		$form->onSuccess[] = function (AdminForm $form) use ($pagesCategoryAll): void {
			$values = $form->getValues('array');

			/** @var \Eshop\Admin\CategoryPresenter $presenter */
			$presenter = $this->getPresenter();

			$presenter->createImageDirs(Category::IMAGE_DIR);
			
			$active = [];
			$nonActive = [];
			
			foreach ($this->formFactory->getMutations() as $mutation) {
				$active[$mutation] = true;
				$nonActive[$mutation] = false;
			}
			
			$this->pageRepository->many()->where('this.uuid', \array_keys($pagesCategoryAll))->update(['active' => $nonActive]);
			
			if (isset($values['categoryProducerPages'])) {
				foreach ($values['categoryProducerPages'] as $pagePK => $pageValues) {
					$pageTitle = $pageValues['name'];
					$pageValues['name'] = [];
					$pageActive = $pageValues['active'];
					unset($pageValues['active']);
					
					foreach (\array_keys($this->categoryRepository->getConnection()->getAvailableMutations()) as $mutation) {
						$pageValues['name'][$mutation] = $pageTitle;
					}
					
					$this->pageRepository->many()->where('this.uuid', $pagePK)->update(['active' => $pageActive ? $active : $nonActive] + $pageValues);
				}
			}
			
			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
				$values['type'] = $presenter->tab;
			}

			/** @var \Forms\Controls\UploadImage $upload */
			$upload = $form['imageFileName'];
			
			$values['imageFileName'] = $upload->upload($values['uuid'] . '.%2$s');

			/** @var \Forms\Controls\UploadImage $upload */
			$upload = $form['productFallbackImageFileName'];

			$values['productFallbackImageFileName'] = $upload->upload($values['uuid'] . '_fallback.%2$s');
			$values['path'] = $this->categoryRepository->generateUniquePath($values['ancestor'] ? $this->categoryRepository->one($values['ancestor'])->path : '');

			/** @var \Eshop\DB\Category $category */
			$category = $this->categoryRepository->syncOne($values, null, true);

			$this->categoryRepository->updateCategoryChildrenPath($category);
			
			$form->syncPages(function () use ($category, $values): void {
				$values['page']['params'] = Helpers::serializeParameters(['category' => $category->getPK()]);
				$this->pageRepository->syncOne($values['page']);
			});
			
			$this->getPresenter()->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$category]);
		};
		
		$this->addComponent($form, 'form');
	}
	
	public function render(): void
	{
		$this->template->category = $this->category;

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render(__DIR__ . '/categoryForm.latte');
	}
}
