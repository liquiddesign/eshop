<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Eshop\DB\AddressRepository;
use Eshop\DB\OpeningHoursRepository;
use Eshop\DB\PickupPoint;
use Eshop\DB\PickupPointRepository;
use Eshop\DB\PickupPointType;
use Eshop\DB\PickupPointTypeRepository;
use Forms\Form;
use Nette\Utils\Arrays;
use Nette\Utils\Image;
use StORM\DIConnection;
use StORM\Entity;
use StORM\ICollection;

class PickupPointPresenter extends BackendPresenter
{
	public const TABS = [
		'points' => 'Místa',
		'types' => 'Typy',
	];

	public const WEEK_DAYS = [
		1 => 'Pondělí',
		2 => 'Úterý',
		3 => 'Středa',
		4 => 'Čtvrtek',
		5 => 'Pátek',
		6 => 'Sobota',
		7 => 'Neděle',
	];

	/** @inject */
	public PickupPointTypeRepository $pickupPointTypeRepo;

	/** @inject */
	public PickupPointRepository $pickupPointRepo;

	/** @inject */
	public OpeningHoursRepository $openingHoursRepo;

	/** @inject */
	public AddressRepository $addressRepository;

	/** @persistent */
	public string $tab = 'points';

	/** @persistent */
	public ?string $selectedPickupPoint = null;

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Výdejní místa';
		$this->template->headerTree = [
			['Výdejní místa'],
		];

		if ($this->tab === 'types') {
			$this->template->displayButtons = [$this->createNewItemButton('typeNew')];
			$this->template->displayControls = [$this->getComponent('typeGrid')];
		} elseif ($this->tab === 'points') {
			$this->template->displayButtons = [$this->createNewItemButton('pointNew')];
			$this->template->displayControls = [$this->getComponent('pointGrid')];
		}

