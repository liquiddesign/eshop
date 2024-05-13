<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminFormFactory;
use Eshop\DB\File;
use Eshop\DB\FileRepository;
use Eshop\DB\Product;
use Forms\Form;
use Nette\Utils\FileSystem;

class FilePresenter extends BackendPresenter
{
	#[\Nette\DI\Attributes\Inject]
	public FileRepository $fileRepository;

	#[\Nette\DI\Attributes\Inject]
	public AdminFormFactory $formFactory;
	
	private string $productFilesPath;
	
	public function startup(): void
	{
		parent::startup();

		$this->productFilesPath = \dirname(__DIR__, 3) . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . Product::FILE_DIR;
	}
	
	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create();
		
		$form->addText('fileName', 'Název souboru');
		$form->addLocaleText('label', 'Popisek');
		$form->addInteger('priority', 'Priorita');
		$form->addCheckbox('hidden', 'Skryto');
		$form->addSubmit('submit', 'Uložit');
		
		return $form;
	}
	
	public function renderDetail(File $file, Product $product): void
	{
		unset($file);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Soubory', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton(':Eshop:Admin:Product:productFiles', $product)];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function actionDetail(File $file, Product $product): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');
		
		$values = $file->toArray();
		$values['hidden'] = (int) $values['hidden'];
		$form->setDefaults($values);
		
		$form->onSuccess[] = function (Form $form) use ($file, $product): void {
			$values = $form->getValues('array');

			foreach (\array_keys($values) as $key) {
				$values[$key] = $values[$key] !== '' ? $values[$key] : null;
			}

			if ($file->fileName !== $values['fileName']) {
				if ($this->fileRepository->one(['fileName' => $values['fileName']])) {
					$this->flashMessage('Chyba: Soubor s tímto názvem již existuje!', 'error');
					$this->redirect('this');
				}

				FileSystem::rename($this->productFilesPath . \DIRECTORY_SEPARATOR . $file->fileName, $this->productFilesPath . \DIRECTORY_SEPARATOR . $values['fileName']);
			}

			$values['hidden'] = (bool) $values['hidden'];
			$values['priority'] = $values['priority'] !== '' ? $values['priority'] : 10;
			$file->update($values);
			$this->flashMessage('Uloženo', 'success');
			$this->redirect(':Eshop:Admin:Product:productFiles', $product);
		};
	}
}
