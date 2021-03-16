<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use App\Admin\Controls\AdminFormFactory;
use Eshop\DB\File;
use Eshop\DB\FileRepository;
use Eshop\DB\Product;
use Forms\Form;
use Nette\Utils\FileSystem;

class FilePresenter extends BackendPresenter
{
	/** @inject */
	public FileRepository $fileRepository;
	
	private string $productFilesPath;
	
	public function startup()
	{
		parent::startup();
		$this->productFilesPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . Product::FILE_DIR;
	}
	
	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->createForm();
		
		$form->addText('fileName', 'Název souboru');
		$form->addLocaleText('label', 'Popisek');
		$form->addInteger('priority', 'Priorita');
		$form->addCheckbox('hidden', 'Skryto');
		$form->addSubmit('submit', 'Uložit');
		
		return $form;
	}
	
	public function renderDetail(File $file, Product $product)
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Soubory', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton(':Eshop:Admin:Product:productFiles', $product)];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}
	
	public function actionDetail(File $file, Product $product)
	{
		/** @var Form $form */
		$form = $this->getComponent('newForm');
		
		$values = $file->toArray();
		$values['hidden'] = (int)$values['hidden'];
		$form->setDefaults($values);
		
		$form->onSuccess[] = function (Form $form) use ($file, $product) {
			$values = $form->getValues();
			foreach ($values as $key => $value) {
				$values[$key] = $values[$key] != '' ? $values[$key] : null;
			}
			if ($file->fileName != $values['fileName']) {
				if ($this->fileRepository->one(['fileName' => $values['fileName']])) {
					$this->flashMessage('Chyba: Soubor s tímto názvem již existuje!', 'error');
					$this->redirect('this');
				}
				FileSystem::rename($this->productFilesPath . DIRECTORY_SEPARATOR . $file->fileName, $this->productFilesPath . DIRECTORY_SEPARATOR . $values['fileName']);
			}
			$values['hidden'] = (bool)$values['hidden'];
			$values['priority'] = $values['priority'] != '' ? $values['priority'] : 10;
			$file->update($values);
			$this->flashMessage('Uloženo', 'success');
			$this->redirect(':Eshop:Admin:Product:productFiles', $product);
		};
	}
	
}