<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\Currency;
use Eshop\DB\CurrencyRepository;
use Forms\Form;

class CurrencyPresenter extends BackendPresenter
{
	protected const CONFIGURATIONS = [
		'loyaltyProgram' => false,
	];

	#[\Nette\DI\Attributes\Inject]
	public CurrencyRepository $currencyRepository;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->currencyRepository->many(), 20, 'code', 'ASC', true);

		$grid->addColumnText('Kód', 'code', '%s', 'code', ['class' => 'fit']);
		$grid->addColumnText('Symbol', 'symbol', '%s', 'symbol', ['class' => 'fit']);
		$grid->addColumnText('Název', 'name', '%s', 'name');
		
		$grid->addColumnText('Kurz', 'convertRatio', '%s', 'convertRatio');

		$grid->addColumnLinkDetail('detail');

		$grid->addFilterTextInput('search', ['code'], null, 'Kód');
		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentNewForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addText('code', 'Kód')->setHtmlAttribute('readonly', 'readonly');
		$form->addText('symbol', 'Symbol')->setHtmlAttribute('readonly', 'readonly');

		$form->addGroup('Formát');
		$form->addInteger('formatDecimals', 'Počet desetinných míst');
		$form->addText('formatDecimalSeparator', 'Oddělovač desetinných míst')->setMaxLength(1);
		$form->addText('formatThousandsSeparator', 'Oddělovač tisícovek')->setMaxLength(1);
		$form->addSelect('formatSymbolPosition', 'Pozice symbolu', [
			'before' => 'Před',
			'after' => 'Za',
		]);
		$form->addGroup('Přepočet');
		$form->addText('convertRatio', 'Kurz')->setHtmlAttribute('data-info', 'Zádávejte násobitel. Např. CZK -> EUR = 0.04')
			->setNullable()->addCondition(Form::FILLED)->addRule($form::FLOAT);

		$currency = $this->getParameter('currency');
		$currencies = $this->currencyRepository->getArrayForSelect();

		if ($currency) {
			unset($currencies[$currency->getPK()]);
		}

		$form->addDataSelect('convertCurrency', 'Vztažen k měně', $currencies);
		$form->addInteger('calculationPrecision', 'Zaokrouhlení při konverzi')
			->setRequired()
			->setHtmlAttribute('data-info', 'Počet desetiných míst, např. při výpočtu slev');
		$form->addCheckbox('enableConversion', 'Konverze')->setHtmlAttribute('data-info', 'Nebudou fungovat ceníky a zvolí se přepočet');

		if (isset($this::CONFIGURATIONS['loyaltyProgram']) && $this::CONFIGURATIONS['loyaltyProgram']) {
			$form->addCheckbox('cashback', 'Cashback měna')->setHtmlAttribute('data-info', 'Měna může být použita pro věrnostní program.');
		}

		$form->addSubmits();

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$this->currencyRepository->syncOne($values);

			$this->flashMessage('Uloženo', 'success');

			$form->processRedirect('this', 'default');
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Měny';
		$this->template->headerTree = [
			['Měny', 'default'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderDetail(Currency $currency): void
	{
		unset($currency);

		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Měny', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('newForm')];
	}

	public function actionDetail(Currency $currency): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('newForm');

		$form->setDefaults($currency->toArray());
	}
}
