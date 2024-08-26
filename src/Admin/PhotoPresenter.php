<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Admin\Controls\AdminGrid;
use Eshop\DB\Photo;
use Eshop\DB\PhotoRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\Services\Product\PhotoExporterService;
use Eshop\Services\Product\PhotoImporterService;
use Forms\Form;
use League\Csv\Writer;
use Nette\Application\Responses\FileResponse;
use Nette\DI\Attributes\Inject;
use Nette\InvalidStateException;
use Nette\Utils\FileSystem;
use Nette\Utils\Image;
use Nette\Utils\Strings;
use StORM\Collection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class PhotoPresenter extends \Eshop\BackendPresenter
{
	protected const FTP_IMPORT_IMAGES_DIR = 'ftp_import_images';

	protected const ALLOW_IMPORT = true;

	protected const IMPORT_COLUMNS = [
		'uuid' => 'Klíč',
		'label_cs' => 'Popisek_cs',
	];

	protected const EXPORT_COLUMNS = [
		'label_cs' => 'Popisek_cs',
	];

	#[Inject]
	public PhotoRepository $photoRepository;
	
	#[Inject]
	public ProductRepository $productRepository;

	#[Inject]
	public AdminFormFactory $formFactory;

	#[Inject]
	public PhotoExporterService $photoExporterService;

	#[Inject]
	public PhotoImporterService $photoImporterService;

	#[Inject]
	public SettingRepository $settingRepository;

	public function createComponentPhotoGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->photoRepository->many(), 20, 'fileName', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnImage('fileName', Product::GALLERY_DIR);
		$grid->addColumn('Produkt', function (Photo $photo): string|null {
			return $photo->product->getFullCode() ?: $photo->product->name;
		}, '%s', 'product.code');
		$grid->addColumnText('Název', 'fileName', '%s', 'fileName');
		$grid->addColumnText('Popisek', 'label', '%s', 'label');
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$deleteCallback = function (Photo $photo): void {
			$subDirs = ['origin', 'detail', 'thumb'];
			$dir = $this->container->getParameters()['wwwDir'] . '/userfiles/' . Product::GALLERY_DIR;

			if ($photo->product->imageFileName === $photo->fileName) {
				$photo->product->update(['imageFileName' => null]);
			}

			foreach ($subDirs as $subDir) {
				try {
					FileSystem::delete("$dir/$subDir/$photo->fileName");
				} catch (\Throwable $e) {
					Debugger::barDump($e);
					Debugger::log($e, ILogger::WARNING);
				}
			}
		};

		$grid->addColumnLinkDetail('Detail');
		$grid->addColumnActionDelete($deleteCallback);

		$grid->addButtonDeleteSelected($deleteCallback);
		$grid->addButtonSaveAll();

		$grid->addBulkAction('export', 'export', 'Exportovat (CSV)');

		$grid->addFilterTextInput('search', ['product.code', 'fileName'], null, 'Kód produktu, název');

		if ($shops = $this->shopsConfig->getAvailableShops()) {
			$categoryTypes = [];

			foreach ($shops as $shop) {
				$setting = $this->settingRepository->getValueByName(SettingsPresenter::MAIN_CATEGORY_TYPE . '_' . $shop->getPK());

				if (!$setting) {
					continue;
				}

				$categoryTypes[] = $setting;
			}

			$categories = $categoryTypes ? $this->categoryRepository->getTreeArrayForSelect(true, $categoryTypes) : [];
		} else {
			$categories = $this->categoryRepository->getTreeArrayForSelect();
		}

		if ($categories) {
			$exactCategories = $categories;
			$categories += ['0' => 'X - bez kategorie'];

			foreach ($exactCategories as $key => $value) {
				$categories += ['.' . $key => $value . ' (bez podkategorií)'];
			}

			$grid->addFilterDataSelect(function (Collection $source, $value): void {
				if (\str_starts_with($value, '.')) {
					$subSelect = $this->categoryRepository->getConnection()->rows(['eshop_product_nxn_eshop_category'])
						->where('this.uuid = eshop_product_nxn_eshop_category.fk_product')
						->where('eshop_product_nxn_eshop_category.fk_category', Strings::substring($value, 1));

					$source->where('EXISTS (' . $subSelect->getSql() . ')', $subSelect->getVars());
				} else {
					$category = $this->categoryRepository->one($value);

					if (!$category && $value !== '0') {
						$source->where('1=0');

						return;
					}

					if ($value === '0') {
						return;
					}

					$allSubCategoriesForCategory = $this->categoryRepository->many()
						->where('this.path LIKE :path', ['path' => "$category->path%"])
						->setSelect(['uuid'])
						->toArrayOf('uuid', toArrayValues: true);

					$subSelect = $this->categoryRepository->getConnection()->rows(['eshop_product_nxn_eshop_category'])
						->where('this.uuid = eshop_product_nxn_eshop_category.fk_product')
						->where('eshop_product_nxn_eshop_category.fk_category', $allSubCategoriesForCategory);

					$source->where('EXISTS (' . $subSelect->getSql() . ')', $subSelect->getVars());
				}
			}, '', 'category', null, $categories)->setPrompt('- Kategorie -');
		}

		$grid->addFilterButtons();

		return $grid;
	}
	
	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create(true);
		
		$form->addText('fileName', 'Název soboru')->setDisabled();
		$form->addLocaleText('label', 'Popisek');
		$form->addInteger('priority', 'Priorita')->setRequired()->setDefaultValue(10);
		$form->addCheckbox('hidden', 'Skryto');
		//$form->addSelect('product', 'Produkt', $this->productRepository->getListForSelect());
		$form->addSubmit('submit', 'Uložit');

		/** @var \Eshop\DB\Photo $photo */
		$photo = $this->getParameter('photo');

		$form->onSuccess[] = function (Form $form) use ($photo): void {
			$values = $form->getValues('array');

			$photo->update($values);

			$this->flashMessage('Uloženo', 'success');
			$this->redirect('this');
		};
		
		return $form;
	}

	public function actionImportCsv(): void
	{
		$this->connection->setDebug(false);
	}

	public function renderImportCsv(): void
	{
		$this->template->headerLabel = 'Import';
		$this->template->headerTree = [
			['Produktové obrázky', 'default'],
			['Import'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('importCsvForm')];

		try {
			$importImagesFromStorage = $this->container->getParameter('ftp_import_images');
		} catch (InvalidStateException $e) {
			Debugger::barDump($e);

			return;
		}

		if ($importImagesFromStorage && !isset($importImagesFromStorage['host']) || !$importImagesFromStorage['host']) {
			return;
		}

		$this->template->displayControls[] = $this->getComponent('importImagesForm');
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Produktové obrázky';
		$this->template->headerTree = [
			['Obrázky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('photoGrid')];

		if ($this::ALLOW_IMPORT) {
			$this->template->displayButtons[] = $this->createButton('importCsv', '<i class="fas fa-file-upload mr-1"></i>Import');
		}

		return;
	}
	
	public function renderDetail(Photo $photo, ?Product $product = null): void
	{
		unset($photo);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Fotky', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$product ? $this->createBackButton(':Eshop:Admin:Product:productPhotos', $product) : $this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function actionDetail(Photo $photo): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');

		$form->setDefaults($photo->toArray());
	}

	public function createComponentImportCsvForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addGroup('CSV soubor');

		$importColumns = $this::IMPORT_COLUMNS;
		$allowedColumns = null;

		foreach ($importColumns as $key => $value) {
			$allowedColumns .= "$key, $value<br>";
		}

		$filePicker = $form->addFilePicker('file', 'Soubor (CSV)')
			->setRequired()
			->addRule($form::MimeType, 'Neplatný soubor!', 'text/csv');

		$dataInfo = '<h5 class="mt-2">Nápověda</h5>
Soubor <b>musí obsahovat</b> hlavičku a sloupec "Klíč" pro jednoznačné rozlišení produktů.&nbsp;<br><br>
Povolené sloupce hlavičky (lze použít obě varianty kombinovaně):<br>
' . $allowedColumns . '<br>
<br>
<b>Pozor!</b> Pokud pracujete se souborem na zařízeních Apple, ujistětě se, že vždy při ukládání použijete možnost uložit do formátu Windows nebo Linux (UTF-8)!';

		$form->addSelect('delimiter', 'Oddělovač', [
			';' => 'Středník (;)',
			',' => 'Čárka (,)',
			'   ' => 'Tab (\t)',
			' ' => 'Mezera ( )',
			'|' => 'Pipe (|)',
		])->setHtmlAttribute('data-info', $dataInfo);

		$form->addSubmit('submit', 'Importovat');

		$form->onValidate[] = function (AdminForm $form) use ($filePicker): void {
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $values['file'];

			if ($file->hasFile()) {
				return;
			}

			$filePicker->addError('Neplatný soubor!');
		};

		$form->onSuccess[] = function (AdminForm $form) use ($importColumns): void {
			/** @var array<mixed> $values */
			$values = $form->getValues('array');

			/** @var \Nette\Http\FileUpload $file */
			$file = $values['file'];

			$tempFileName = \tempnam($this->container->getParameter('tempDir'), 'products');

			if (!$tempFileName) {
				throw new \Exception('Cant create temp file');
			}

			$file->move($tempFileName);

			$connection = $this->productRepository->getConnection();
			$connection->getLink()->beginTransaction();

			try {
				Debugger::log($this->photoImporterService->importCsv(
					$tempFileName,
					$values['delimiter'],
					$importColumns,
				), ILogger::DEBUG);

				$connection->getLink()->commit();
				$this->flashMessage('Import produktů úspěšný', 'success');
			} catch (\Exception $e) {
				Debugger::barDump($e);

				$connection->getLink()->rollBack();

				$this->flashMessage($e->getMessage() !== '' ? $e->getMessage() : 'Import produktů se nezdařil!', 'error');
			}

			try {
				FileSystem::delete($tempFileName);
			} catch (\Exception $e) {
				Debugger::log($e, ILogger::WARNING);
			}

			$this->redirect('this');
		};

		return $form;
	}

	public function createComponentImportImagesForm(): AdminForm
	{
		$importImagesFromStorage = $this->container->getParameter('ftp_import_images');

		$form = $this->formFactory->create(false, false, false, false, false);

		$form->addGroup('Obrázky z FTP úložiště');
		$form->addText('protocol', 'Protokol')->setDisabled()->setDefaultValue('FTP');
		$form->addText('server', 'Server (Host)')->setDisabled()->setDefaultValue($importImagesFromStorage['host'] ?? '');
		$form->addText('username', 'Uživatelské jméno')->setDisabled()->setDefaultValue($importImagesFromStorage['user'] ?? '');
		$form->addText('password', 'Heslo')->setDisabled()->setDefaultValue($importImagesFromStorage['password'] ?? '');
		$form->addCheckbox('deleteCurrentImages', 'Vymazat aktuální obrázky');
		$form->addCheckbox('asMain', 'Nastavit jako hlavní obrázek')->setHtmlAttribute('data-info', 'Pro práci s FTP doporučejeme klient WinSCP dostupný zde: 
<a target="_blank" href="https://winscp.net/eng/download.php">https://winscp.net/eng/download.php</a><br>
Výše zobrazené údaje stačí v klientovi vyplnit a nahrát obrázky.<br><br>
Název souborů musí být ve formátu "kod_název_1.přípona". Např.: "ABC_obrazek_1.jpg"<br>
Můžete nahrát více obrázků pro jeden produkt. Např.: "ABC_obrazek_1.jpg", "ABC_obrazek_2.jpg", ...');

		$form->addSubmit('images', 'Importovat');

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$connection = $this->productRepository->getConnection();
			$mutations = $this->productRepository->getConnection()->getAvailableMutations();

			$imagesPath = \dirname(__DIR__, 5) . '/userfiles/' . $this::FTP_IMPORT_IMAGES_DIR;
			$originalPath = \dirname(__DIR__, 5) . '/userfiles/' . Product::GALLERY_DIR . '/origin';
			$thumbPath = \dirname(__DIR__, 5) . '/userfiles/' . Product::GALLERY_DIR . '/thumb';
			$detailPath = \dirname(__DIR__, 5) . '/userfiles/' . Product::GALLERY_DIR . '/detail';

			FileSystem::createDir($imagesPath);
			FileSystem::createDir($originalPath);
			FileSystem::createDir($thumbPath);
			FileSystem::createDir($detailPath);

			$images = \scandir($imagesPath);

			$products = $this->productRepository->many()->setIndex('code')->toArrayOf('uuid');

			$photosToImport = [];

			foreach ($images as $image) {
				if ($image === '.' || $image === '..') {
					continue;
				}

				$code = ($underscorePos = Strings::indexOf($image, '_')) ? Strings::substring($image, 0, $underscorePos) : Strings::substring($image, 0, Strings::indexOf($image, '.'));

				if (!$code || !isset($products[$code])) {
					continue;
				}

				if ($values['deleteCurrentImages']) {
					$product = $this->productRepository->one(['code' => $code], true);
					$productImages = $product->photos->toArray();

					foreach ($productImages as $productImage) {
						FileSystem::delete($originalPath . '/' . $productImage->fileName);
						FileSystem::delete($thumbPath . '/' . $productImage->fileName);
						FileSystem::delete($detailPath . '/' . $productImage->fileName);
					}

					$product->photos->delete();
					$product->update(['imageFileName' => null]);
				}

				$photosToImport[$code][] = $image;
			}

			if (\count($photosToImport) === 0) {
				$this->flashMessage('Nenalezen žádný odpovídající obrázek!', 'warning');
				$this->redirect('this');
			}

			$connection->getLink()->beginTransaction();

			$newPhotos = [];
			$newProductsMainImages = [];

			try {
				foreach ($photosToImport as $productCode => $photos) {
					$first = true;

					foreach ($photos as $photoFileName) {
						if (!isset($products[$productCode])) {
							continue;
						}

						$imageD = Image::fromFile($imagesPath . '/' . $photoFileName);
						$imageT = Image::fromFile($imagesPath . '/' . $photoFileName);
						$imageD->resize(600, null);
						$imageT->resize(300, null);

						FileSystem::copy($imagesPath . '/' . $photoFileName, $originalPath . '/' . $photoFileName);

						try {
							$imageD->save($detailPath . '/' . $photoFileName);
							$imageT->save($thumbPath . '/' . $photoFileName);
						} catch (\Exception $e) {
						}

						$existingPhoto = $this->photoRepository->many()->where('this.fk_product', $products[$productCode])->where('this.fileName', $photoFileName)->first();

						if (!$existingPhoto) {
							$newPhotoArray = [
								'product' => $products[$productCode],
								'fileName' => $photoFileName,
								'priority' => 999,
							];

							$fileParts = \pathinfo($photoFileName);

							$name = $fileParts['filename'];

							foreach (\array_keys($mutations) as $mutation) {
								$newPhotoArray['label'][$mutation] = $name;
							}

							$newPhotos[] = $newPhotoArray;
						}

						if (!$values['asMain'] || !$first) {
							continue;
						}

						$first = false;

						$newProductsMainImages[] = ['uuid' => $products[$productCode], 'imageFileName' => $photoFileName];
					}
				}

				$this->photoRepository->syncMany($newPhotos, []);

				if (\count($newProductsMainImages) > 0) {
					$this->productRepository->syncMany($newProductsMainImages);
				}

				$this->flashMessage('Provedeno', 'success');

				$connection->getLink()->commit();
			} catch (\Throwable $e) {
				Debugger::dump($e);
				$this->flashMessage('Při zpracovávání došlo k chybě!', 'error');

				$connection->getLink()->rollBack();
			}

			$this->redirect('this');
		};

		return $form;
	}

	public function actionExport(array $ids): void
	{
		unset($ids);
	}

	public function renderExport(array $ids): void
	{
		unset($ids);

		$this->template->headerLabel = 'Export obrázků do CSV';
		$this->template->headerTree = [
			['Produktové obrázky', 'default'],
			['Export obrázků'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('exportForm')];
	}

	public function createComponentExportForm(): AdminForm
	{
		return $this->formFactory->createBulkActionForm($this->getBulkFormGrid('photoGrid'), function (array $values, Collection $collection): void {
			$tempFilename = \tempnam($this->tempDir, 'csv');

			$this->photoExporterService->exportCsv($collection, Writer::createFromPath($tempFilename), $this::EXPORT_COLUMNS);

			$this->sendResponse(new FileResponse($tempFilename, 'photos.csv', 'text/csv'));
		}, $this->getBulkFormActionLink(), $this->photoRepository->many(), $this->getBulkFormIds());
	}
}
