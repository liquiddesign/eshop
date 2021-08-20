<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\PricelistRepository;
use Nette\Utils\Arrays;
use Web\DB\SettingRepository;
use Nette\Caching\Cache;
use Nette\Caching\Storage;

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
		/** @var AdminForm $form */
		$form = $this->getComponent('settingForm');

		$values = $this->settingsRepo->many()->setIndex('name')->toArrayOf('value');

		$keys = [
			'heurekaExportPricelist',
			'zboziExportPricelist',
			'googleExportPricelist'
		];

		$defaults = [];

		foreach ($values as $key => $value) {
			if (Arrays::contains($keys, $key) && $value && \count(\explode(';', $value)) > 0) {
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
			(object)[
				'name' => 'Export pro partnery',
				'link' => $this->link('//:Eshop:Export:partnersExport')
			],
			(object)[
				'name' => 'Export pro Heureku',
				'link' => $this->link('//:Eshop:Export:heurekaExport')
			],
			(object)[
				'name' => 'Export pro Zboží',
				'link' => $this->link('//:Eshop:Export:zboziExport')
			],
			(object)[
				'name' => 'Export pro Google Nákupy',
				'link' => $this->link('//:Eshop:Export:googleExport')
			]
		];

		if ($this->settingsRepo->many()->where('name', 'supportBoxApiKey')->first()) {
			$this->template->exports[] =
				(object)[
					'name' => 'Export pro SupportBox',
					'link' => $this->link('//:Eshop:Export:supportbox')
				];
		}

		$this->template->setFile(__DIR__ . \DIRECTORY_SEPARATOR . 'templates' . \DIRECTORY_SEPARATOR . 'Export.default.latte');
	}

	public function createComponentSettingForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->removeComponent($form['uuid']);
		$form->addGroup('');
		$form->addDataMultiSelect('heurekaExportPricelist', 'Ceník exportu pro Heureku', $this->priceListRepo->getArrayForSelect(false));
		$form->addDataMultiSelect('zboziExportPricelist', 'Ceník exportu pro Zboží', $this->priceListRepo->getArrayForSelect(false));
		$form->addDataMultiSelect('googleExportPricelist', 'Ceník exportu pro Google Nákupy', $this->priceListRepo->getArrayForSelect(false));

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			foreach ($values as $key => $value) {
				$setting = $this->settingsRepo->one(['name' => $key]);

				if ($setting) {
					$setting->update(['value' => \implode(';', $value)]);
				} else {
					$this->settingsRepo->createOne([
						'name' => $key,
						'value' => \implode(';', $value)
					]);
				}
			}

			$cache = new Cache($this->storage);
			$cache->clean([
				Cache::TAGS => ["export"],
			]);

			$this->flashMessage('Nastavení uloženo', 'success');
			$this->redirect('default');
		};

		return $form;
	}

}
