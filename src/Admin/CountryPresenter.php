<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\Country;
use Eshop\DB\CountryRepository;
use Eshop\DB\VatRate;
use Eshop\DB\VatRateRepository;
use Nette\Http\Request;

class CountryPresenter extends BackendPresenter
{
	/** @inject */
	public CountryRepository $countryRepository;

	/** @inject */
	public VatRateRepository $vatRateRepository;

	/** @inject */
	public Request $request;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->countryRepository->many(), 20, 'code', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'fit']);
		$grid->addColumnText('Název', 'name', '%s', 'name');

		$grid->addColumnLink('vats', 'DPH');
		$grid->addColumnLinkDetail('Detail');

		//$grid->addButtonSaveAll();

		$grid->addFilterTextInput('search', ['name', 'code'], null, 'Název, kód');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentVatGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->vatRateRepository->many()->where('fk_country', $this->getParameter('country')), 20, 'priority', 'ASC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Název', 'name', '%s', 'name');
		$grid->addColumnText('Výše', 'rate', '%s %%', 'rate');
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);

		$grid->addColumnLinkDetail('vatDetail');

		$grid->addButtonSaveAll();

		$grid->addFilterTextInput('search', ['this.name'], null, 'Název');
		$grid->addFilterButtons(['vats', $this->getParameter('country')]);

		return $grid;
	}

	public function createComponentNewForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addText('code', 'Kód');
		$form->addText('name', 'Název');
		$form->addInteger('orderCodeStartNumber', 'Počáteční číslo objednávek')
			->setDefaultValue(1)
			->setRequired()
			->setHtmlAttribute('data-info', 'Např.: pokud bude nastaveno na 5 tak první objednávka bude mít kód #X202100005.');

		$form->addSubmits();

		$form->onSuccess[] = function (AdminForm $form): void {
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
		$form->addText('priority', 'Priorita')
			->addRule($form::INTEGER)
			->setRequired()
			->setDefaultValue(10);
		$form->addSubmits(false, false);

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$this->vatRateRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('vats', 'vats', [], [$this->getParameter('vat')->country]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Země a DPH';
		$this->template->headerTree = [
			['Země a DPH'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderVats(Country $country): void
	{
		$this->template->headerLabel = 'Země a DPH: ' . $country->name;
		$this->template->headerTree = [
			['Země a DPH'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('vatGrid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Země a DPH', 'default'],
			['Nová položka'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Země a DPH', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(Country $country): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');

		$form->setDefaults($country->toArray());
	}

	public function renderVatDetail(VatRate $vat): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Výše DPH', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('vats', ['country' => $vat->country])];
		$this->template->displayControls = [$this->getComponent('vatForm')];
	}

	public function actionVatDetail(VatRate $vat): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('vatForm');

		$form->setDefaults($vat->toArray());
	}
}