		$this->template->tabs = self::TABS;
	}

	public function renderTypeNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Výdejní místa', 'default'],
			['Typy'],
			['Nová položka'],
		];

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('typeForm')];
	}

	public function actionTypeDetail(PickupPointType $pickupPointType): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('typeForm');

		$form->setDefaults($pickupPointType->toArray());
	}

	public function renderTypeDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Výdejní místa', 'default'],
			['Typy'],
			['Detail'],
		];

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('typeForm')];
	}

	public function renderPointNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Výdejní místa', 'default'],
			['Místa'],
			['Nová položka'],
		];

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('pointForm')];
	}

	public function actionPointDetail(PickupPoint $pickupPoint): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('pointForm');

		$form->setDefaults($pickupPoint->toArray());
		$form['addressContainer']->setDefaults($pickupPoint->address ? $pickupPoint->address->toArray() : []);
	}

	public function renderPointDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Výdejní místa', 'default'],
			['Místa'],
			['Detail'],
		];

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('pointForm')];
	}

	public function renderPointHours(?PickupPoint $pickupPoint): void
	{
		$pickupPoint ??= $this->pickupPointRepo->one($this->selectedPickupPoint, true);
		$this->selectedPickupPoint = $pickupPoint->getPK();

		$this->template->headerLabel = 'Otevírací doba místa: ' . $pickupPoint->name;
		$this->template->headerTree = [
			['Výdejní místa', 'default'],
			['Místa'],
			['Otevírací doba'],
		];

		$this->template->setFile(__DIR__ . \DIRECTORY_SEPARATOR . 'templates' . \DIRECTORY_SEPARATOR . 'PickupPoint.pointHours.latte');
	}

	public function createComponentTypeGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->pickupPointTypeRepo->many(), 20, 'priority');
		$grid->addColumnSelector();

		$grid->addColumnImage('imageFileName', PickupPointType::IMAGE_DIR);
		$grid->addColumnText('Název', 'name', '%s', 'name');

		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLinkDetail('typeDetail');
		$grid->addColumnActionDeleteSystemic();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected(null, false, function ($object) {
			return !$object->isSystemic();
		});

		$grid->addFilterTextInput('search', ['name_cs'], null, 'Název');
		$grid->addFilterButtons();

		$grid->onDelete[] = [$this, 'onDelete'];

		return $grid;
	}

	public function createComponentPointGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->pickupPointRepo->many(), 20, 'priority');
		$grid->addColumnSelector();

		$grid->addColumnImage('imageFileName', PickupPoint::IMAGE_DIR);
		$grid->addColumnText('Název', 'name', '%s', 'name');

		$grid->addColumnText('Adresa', ['address.street', 'address.city'], '%s, %s', 'address.street');
		$grid->addColumnText('Telefon', 'phone', '<a href="tel:%1$s"><i class="fa fa-phone-alt"></i> %1$s</a>', 'phone')->onRenderCell[] = [$grid, 'decoratorEmpty'];
		$grid->addColumnText('Email', 'email', '<a href="mailto:%1$s"><i class="far fa-envelope"></i> %1$s</a>', 'email')->onRenderCell[] = [$grid, 'decoratorEmpty'];
		$grid->addColumn('Typ místa', function (PickupPoint $object, $datagrid) {
			$link = $this->admin->isAllowed(':Eshop:Admin:PickupPoint:typeDetail') && $object->pickupPointType ?
				$datagrid->getPresenter()->link(':Eshop:Admin:PickupPoint:typeDetail', [$object->pickupPointType, 'backLink' => $this->storeRequest()]) : '#';

			return $object->pickupPointType ? "<a href='$link'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;" . $object->pickupPointType->name . "</a>" : '';
		});
		$grid->addColumnInputInteger('Priorita', 'priority', '', '', 'priority', [], true);
		$grid->addColumnInputCheckbox('<i title="Skryto" class="far fa-eye-slash"></i>', 'hidden', '', '', 'hidden');

		$grid->addColumnLink('pointHours', 'Otevírací doba');
		$grid->addColumnLinkDetail('pointDetail');
		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->addFilterTextInput('search', ['name_cs'], null, 'Název');

		if (\count($this->pickupPointTypeRepo->getArrayForSelect()) > 0) {
			$grid->addFilterDataMultiSelect(function (ICollection $source, $value): void {
				$source->where('fk_pickupPointType', $value);
			}, '', 'type', 'Typ', $this->pickupPointTypeRepo->getArrayForSelect(), ['placeholder' => '- Typ -']);
		}

		$grid->addFilterButtons();
		$grid->onDelete[] = [$this, 'onDelete'];

		return $grid;
	}

	public function createComponentSpecialHoursGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create(
			$this->openingHoursRepo->many()
				->where('fk_pickupPoint', ($this->getParameter('pickupPoint') ?: $this->pickupPointRepo->one($this->selectedPickupPoint, true))->getPK())
				->where('date IS NOT NULL'),
			20,
			'date',
		);
		$grid->addColumnSelector();

		$grid->addColumnText('Datum', "date|date:'d.m.Y'", '%s', 'date', ['class' => 'fit'])->onRenderCell[] = [$grid, 'decoratorNowrap'];

		$grid->addColumnInputTime('Otevřeno od', 'openFrom');
		$grid->addColumnInputTime('Pauza od', 'pauseFrom');
		$grid->addColumnInputTime('Pauza do', 'pauseTo');
		$grid->addColumnInputTime('Otevřeno do', 'openTo');

		$grid->addColumnActionDelete();

		$grid->addButtonSaveAll();
		$grid->addButtonDeleteSelected();

		$grid->onDelete[] = [$this, 'onDelete'];

		return $grid;
	}

	public function createComponentTypeForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		$form->addLocaleText('name', 'Název');

		$imagePicker = $form->addImagePicker('imageFileName', 'Obrázek', [
			PickupPointType::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			PickupPointType::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			PickupPointType::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);

		$imagePicker->onDelete[] = function (array $directories, $filename): void {
			$this->onDelete($this->getParameter('pickupPointType'));
			$this->pickupPointRepo->clearCache();
			$this->redirect('this');
		};

		$form->addInteger('priority', 'Priorita')
			->setDefaultValue(10)
			->setRequired();

		$form->addSubmits(!$this->getParameter('pickupPointType'));

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			$this->createImageDirs(PickupPointType::IMAGE_DIR);

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$values['imageFileName'] = $form['imageFileName']->upload($values['uuid'] . '.%2$s');

			$pickupPointType = $this->pickupPointTypeRepo->syncOne($values);
			$this->pickupPointRepo->clearCache();

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('typeDetail', 'default', [$pickupPointType]);
		};

		return $form;
	}

	public function createComponentPointForm(): AdminForm
	{
		$form = $this->formFactory->create(true);

		$form->addText('code', 'Kód')->setRequired();
		$form->addLocaleText('name', 'Název');

		$imagePicker = $form->addImagePicker('imageFileName', 'Obrázek', [
			PickupPoint::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'origin' => null,
			PickupPoint::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'detail' => static function (Image $image): void {
				$image->resize(600, null);
			},
			PickupPoint::IMAGE_DIR . \DIRECTORY_SEPARATOR . 'thumb' => static function (Image $image): void {
				$image->resize(300, null);
			},
		]);

		$imagePicker->onDelete[] = function (array $directories, $filename): void {
			$this->onDelete($this->getParameter('pickupPoint'));
			$this->redirect('this');
		};

		$form->addLocaleText('description', 'Popis');
		$form->addCheckbox('hidden', 'Skryto');

		$addressContainer = $form->addContainer('addressContainer');
		$addressContainer->addHidden('uuid');
		$addressContainer->addText('street', 'Ulice')->setRequired();
		$addressContainer->addText('city', 'Město')->setRequired();
		$addressContainer->addText('zipcode', 'PSČ')->setNullable();
		$addressContainer->addText('state', 'Stát')->setNullable();

		$form->addText('gpsN', 'GPS souřadnice N')
			->setNullable(true)
			->addRule(Form::FLOAT)
			->setHtmlAttribute('data-info', 'GPS souřadnice pro zobrazení bodu na mapě. Např.: 49,1920700');
		$form->addText('gpsE', 'GPS souřadnice E')
			->setNullable(true)
			->addRule(Form::FLOAT)
			->setHtmlAttribute('data-info', 'GPS souřadnice pro zobrazení bodu na mapě. Např.: 16,6125203');

		$form->addText('phone', 'Telefon')->setNullable();
		$form->addText('email', 'Email')->setNullable();

		$form->addDataSelect('pickupPointType', 'Typ výdejního místa', $this->pickupPointTypeRepo->getArrayForSelect())->setRequired();

		$form->addSubmits(!$this->getParameter('pickupPoint'));

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$values['addressContainer']['uuid']) {
				$values['addressContainer']['uuid'] = DIConnection::generateUuid();
			}

			$address = $this->addressRepository->syncOne(Arrays::pick($values, 'addressContainer'));
			$values['address'] = $address->getPK();

			$this->createImageDirs(PickupPoint::IMAGE_DIR);

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			//@TODO refaktor
			if ($form['imageFileName']->isFilled()) {
				$values['imageFileName'] = $form['imageFileName']->upload($values['uuid'] . '.%2$s');
			} else {
				unset($values['imageFileName']);
			}

			$pickupPoint = $this->pickupPointRepo->syncOne($values);
			$this->pickupPointRepo->clearCache();

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('pointDetail', 'default', [$pickupPoint]);
		};

		return $form;
	}

	public function renderSpecialHoursNew(): void
	{
		/** @var \Eshop\DB\PickupPoint $pickupPoint */
		$pickupPoint = $this->pickupPointRepo->one($this->selectedPickupPoint, true);

		$this->template->headerLabel = 'Nová mimořádná otevírací doba místa: ' . $pickupPoint->name;
		$this->template->headerTree = [
			['Výdejní místa', 'default'],
			['Místa'],
			['Otevírací doba', 'pointHours'],
			['Nová položka'],
		];

		$this->template->displayButtons = [$this->createBackButton('pointHours')];
		$this->template->displayControls = [$this->getComponent('specialHoursForm')];
	}

	public function createComponentSpecialHoursForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addText('date', 'Datum')->setHtmlType('date')->setRequired();
		$form->addText('openFrom', 'Otevřeno od')->setHtmlType('time')->setNullable();
		$form->addText('pauseFrom', 'Pauza od')->setHtmlType('time')->setNullable();
		$form->addText('pauseTo', 'Pauza do')->setHtmlType('time')->setNullable();
		$form->addText('openTo', 'Otevřeno do')->setHtmlType('time')->setNullable();

		$form->onValidate[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$this->openingHoursRepo->many()
				->where('date', $values['date'])
				->first()
			) {
				return;
			}

			$form['date']->addError('Mimořádná otevírací doba pro tento den již existuje!');
		};

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$values['pickupPoint'] = $this->selectedPickupPoint;

			$this->openingHoursRepo->syncOne($values);
			$this->pickupPointRepo->clearCache();

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('', 'pointHours');
		};


		$form->addSubmits(true, false);

		return $form;
	}

	public function createComponentHoursForm(): AdminForm
	{
		$form = $this->formFactory->create();

		/** @var \Eshop\DB\PickupPoint $pickupPoint */
		$pickupPoint = $this->getParameter('pickupPoint') ?: $this->pickupPointRepo->one($this->selectedPickupPoint, true);

		foreach ($this::WEEK_DAYS as $key => $day) {
			/** @var \Eshop\DB\OpeningHours $openingHour */
			$openingHour = $this->openingHoursRepo->many()
				->where('fk_pickupPoint', $pickupPoint->getPK())
				->where('day', $key)
				->where('date IS NULL')
				->first();

			$form->addHidden("$key" . '_uuid')->setDefaultValue($openingHour ? $openingHour->getPK() : null);
			$form->addText("$key" . '_openFrom', $day)->setHtmlType('time')->setDefaultValue($openingHour->openFrom ?? null)->setNullable();
			$form->addText("$key" . '_pauseFrom', $day)->setHtmlType('time')->setDefaultValue($openingHour->pauseFrom ?? null)->setNullable();
			$form->addText("$key" . '_pauseTo', $day)->setHtmlType('time')->setDefaultValue($openingHour->pauseTo ?? null)->setNullable();
			$form->addText("$key" . '_openTo', $day)->setHtmlType('time')->setDefaultValue($openingHour->openTo ?? null)->setNullable();
		}

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form) use ($pickupPoint): void {
			$values = $form->getValues('array');

			foreach (\array_keys($this::WEEK_DAYS) as $key) {
				if (!$values[$key . '_uuid']) {
					$values[$key . '_uuid'] = DIConnection::generateUuid();
				}

				$this->openingHoursRepo->syncOne([
					'uuid' => $values[$key . '_uuid'],
					'openFrom' => $values[$key . '_openFrom'],
					'pauseFrom' => $values[$key . '_pauseFrom'],
					'pauseTo' => $values[$key . '_pauseTo'],
					'openTo' => $values[$key . '_openTo'],
					'pickupPoint' => $pickupPoint->getPK(),
					'day' => $key,
				]);
			}

			$this->pickupPointRepo->clearCache();
			$this->flashMessage('Uloženo', 'success');
			$this->redirect('this');
		};

		return $form;
	}

	public function onDelete(Entity $object): void
	{
		parent::onDelete($object);

		$this->pickupPointRepo->clearCache();
	}
}
