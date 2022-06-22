<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\Admin\CategoryPresenter;
use Eshop\DB\Category;
use Eshop\DB\CategoryRepository;
use Eshop\Shopper;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Utils\Image;
use Pages\Helpers;
use StORM\DIConnection;
use Web\DB\PageRepository;
use Web\DB\SettingRepository;

class CategoryForm extends Control
{
	private CategoryRepository $categoryRepository;

	private PageRepository $pageRepository;

	private SettingRepository $settingRepository;

	private Shopper $shopper;

	private ?Category $category;

	public function __construct(
		CategoryRepository $categoryRepository,
		AdminFormFactory $formFactory,
		PageRepository $pageRepository,
		SettingRepository $settingRepository,
		Shopper $shopper,
		?Category $category
	) {
		$this->category = $category;
		$this->categoryRepository = $categoryRepository;
		$this->pageRepository = $pageRepository;
		$this->settingRepository = $settingRepository;
		$this->shopper = $shopper;

		$form = $formFactory->create(true);

		$form->addText('code', 'Kód')->setRequired();

		$imagePicker = $form->addImagePicker('imageFileName', 'Obrázek', [
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => function (Image $image): void {
				$image->resize($this->shopper->getCategoriesImage()['detail']['width'], $this->shopper->getCategoriesImage()['detail']['height']);
			},
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => function (Image $image): void {
				$image->resize($this->shopper->getCategoriesImage()['thumb']['width'], $this->shopper->getCategoriesImage()['thumb']['height']);
			},
		]);

		if ($this->shopper->getCategoriesImage()['detail']['width']) {
			$imagePicker->setHtmlAttribute('data-info', 'Vkládejte obrázky o minimální šířce ' . $this->shopper->getCategoriesImage()['detail']['width'] . 'px.');
		}

		if ($this->shopper->getCategoriesImage()['detail']['height']) {
			$imagePicker->setHtmlAttribute('data-info', 'Vkládejte obrázky o minimální výšce ' . $this->shopper->getCategoriesImage()['detail']['height'] . 'px.');
		}

		$this->monitor(Presenter::class, function (CategoryPresenter $presenter) use ($imagePicker, $category): void {
			$imagePicker->onDelete[] = function (array $directories, $filename) use ($category, $presenter): void {
				$presenter->onDeleteImagePublic($category);
				$presenter->redirect('this');
			};
		});

		$imagePicker = $form->addImagePicker('productFallbackImageFileName', 'Placeholder produktů', [
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => function (Image $image): void {
				$image->resize($this->shopper->getCategoriesFallbackImage()['detail']['width'], $this->shopper->getCategoriesFallbackImage()['detail']['height']);
			},
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => function (Image $image): void {
				$image->resize($this->shopper->getCategoriesFallbackImage()['thumb']['width'], $this->shopper->getCategoriesFallbackImage()['thumb']['height']);
			},
		])->setHtmlAttribute('data-info', 'Vkládejte obrázky o minimální výšce 600px s libovolnou šířkou.');

		if ($this->shopper->getCategoriesFallbackImage()['detail']['width']) {
			$imagePicker->setHtmlAttribute('data-info', 'Vkládejte obrázky o minimální šířce ' . $this->shopper->getCategoriesFallbackImage()['detail']['width'] . 'px.');
		}

		if ($this->shopper->getCategoriesFallbackImage()['detail']['height']) {
			$imagePicker->setHtmlAttribute('data-info', 'Vkládejte obrázky o minimální výšce ' . $this->shopper->getCategoriesFallbackImage()['detail']['height'] . 'px.');
		}

		$this->monitor(Presenter::class, function (CategoryPresenter $presenter) use ($imagePicker, $category): void {
			$imagePicker->onDelete[] = function (array $directories, $filename) use ($category, $presenter): void {
				$presenter->onDeleteImagePublic($category, 'productFallbackImageFileName');
				$presenter->redirect('this');
			};
		});

		$nameInput = $form->addLocaleText('name', 'Název');
		$form->addLocalePerexEdit('perex', 'Perex', [
			/** @codingStandardsIgnoreStart */
			'toolbar1' => 'undo redo | styleselect | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | link unlink anchor | table | copy cut paste pastetext insertcontent code',
			/** @codingStandardsIgnoreEnd */
			'plugins' => 'table code link',
		]);
		$form->addLocaleRichEdit('content', 'Obsah');
		$form->addLocalePerexEdit('defaultProductPerex', 'Výchozí perex produktů', [
			/** @codingStandardsIgnoreStart */
			'toolbar1' => 'undo redo | styleselect | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | link unlink anchor | table | copy cut paste pastetext insertcontent code',
			/** @codingStandardsIgnoreEnd */
			'plugins' => 'table code link',
		]);
		$form->addLocaleRichEdit('defaultProductContent', 'Výchozí obsah produktů');

		$this->monitor(Presenter::class, function ($presenter) use ($form, $category): void {
			$categories = $this->categoryRepository->getTreeArrayForSelect(true, $presenter->tab);

			if ($category) {
				unset($categories[$category->getPK()]);
			}

			$form->addSelect2('ancestor', 'Nadřazená kategorie', $categories)->setPrompt('Žádná');

			/** @var \Web\DB\Setting|null $categoryTypeSetting */
			$categoryTypeSetting = $this->settingRepository->many()->where('name', 'zboziCategoryTypeToParse')->first();

			if ($categoryTypeSetting && $categoryTypeSetting->value) {
				$categories = $this->categoryRepository->getTreeArrayForSelect(true, $categoryTypeSetting->value);

				if ($category) {
					$form->addSelect2('exportZboziCategory', 'Exportní kategorie pro Zboží', $categories)->setPrompt('Žádná');
				}
			} else {
				$form->addSelect2('exportZboziCategory', 'Exportní kategorie pro Zboží', $categories)
					->setPrompt('Žádná')
					->setDisabled()
					->checkDefaultValue(false)
					->setHtmlAttribute('data-info', 'Nejprve zvolte v nastavení exportů typ kategorií pro Zboží.');
			}

			/** @var \Web\DB\Setting|null $categoryTypeSetting */
			$categoryTypeSetting = $this->settingRepository->many()->where('name', 'heurekaCategoryTypeToParse')->first();

			if ($categoryTypeSetting && $categoryTypeSetting->value) {
				$categories = $this->categoryRepository->getTreeArrayForSelect(true, $categoryTypeSetting->value);

				if ($category) {
					$form->addSelect2('exportHeurekaCategory', 'Exportní kategorie pro Heuréku', $categories)->setPrompt('Žádná');
				}
			} else {
				$form->addSelect2('exportHeurekaCategory', 'Exportní kategorie pro Heuréku', $categories)->setPrompt('Žádná')->setDisabled()
					->setHtmlAttribute('data-info', 'Nejprve zvolte v nastavení exportů typ kategorií pro Heuréku.');
			}
		});

		$form->addText('exportGoogleCategory', 'Exportní název pro Google');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10)->setRequired();
		$form->addCheckbox('hidden', 'Skryto');
		$form->addCheckbox('showInMenu', 'Zobrazit v menu');
		$form->addCheckbox('recommended', 'Doporučeno');

		$form->addPageContainer('product_list', ['category' => $category], $nameInput);

		if (!$category || (!$category->type->isReadOnly())) {
			$form->addSubmits(!$category);
		}

		$form->onValidate[] = function (AdminForm $form): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues('array');

			$columnsToCheck = ['defaultProductPerex', 'defaultProductContent'];

			foreach ($columnsToCheck as $column) {
				foreach ($values[$column] as $mutation => $content) {
					if (!$this->categoryRepository->isDefaultContentValid($content)) {
						/** @var \Nette\Forms\Controls\TextInput $input */
						$input = $form[$column][$mutation];
						$input->addError('Neplatný text! Zkontrolujte správnost proměnných!');
					}
				}
			}
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			/** @var \Eshop\Admin\CategoryPresenter $presenter */
			$presenter = $this->getPresenter();

			$presenter->createImageDirs(Category::IMAGE_DIR);

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

			$this->categoryRepository->recalculateCategoryTree($presenter->tab);

			$form->syncPages(function () use ($category, $values): void {
				$values['page']['params'] = Helpers::serializeParameters(['category' => $category->getPK()]);
				$this->pageRepository->syncOne($values['page']);
			});

			$this->categoryRepository->clearCategoriesCache();

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
