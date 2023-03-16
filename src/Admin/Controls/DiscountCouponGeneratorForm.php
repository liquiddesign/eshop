<?php

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\Discount;
use Eshop\DB\DiscountCondition;
use Eshop\DB\DiscountConditionRepository;
use Eshop\DB\DiscountCouponRepository;
use Eshop\FormValidators;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Utils\Arrays;
use Nette\Utils\Random;

class DiscountCouponGeneratorForm extends Control
{
	public function __construct(
		AdminFormFactory $adminFormFactory,
		CurrencyRepository $currencyRepository,
		DiscountCouponRepository $discountCouponRepository,
		DiscountConditionRepository $discountConditionRepository,
		Discount $discount,
	) {
		$form = $adminFormFactory->create();

		$form->addInteger('generatorCount', 'Počet kuponů ke generování')->setRequired();

		$form->addGroup('Nastavení kupónu');
		$form->addText('codePrefix', 'Prefix kódu')->setNullable();
		$form->addText('label', 'Popisek')->setNullable();
		$form->addText('discountPct', 'Sleva (%)')->setNullable()->addRule($form::FLOAT)->addRule([FormValidators::class, 'isPercent'], 'Hodnota není platné procento!');
		$form->addInteger('usageLimit', 'Maximální počet použití')->setNullable()->addCondition($form::FILLED)->toggle('frm-couponsForm-usagesCount-toogle');
		$form->addInteger('usagesCount', 'Aktuální počet použití')
			->setDefaultValue(0)
			->setHtmlAttribute('data-info', 'Automaticky se zvyšuje při použití kupónu.');
		$form->addGroup('Absolutní sleva');
		$form->addDataSelect('currency', 'Měna', $currencyRepository->getArrayForSelect());
		$form->addText('discountValue', 'Sleva')->setNullable()->setHtmlAttribute('data-info', 'Zadejte hodnotu ve zvolené měně.')->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('discountValueVat', 'Sleva s DPH')->setNullable()->setHtmlAttribute('data-info', 'Zadejte hodnotu ve zvolené měně.')->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('minimalOrderPrice', 'Minimální cena objednávky')->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('maximalOrderPrice', 'Maximální cena objednávky')->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addGroup('Export');
		$form->addCheckbox('targitoExport', 'Targito');
		$form->addGroup('Podmínky');
		$form->addSelect('conditionsType', 'Typ porovnávání', ['and' => 'Všechny podmínky musí platit', 'or' => 'Alespoň jedna podmínka musí platit']);

		$conditionsContainer = $form->addContainer('conditionsContainer');

		$this->monitor(Presenter::class, function (Presenter $presenter) use ($conditionsContainer): void {
			for ($i = 0; $i < 6; $i++) {
				$conditionsContainer->addSelect("cartCondition_$i", null, DiscountCondition::CART_CONDITIONS);
				$conditionsContainer->addSelect("quantityCondition_$i", null, DiscountCondition::QUANTITY_CONDITIONS);
				$conditionsContainer->addMultiSelect2("products_$i", null, [], [
					'ajax' => [
						'url' => $presenter->link('getProductsForSelect2!'),
					],
					'placeholder' => 'Zvolte produkty',
				])->checkDefaultValue(false);
			}
		});

		$form->addSubmits(false, false);

		/** @var \Nette\Forms\Controls\SubmitButton $submitButton */
		$submitButton = $form['submit'];

		$submitButton->setCaption('Generovat');

		$form->onValidate[] = function (AdminForm $form): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues('array');

			if ($values['generatorCount'] > 0 && $values['generatorCount'] <= 10000) {
				return;
			}

			/** @var \Nette\Forms\Controls\TextInput $generatorCountInput */
			$generatorCountInput = $form['generatorCount'];

			$generatorCountInput->addError('Zadejte číslo větší jak 0 a menší jak 10000');
		};

		$form->onSuccess[] = function (AdminForm $form) use ($discountCouponRepository, $discount, $discountConditionRepository): void {
			$values = $form->getValues('array');
			$data = $form->getHttpData();

			$values['discount'] = $discount->getPK();

			if ($values['usagesCount'] < 0) {
				$values['usagesCount'] = 0;
			}

			$codePrefix = Arrays::pick($values, 'codePrefix');
			/** @var int<1, 10000> $generatorCount */
			$generatorCount = Arrays::pick($values, 'generatorCount');
			/** @var array<mixed> $conditions */
			$conditions = Arrays::pick($values, 'conditionsContainer');

			$existingCoupons = $discountCouponRepository->many()->setIndex('this.code')->where('this.fk_discount', $discount->getPK())->toArray();

			for ($j = 0; $j < $generatorCount; $j++) {
				do {
					$newCouponCode = $codePrefix . Random::generate(10, '0-9A-Z');
					$temp = $existingCoupons[$newCouponCode] ?? null;
				} while ($temp);

				$values['code'] = $newCouponCode;

				$discountCoupon = $discountCouponRepository->createOne($values);
				$existingCoupons[$discountCoupon->getPK()] = $discountCoupon;

				$discountConditionRepository->many()->where('fk_discountCoupon', $discountCoupon->getPK())->delete();

				for ($i = 0; $i < 6; $i++) {
					if (!isset($data['conditionsContainer']["products_$i"])) {
						continue;
					}

					$discountConditionRepository->syncOne([
						'cartCondition' => $conditions["cartCondition_$i"],
						'quantityCondition' => $conditions["quantityCondition_$i"],
						'products' => $data['conditionsContainer']["products_$i"],
						'discountCoupon' => $discountCoupon,
					]);
				}
			}

			$form->getPresenter()->flashMessage('Kupóny úspěšně vygenerovány', 'success');
			$form->getPresenter()->redirect('this', [$discount]);
		};

		$this->addComponent($form, 'form');
	}

	public function render(): void
	{
		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render(__DIR__ . '/discountCouponGeneratorForm.latte');
	}
}
