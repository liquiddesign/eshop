<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use App\Admin\Controls\AdminForm;
use App\Admin\Controls\AdminFormFactory;
use Eshop\DB\PricelistRepository;
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
		$form->setDefaults($values);

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

		$this->template->setFile(__DIR__. \DIRECTORY_SEPARATOR . 'templates' . \DIRECTORY_SEPARATOR . 'Export.default.latte');
	}

	public function createComponentSettingForm(): AdminForm
	{
		$form = $this->formFactory->create();

		$form->addGroup('');
		$form->addDataSelect('heurekaExportPricelist', 'Ceník exportu pro Heureku', $this->priceListRepo->getArrayForSelect())->setPrompt('Žádný');
		$form->addDataSelect('zboziExportPricelist', 'Ceník exportu pro Zboží', $this->priceListRepo->getArrayForSelect())->setPrompt('Žádný');
		$form->addDataSelect('googleExportPricelist', 'Ceník exportu pro Google Nákupy', $this->priceListRepo->getArrayForSelect())->setPrompt('Žádný');

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form) {
			$values = $form->getValues('array');

			foreach ($values as $key => $value) {
				$setting = $this->settingsRepo->one(['name' => $key]);

				if ($setting) {
					$setting->update(['value' => $value]);
				} else {
					$this->settingsRepo->createOne([
						'name' => $key,
						'value' => $value
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
