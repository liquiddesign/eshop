<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\BackendPresenter;
use Admin\Controls\AdminForm;
use Eshop\DB\AttributeRepository;
use Eshop\DB\CategoryTypeRepository;
use Eshop\DB\PricelistRepository;
use Eshop\DB\RelatedTypeRepository;
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
	public CategoryTypeRepository $categoryTypeRepository;

	/** @inject */
	public RelatedTypeRepository $relatedTypeRepository;

	/** @inject */
	public AttributeRepository $attributeRepository;

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
			'heurekaCategoryTypeToParse',
			'zboziExportPricelist',
			'zboziCategoryTypeToParse',
			'zboziGroupRelation',
			'googleExportPricelist',
			'googleColorAttribute',
			'googleHighlightsAttribute',
			'googleHighlightsMutation',
			'googleSalePricelist',
		];

		$defaults = [];

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

		$this->template->headerTree = [
			['Exporty'],
		];
		$this->template->displayButtons = [];
		$this->template->displayControls = [];

		$primaryMutation = Arrays::first(\array_keys($this->settingsRepo->getConnection()->getAvailableMutations()));

		$this->template->exports = [
			[
				'name' => 'Export pro Partnery',
				'link' => $this->link('//:Eshop:Export:partnersExport', ['lang' => $primaryMutation]),
			],
			[
				'name' => 'Export pro Heureku',
				'link' => $this->link('//:Eshop:Export:heurekaExport', ['lang' => $primaryMutation]),
			],
			[
				'name' => 'Export pro Zboží',
				'link' => $this->link('//:Eshop:Export:zboziExport', ['lang' => $primaryMutation]),
			],
			[
				'name' => 'Export pro Google Nákupy',
				'link' => $this->link('//:Eshop:Export:googleExport', ['lang' => $primaryMutation]),
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
GET https://vasprojekt.cz/json/supportbox?email=test@lqd.cz HTTP/1.1<br>
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

		$form->addGroup('Heuréka');
		$form->addDataSelect('heurekaCategoryTypeToParse', 'Typ kategorií', $this->categoryTypeRepository->getArrayForSelect())->setPrompt('- Nepřiřazeno -');

		$form->addGroup('Zboží');
		$form->addDataSelect('zboziCategoryTypeToParse', 'Typ kategorií', $this->categoryTypeRepository->getArrayForSelect())->setPrompt('- Nepřiřazeno -');
		$form->addDataSelect('zboziGroupRelation', 'Typ vazby pro ITEMGROUP_ID', $this->relatedTypeRepository->getArrayForSelect())->setPrompt('- Nepřiřazeno -');

		$form->addGroup('Google');
		$form->addSelect2('googleColorAttribute', 'Atribut pro tag Barva [color]', $this->attributeRepository->getArrayForSelect())->setPrompt('- Nepřiřazeno -')
			->setHtmlAttribute('data-info', 'Pro tag se použijí hodnoty atributu přiřazené danému produktu (max 3).');
		$form->addSelect2('googleHighlightsAttribute', 'Atribut pro tag Představení produktu [product_highlight]', $this->attributeRepository->getArrayForSelect())->setPrompt('- Nepřiřazeno -')
		->setHtmlAttribute('data-info', 'Pro tag se použijí hodnoty atributu přiřazené danému produktu (max 10).');
		$form->addSelect2('googleSalePricelist', 'Ceník pro tag Cena v akci [sale_price]', $this->priceListRepo->getArrayForSelect())->setPrompt('- Nepřiřazeno -')
			->setHtmlAttribute('data-info', 'Zvolený ceník se použije pro zobrazení ceny v akci, pokud je nižší než cena dle zvolených ceníků pro export.');

		$mutations = \array_keys($this->attributeRepository->getConnection()->getAvailableMutations());

		$form->addSelect2(
			'googleHighlightsMutation',
			'Jazyk tagu Představení produktu [product_highlight]',
			\array_combine($mutations, $mutations),
		)->setPrompt('- Primární -');

		$form->addSubmit('submit', 'Uložit');

		$form->onSuccess[] = function (AdminForm $form): void {
			$values = $form->getValues('array');

			foreach ($values as $key => $value) {
				$setting = $this->settingsRepo->one(['name' => $key]);

				if ($setting) {
					$setting->update(['value' => \is_array($value) ? \implode(';', $value) : $value]);
				} else {
					$this->settingsRepo->createOne([
						'name' => $key,
						'value' => \is_array($value) ? \implode(';', $value) : $value,
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
