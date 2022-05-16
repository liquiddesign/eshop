<?php

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\BackendPresenter;
use Eshop\DB\ApiGeneratorDiscountCoupon;
use Eshop\DB\ApiGeneratorDiscountCouponRepository;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\DiscountCondition;
use Eshop\DB\DiscountConditionRepository;
use Eshop\DB\DiscountRepository;
use Eshop\FormValidators;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Utils\Arrays;

class ApiGeneratorDiscountCouponForm extends Control
{
	public function __construct(
		AdminFormFactory $adminFormFactory,
		CustomerRepository $customerRepository,
		CurrencyRepository $currencyRepository,
		DiscountConditionRepository $discountConditionRepository,
		DiscountRepository $discountRepository,
		ApiGeneratorDiscountCouponRepository $apiGeneratorDiscountCouponRepository,
		?ApiGeneratorDiscountCoupon $apiGeneratorDiscountCoupon
	) {
		$form = $adminFormFactory->create();

		$codeInput = $form->addText('code', 'Kód')->setRequired();

		$this->monitor(Presenter::class, function (BackendPresenter $presenter) use ($codeInput, $apiGeneratorDiscountCoupon): void {
			if ($apiGeneratorDiscountCoupon) {
				try {
					$url = $presenter->link('//:Eshop:ApiGenerator:default', ['generator' => 'discountCoupon', 'code' => $apiGeneratorDiscountCoupon->code]);

					$codeInput->setHtmlAttribute('data-info', "Odkaz pro generování: <a class='ml-2' href='$url' target='_blank'><i class='fas fa-external-link-alt mr-1'></i>$url</a>");
				} catch (\Throwable $e) {
				}
			}
		});

		$form->addText('label', 'Popisek');
		$form->addDatetime('validFrom', 'Platný od')->setNullable();
		$form->addDatetime('validTo', 'Platný do')->setNullable();
		$form->addSelect2('exclusiveCustomer', 'Jen pro zákazníka', $customerRepository->getArrayForSelect())->setPrompt('Žádný');
		$form->addText('discountPct', 'Sleva (%)')->addRule($form::FLOAT)->addRule([FormValidators::class, 'isPercent'], 'Hodnota není platné procento!');
		$form->addInteger('apiUsageLimit', 'Maximální počet použití')->setNullable();
		$form->addInteger('apiUsagesCount', 'Aktuální počet použití')
			->setDefaultValue(0)
			->setHtmlAttribute('data-info', 'Automaticky se zvyšuje při použití kódu.');
		$form->addInteger('usageLimit', 'Maximální počet použití')->setNullable()->addCondition($form::FILLED)->toggle('frm-couponsForm-usagesCount-toogle');
		$form->addGroup('Absolutní sleva');
		$form->addDataSelect('currency', 'Měna', $currencyRepository->getArrayForSelect())->setRequired();
		$form->addText('discountValue', 'Sleva')->setHtmlAttribute('data-info', 'Zadejte hodnotu ve zvolené měně.')->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('discountValueVat', 'Sleva s DPH')->setHtmlAttribute('data-info', 'Zadejte hodnotu ve zvolené měně.')->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addSelect2('discount', 'Akce', $discountRepository->getArrayForSelect())->setRequired();
		$form->addText('minimalOrderPrice', 'Minimální cena objednávky')->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('maximalOrderPrice', 'Maximální cena objednávky')->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->bind($apiGeneratorDiscountCouponRepository->getStructure());
		$form->addGroup('Podmínky');
		$form->addSelect('conditionsType', 'Typ porovnávání', ['and' => 'Všechny podmínky musí platit', 'or' => 'Alespoň jedna podmínka musí platit']);

		$conditionsContainer = $form->addContainer('conditionsContainer');

		$this->monitor(Presenter::class, function (Presenter $presenter) use ($conditionsContainer, $discountConditionRepository, $apiGeneratorDiscountCoupon): void {
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

			if (!$apiGeneratorDiscountCoupon) {
				return;
			}

			$conditions = $discountConditionRepository->many()->where('fk_apiGeneratorDiscountCoupon', $apiGeneratorDiscountCoupon->getPK());

			$i = 0;

			/** @var \Eshop\DB\DiscountCondition $condition */
			foreach ($conditions as $condition) {
				/** @var \Nette\Forms\Controls\MultiSelectBox $productsInput */
				$productsInput = $conditionsContainer["products_$i"];
				/** @var \Nette\Forms\Controls\SelectBox $cartConditionInput */
				$cartConditionInput = $conditionsContainer["cartCondition_$i"];
				/** @var \Nette\Forms\Controls\SelectBox $quantityConditionInput */
				$quantityConditionInput = $conditionsContainer["quantityCondition_$i"];

				$presenter->template->select2AjaxDefaults[$productsInput->getHtmlId()] = $condition->products->toArrayOf('name');
				$cartConditionInput->setDefaultValue($condition->cartCondition);
				$quantityConditionInput->setDefaultValue($condition->quantityCondition);

				$i++;

				if ($i === 6) {
					break;
				}
			}
		});

		$form->addSubmits(!$apiGeneratorDiscountCoupon);

		$form->onSuccess[] = function (AdminForm $form) use ($apiGeneratorDiscountCoupon, $discountConditionRepository, $apiGeneratorDiscountCouponRepository): void {
			$values = $form->getValues('array');
			$data = $form->getHttpData();

			if ($values['apiUsagesCount'] < 0) {
				$values['apiUsagesCount'] = 0;
			}

			$conditions = Arrays::pick($values, 'conditionsContainer');

			$apiGeneratorDiscountCoupon = $apiGeneratorDiscountCouponRepository->syncOne($values, null, true);

			$discountConditionRepository->many()->where('fk_apiGeneratorDiscountCoupon', $apiGeneratorDiscountCoupon->getPK())->delete();

			for ($i = 0; $i < 6; $i++) {
				if (!isset($data['conditionsContainer']["products_$i"])) {
					continue;
				}

				$discountConditionRepository->syncOne([
					'cartCondition' => $conditions["cartCondition_$i"],
					'quantityCondition' => $conditions["quantityCondition_$i"],
					'products' => $data['conditionsContainer']["products_$i"],
					'apiGeneratorDiscountCoupon' => $apiGeneratorDiscountCoupon->getPK(),
				]);
			}

			$this->flashMessage('Uloženo', 'success');

			$form->processRedirect('detail', 'default', [$apiGeneratorDiscountCoupon]);
		};

		$this->addComponent($form, 'form');
	}

	public function render(): void
	{
		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render(__DIR__ . '/apiGeneratorDiscountCouponForm.latte');
	}
}
