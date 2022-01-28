<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\PricelistRepository;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Utils\Arrays;
use Web\DB\SettingRepository;

class ExportPresenter extends BackendPresenter
{
	/** @inject */
	public SettingRepository $settingsRepo;

	/** @inject */
	public PricelistRepository $priceListRepo;

	/** @inject */
	public Storage $storage;

	public function actionDefault(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('settingForm');

		$values = $this->settingsRepo->many()->setIndex('name')->toArrayOf('value');

		$keys = [
			'partnersExportPricelist',
			'heurekaExportPricelist',
			'zboziExportPricelist',
			'googleExportPricelist',
		];

		$defaults = [];

		/**
		 * @var string $key
		 * @var string $value
		 */
		foreach ($values as $key => $value) {
			if (Arrays::contains($keys, $key) && $value) {
				$defaults[$key] = \explode(';', $value);
			}
		}

		try {
			$form->setDefaults($defaults);
		} catch (\InvalidArgumentException $e) {
		}

		$this->template->headerTree = [
			['Exporty'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [];

		$this->template->exports = [
			[
				'name' => 'Export pro Partnery',
				'link' => $this->link('//:Eshop:Export:partnersExport'),
			],
			[
				'name' => 'Export pro Heureku',
				'link' => $this->link('//:Eshop:Export:heurekaExport'),
			],
			[
				'name' => 'Export pro Zboží',
				'link' => $this->link('//:Eshop:Export:zboziExport'),
			],
			[
				'name' => 'Export pro Google Nákupy',
				'link' => $this->link('//:Eshop:Export:googleExport'),
			],
		];

		/** @var \Web\DB\Setting|null $setting */
		$setting = $this->settingsRepo->many()->where('name', 'supportBoxApiKey')->first();

		if ($setting && $setting->value) {
			$this->template->exports[] =
				[
					'name' => 'Export pro SupportBox',
					'link' => $this->link('//:Eshop:Export:supportbox'),
					'detail' => 'Je nutné specifikovat e-mail zákazníka a API klíč SupportBoxu shodný se zadaným API klíčem v adminu.<br><br>
Příklad HTTP požadavku:<br>
GET /rajtiskaren/json/supportbox?email=test@lqd.cz HTTP/1.1<br>
Host: localhost:443<br>
Authorization: Basic fa331395e9c7ef794130d50fec5d6251<br>
',
				];
		}

		$this->template->setFile(__DIR__ . \DIRECTORY_SEPARATOR . 'templates' . \DIRECTORY_SEPARATOR . 'Export.default.latte');
	}

	public function createComponentSettingForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->removeComponent($form['uuid']);
		$form->addGroup('Ceníky');
		$form->addDataMultiSelect('partnersExportPricelist', 'Partneři', $this->priceListRepo->getArrayForSelect(false));
		$form->addDataMultiSelect('heurekaExportPricelist', 'Heureka', $this->priceListRepo->getArrayForSelect(false));
		$form->addDataMultiSelect('zboziExportPricelist', 'Zboží', $this->priceListRepo->getArrayForSelect(false));
		$form->addDataMultiSelect('googleExportPricelist', 'Google Nákupy', $this->priceListRepo->getArrayForSelect(false));

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			foreach ($values as $key => $value) {
				$setting = $this->settingsRepo->one(['name' => $key]);

				if ($setting) {
					$setting->update(['value' => \implode(';', $value)]);
				} else {
					$this->settingsRepo->createOne([
						'name' => $key,
						'value' => \implode(';', $value),
					]);
				}
			}

			$cache = new Cache($this->storage);
			$cache->clean([
				Cache::TAGS => ['export'],
			]);

			$this->flashMessage('Nastavení uloženo', 'success');
			$this->redirect('default');
		};

		return $form;
	}
}
