<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\Admin\CategoryPresenter;
use Eshop\DB\Category;
use Eshop\DB\CategoryRepository;
use Eshop\ShopperUser;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Utils\Image;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use Pages\Helpers;
use StORM\DIConnection;
use Web\DB\PageRepository;
use Web\DB\SettingRepository;

class CategoryForm extends Control
{
	public function __construct(
		private readonly CategoryRepository $categoryRepository,
		AdminFormFactory $formFactory,
		private readonly PageRepository $pageRepository,
		private readonly SettingRepository $settingRepository,
		private readonly ShopperUser $shopperUser,
		private readonly ?Category $category
	) {
		$form = $formFactory->create(true);

		$form->addText('code', 'Kód')->setRequired();

		$imagePicker = $form->addImagePicker('imageFileName', 'Obrázek', [
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => function (Image $image): void {
				$image->resize($this->shopperUser->getCategoriesImage()['detail']['width'], $this->shopperUser->getCategoriesImage()['detail']['height']);
			},
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => function (Image $image): void {
				$image->resize($this->shopperUser->getCategoriesImage()['thumb']['width'], $this->shopperUser->getCategoriesImage()['thumb']['height']);
			},
		]);

		if ($this->shopperUser->getCategoriesImage()['detail']['width']) {
			$imagePicker->setHtmlAttribute('data-info', 'Vkládejte obrázky o minimální šířce ' . $this->shopperUser->getCategoriesImage()['detail']['width'] . 'px.');
		}

		if ($this->shopperUser->getCategoriesImage()['detail']['height']) {
			$imagePicker->setHtmlAttribute('data-info', 'Vkládejte obrázky o minimální výšce ' . $this->shopperUser->getCategoriesImage()['detail']['height'] . 'px.');
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
				$image->resize($this->shopperUser->getCategoriesFallbackImage()['detail']['width'], $this->shopperUser->getCategoriesFallbackImage()['detail']['height']);
			},
			Category::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => function (Image $image): void {
				$image->resize($this->shopperUser->getCategoriesFallbackImage()['thumb']['width'], $this->shopperUser->getCategoriesFallbackImage()['thumb']['height']);
			},
		])->setHtmlAttribute('data-info', 'Vkládejte obrázky o minimální výšce 600px s libovolnou šířkou.');

		if ($this->shopperUser->getCategoriesFallbackImage()['detail']['width']) {
			$imagePicker->setHtmlAttribute('data-info', 'Vkládejte obrázky o minimální šířce ' . $this->shopperUser->getCategoriesFallbackImage()['detail']['width'] . 'px.');
		}

		if ($this->shopperUser->getCategoriesFallbackImage()['detail']['height']) {
			$imagePicker->setHtmlAttribute('data-info', 'Vkládejte obrázky o minimální výšce ' . $this->shopperUser->getCategoriesFallbackImage()['detail']['height'] . 'px.');
		}

		$this->monitor(Presenter::class, function (CategoryPresenter $presenter) use ($imagePicker, $category): void {
			$imagePicker->onDelete[] = function (array $directories, $filename) use ($category, $presenter): void {
				$presenter->onDeleteImagePublic($category, 'productFallbackImageFileName');
				$presenter->redirect('this');
			};
		});

		$nameInput = $form->addLocaleText('name', 'Název');
		$form->addLocalePerexEdit('perex', 'Perex', [
			/** @codingStandardsIgnoreStart Long string*/
			'toolbar1' => 'undo redo | styleselect | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | link unlink anchor | table | copy cut paste pastetext insertcontent code',
			/** @codingStandardsIgnoreEnd */
			'plugins' => 'table code link',
		]);
		$form->addLocaleRichEdit('content', 'Obsah');
		$form->addLocalePerexEdit('defaultProductPerex', 'Výchozí perex produktů', [
			/** @codingStandardsIgnoreStart Long string */
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
					$form->addSelect2('exportZboziCategory', 'Exportní kategorie pro Zboží', $categories)->setPrompt('Žádná');
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
					$form->addSelect2('exportHeurekaCategory', 'Exportní kategorie pro Heuréku', $categories)->setPrompt('Žádná');
			} else {
				$form->addSelect2('exportHeurekaCategory', 'Exportní kategorie pro Heuréku', $categories)->setPrompt('Žádná')
					->setDisabled()
					->checkDefaultValue(false)
					->setHtmlAttribute('data-info', 'Nejprve zvolte v nastavení exportů typ kategorií pro Heuréku.');
			}
		});

		$form->addText('exportGoogleCategory', 'Exportní název pro Google');
		$form->addText('exportGoogleCategoryId', 'Exportní ID kategorie Google');
		$form->addInteger('priority', 'Priorita')->setDefaultValue(10)->setRequired();
		$form->addCheckbox('hidden', 'Skryto');
		$form->addCheckbox('showInMenu', 'Zobrazit v menu');
		$form->addCheckbox('showEmpty', 'Zobrazit pokud nemá produkty');
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

			unset($values['imageFileName']);

			if ($upload->isOk() && $upload->isFilled()) {
				$userDir = $form->getUserDir();
				$fileName = \pathinfo($upload->getValue()->getSanitizedName(), \PATHINFO_FILENAME);
				$fileExtension = Strings::lower(\pathinfo($upload->getValue()->getSanitizedName(), \PATHINFO_EXTENSION));

				$newsImageDir = Category::IMAGE_DIR;

				while (\is_file("$userDir/$newsImageDir/origin/$fileName.$fileExtension")) {
					$fileName .= '-' . Random::generate(1, '0-9');
				}

				$values['imageFileName'] = $upload->upload($fileName . '.%2$s');
			}

			/** @var \Forms\Controls\UploadImage $upload */
			$upload = $form['productFallbackImageFileName'];

			unset($values['productFallbackImageFileName']);

			if ($upload->isOk() && $upload->isFilled()) {
				$userDir = $form->getUserDir();
				$fileName = \pathinfo($upload->getValue()->getSanitizedName(), \PATHINFO_FILENAME);
				$fileExtension = Strings::lower(\pathinfo($upload->getValue()->getSanitizedName(), \PATHINFO_EXTENSION));

				$newsImageDir = Category::IMAGE_DIR;

				while (\is_file("$userDir/$newsImageDir/origin/$fileName.$fileExtension")) {
					$fileName .= '-' . Random::generate(1, '0-9');
				}

				$values['productFallbackImageFileName'] = $upload->upload($fileName . '-fallback.%2$s');
			}

			$values['path'] = $this->categoryRepository->generateUniquePath($values['ancestor'] ? $this->categoryRepository->one($values['ancestor'])->path : '');

			/** @var \Eshop\DB\Category $category */
			$category = $this->categoryRepository->syncOne($values, null, true, ignore: false);

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
