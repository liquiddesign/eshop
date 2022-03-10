<?php

declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Forms\Form;
use Nette\Utils\Arrays;
use Web\DB\SettingRepository;

class SettingsPresenter extends BackendPresenter
{
	/** @inject */
	public SettingRepository $settingsRepository;

	/**
	 * @var array<string|array<mixed>>
	 * Can be simple: ['settingKey' => 'inputLabel']
	 * Or complex: [
	 * 		'inputGroupLabel' => [[
	 * 			'key' => '...', //settings key,
	 * 			'label' => '...', //input label,
	 * 			'type' => '...', //input type (string,select,multi),
	 * 			'options' => [], //if select or multi you need to specify options,
	 * 			'prompt' => null|string, //prompt for select,
	 * 			'info' => null|string, // data-info for element,
	 * 			'onSave' => callable // custom callback called on save form,
	 * 	], ...]
	 * ]
	 */
	protected array $customSettings = [];

	/**
	 * @var array<string, callable> calleble gets $key, $prevValue, $newValue
	 */
	private array $customOnSaves = [];

	public function createComponentForm(): Form
	{
		$form = $this->formFactory->create();

		$basicSettings = false;

		foreach ($this->customSettings as $header => $settings) {
			if (\is_array($settings)) {
				$form->addGroup($header);

				foreach ($settings as $setting) {
					if ($setting['type'] === 'string') {
						$form->addText($setting['key'], $setting['label'])
							->setNullable()
							->setHtmlAttribute('data-info', $setting['info'] ?? null);
					} elseif ($setting['type'] === 'select') {
						$form->addSelect2($setting['key'], $setting['label'], $setting['options'])
							->setPrompt($setting['prompt'] ?? '- Nepřiřazeno -')
							->checkDefaultValue(false)
							->setHtmlAttribute('data-info', $setting['info'] ?? null);
					} elseif ($setting['type'] === 'multi') {
						$form->addMultiSelect2($setting['key'], $setting['label'], $setting['options'])
							->checkDefaultValue(false)
							->setHtmlAttribute('data-info', $setting['info'] ?? null);
					}

					if (!isset($setting['onSave'])) {
						continue;
					}

					$this->customOnSaves[$setting['key']] = $setting['onSave'];
				}
			} else {
				$basicSettings = true;
			}
		}

		if ($basicSettings) {
			$form->addGroup('Ostatní');

			foreach ($this->customSettings as $header => $settings) {
				if (!\is_array($settings)) {
					$form->addText($header, $settings)->setNullable();
				}
			}
		}

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			foreach ($values as $key => $value) {
				$setting = $this->settingsRepository->one(['name' => $key]);
				$value = \is_array($value) ? \implode(';', $value) : $value;

				if (isset($this->customOnSaves[$key])) {
					$this->customOnSaves[$key]($key, $setting ? $setting->value : null, $value);
				}

				if ($setting) {
					$setting->update(['value' => $value]);
				} else {
					$this->settingsRepository->createOne([
						'name' => $key,
						'value' => $value,
					]);
				}
			}

			$this->flashMessage('Nastavení uloženo', 'success');
			$this->redirect('default');
		};

		return $form;
	}

	public function actionDefault(): void
	{
		/** @var \Admin\Controls\AdminForm $form */
		$form = $this->getComponent('form');

		$keys = [];

		foreach ($this->customSettings as $key => $groupSettings) {
			if (\is_array($groupSettings)) {
				foreach ($groupSettings as $setting) {
					$keys[] = $setting['key'];
				}
			} else {
				$keys[] = $key;
			}
		}

		$defaults = [];
		$values = $this->settingsRepository->many()->setIndex('name')->toArrayOf('value');

		/**
		 * @var string $key
		 * @var string $value
		 */
		foreach ($values as $key => $value) {
			if (Arrays::contains($keys, $key) && $value) {
				$array = \explode(';', $value);
				$defaults[$key] = \count($array) > 1 ? $array : $value;
			}
		}

		try {
			$form->setDefaults($defaults);
		} catch (\InvalidArgumentException $e) {
		}
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Nastavení';
		$this->template->headerTree = [
			['Nastavení', 'default'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [$this->getComponent('form')];
	}
}
