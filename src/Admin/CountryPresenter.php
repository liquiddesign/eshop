<?php
declare(strict_types=1);

namespace Eshop\Admin;

use App\Admin\Controls\AdminForm;
use App\Admin\Controls\AdminFormFactory;
use App\Admin\Controls\AdminGrid;
use App\Admin\PresenterTrait;
use Eshop\DB\Country;
use Eshop\DB\CountryRepository;
use Eshop\DB\VatRate;
use Eshop\DB\VatRateRepository;
use Forms\Form;
use Nette\Http\Request;
use StORM\DIConnection;

class CountryPresenter extends \Nette\Application\UI\Presenter
{
	use PresenterTrait;

	/** @inject */
	public CountryRepository $countryRepository;
	
	/** @inject */
	public VatRateRepository $vatRateRepository;

	/** @inject */
	public Request $request;

	public function createComponentGrid()
	{
		$grid = $this->gridFactory->create($this->countryRepository->many(), 20, 'code', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'fit']);
		$grid->addColumnText('Název', 'name', '%s', 'name');
		
		$grid->addColumnLink('vats', 'DPH');
		$grid->addColumnLinkDetail('Detail');

		//$grid->addButtonSaveAll();

		$grid->addFilterTextInput('search', ['name','code'], null, 'Název, kód');
		$grid->addFilterButtons();

		return $grid;
	}
	
	public function createComponentVatGrid()
	{
		$grid = $this->gridFactory->create($this->vatRateRepository->many()->where('fk_country', $this->getParameter('country')), 20, 'rate', 'ASC', true);
		$grid->addColumnSelector();
		
		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnText('Výše', 'rate', '%s %%', 'rate');
		
		$grid->addColumnLinkDetail('vatDetail');
		
		// $grid->addButtonSaveAll();
		
		$grid->addFilterTextInput('search', ['this.name'], null, 'Název');
		$grid->addFilterButtons();
		
		return $grid;
	}
	
	public function createComponentNewForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addText('code', 'Kód');
		$form->addText('name', 'Název');
		$form->addSubmits();

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			$country = $this->countryRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$country]);
		};

		return $form;
	}
	
	public function createComponentVatForm(): AdminForm
	{
		$form = $this->formFactory->create();
		
		$form->addText('name', 'Název');
		$form->addText('rate', 'Výše')->addRule($form::FLOAT)->setRequired();
		$form->addHidden('country', (string) $this->getParameter('vat')->country);
		$form->addSubmits(false, false);
		
		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');
			
			$this->vatRateRepository->syncOne($values, null, true);
			
			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('vats', 'vats', [], [$this->getParameter('vat')->country]);
		};
		
		return $form;
	}

	public function renderDefault()
	{
		$this->template->headerLabel = 'Země a DPH';
		$this->template->headerTree = [
			['Země a DPH'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('grid')];
	}
	
	public function renderVats(Country $country)
	{
		$this->template->headerLabel = 'Země a DPH: ' . $country->name;
		$this->template->headerTree = [
			['Země a DPH'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('vatGrid')];
	}

	public function renderNew()
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Země a DPH', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail()
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Země a DPH', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(Country $country)
	{
		/** @var Form $form */
		$form = $this->getComponent('newForm');

		$form->setDefaults($country->toArray());
	}
	
	public function renderVatDetail(VatRate $vat)
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Výše DPH', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('vatForm')];
	}
	
	public function actionVatDetail(VatRate $vat)
	{
		/** @var Form $form */
		$form = $this->getComponent('vatForm');
		
		$form->setDefaults($vat->toArray());
	}
}