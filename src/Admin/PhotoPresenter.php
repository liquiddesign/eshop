<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminFormFactory;
use Eshop\DB\Photo;
use Eshop\DB\PhotoRepository;
use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Forms\Form;
use Nette\Utils\FileSystem;

class PhotoPresenter extends BackendPresenter
{
	/** @inject */
	public PhotoRepository $photoRepository;
	
	/** @inject */
	public ProductRepository $productRepository;

	/** @inject */
	public AdminFormFactory $formFactory;
	
	private string $productPhotosPath;
	
	public function startup(): void
	{
		parent::startup();

		$this->productPhotosPath = \dirname(__DIR__, 3) . \DIRECTORY_SEPARATOR . 'userfiles' . \DIRECTORY_SEPARATOR . Product::IMAGE_DIR;
	}
	
	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create(true);
		
		$form->addText('fileName', 'Název soboru');
		$form->addLocaleText('label', 'Popisek');
		$form->addInteger('priority', 'Priorita');
		$form->addCheckbox('hidden', 'Skryto');
		//$form->addSelect('product', 'Produkt', $this->productRepository->getListForSelect());
		$form->addSubmit('submit', 'Uložit');
		
		return $form;
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
	
	public function actionDetail(Photo $photo, ?Product $product = null): void
	{
		unset($product);

		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');
		
		$values = $photo->toArray();
		$values['hidden'] = (int)$values['hidden'];
		$form->setDefaults($values);
		
		$form->onSuccess[] = function (Form $form) use ($photo): void {
			$values = $form->getValues();

			foreach (\array_keys($values) as $key) {
				$values[$key] = $values[$key] !== '' ? $values[$key] : null;
			}

			if ($photo->fileName !== $values['fileName']) {
				if ($this->photoRepository->one(['fileName' => $values['fileName']])) {
					$this->flashMessage('Chyba: Soubor s tímto názvem již existuje!', 'error');
					$this->redirect('this');
				}

				FileSystem::rename($this->productPhotosPath . \DIRECTORY_SEPARATOR . $photo->fileName, $this->productPhotosPath . \DIRECTORY_SEPARATOR . $values['fileName']);
			}

			$values['hidden'] = (bool)$values['hidden'];
			$values['priority'] = $values['priority'] !== '' ? $values['priority'] : 10;
			$photo->update($values);
			$this->flashMessage('Uloženo', 'success');
			$this->redirect('this');
		};
	}
}
