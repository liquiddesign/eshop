<?php

namespace Eshop\Admin\Controls;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminFormFactory;
use Eshop\BackendPresenter;
use Eshop\DB\CurrencyRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\Discount;
use Eshop\DB\DiscountCondition;
use Eshop\DB\DiscountConditionRepository;
use Eshop\DB\DiscountCoupon;
use Eshop\DB\DiscountCouponRepository;
use Eshop\FormValidators;
use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Utils\Arrays;
use Web\DB\PageRepository;

class DiscountCouponForm extends Control
{
	public function __construct(
		AdminFormFactory $adminFormFactory,
		CustomerRepository $customerRepository,
		CurrencyRepository $currencyRepository,
		DiscountCouponRepository $discountCouponRepository,
		DiscountConditionRepository $discountConditionRepository,
		PageRepository $pageRepository,
		?DiscountCoupon $discountCoupon,
		?Discount $discount = null
	) {
		$form = $adminFormFactory->create();

		$codeInput = $form->addText('code', 'Kód')->setRequired();

		$this->monitor(Presenter::class, function (BackendPresenter $presenter) use ($codeInput, $discountCoupon, $pageRepository): void {
			if ($discountCoupon) {
				$url = $presenter->link('//:Eshop:Checkout:cart', ['coupon' => $discountCoupon->code]);

				$codeInput->setHtmlAttribute('data-info', "Odkaz pro vložení: <a class='ml-2' href='$url' target='_blank'><i class='fas fa-external-link-alt'></i>$url</a>");
			}
		});

		$form->addText('label', 'Popisek');
		$form->addDataSelect('exclusiveCustomer', 'Jen pro zákazníka', $customerRepository->getArrayForSelect())->setPrompt('Žádný');
		$form->addText('discountPct', 'Sleva (%)')->addRule($form::FLOAT)->addRule([FormValidators::class, 'isPercent'], 'Hodnota není platné procento!');
		$form->addInteger('usageLimit', 'Maximální počet použití')->setNullable()->addCondition($form::FILLED)->toggle('frm-couponsForm-usagesCount-toogle');
		$form->addInteger('usagesCount', 'Aktuální počet použití')
			->setDefaultValue(0)
			->setHtmlAttribute('data-info', 'Automaticky se zvyšuje při použití kupónu.');
		$form->addGroup('Absolutní sleva');
		$form->addDataSelect('currency', 'Měna', $currencyRepository->getArrayForSelect());
		$form->addText('discountValue', 'Sleva')->setHtmlAttribute('data-info', 'Zadejte hodnotu ve zvolené měně.')->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('discountValueVat', 'Sleva s DPH')->setHtmlAttribute('data-info', 'Zadejte hodnotu ve zvolené měně.')->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addHidden('discount', isset($discount) ? (string)$discount : $discountCoupon->getValue('discount'));
		$form->addText('minimalOrderPrice', 'Minimální cena objednávky')->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->addText('maximalOrderPrice', 'Maximální cena objednávky')->setNullable()->addCondition($form::FILLED)->addRule($form::FLOAT);
		$form->bind($discountCouponRepository->getStructure());
		$form->addGroup('Podmínky');
		$form->addSelect('conditionsType', 'Typ porovnávání', ['and' => 'Všechny podmínky musí platit', 'or' => 'Alespoň jedna podmínka musí platit']);

		$conditionsContainer = $form->addContainer('conditionsContainer');

		$this->monitor(Presenter::class, function (Presenter $presenter) use ($conditionsContainer, $discountConditionRepository, $discountCoupon): void {
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

			if (!$discountCoupon) {
				return;
			}

			$conditions = $discountConditionRepository->many()->where('fk_discountCoupon', $discountCoupon->getPK());

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

		$form->addSubmits(!$discountCoupon);

		$form->onSuccess[] = function (AdminForm $form) use ($discountCouponRepository, $discount, $discountConditionRepository): void {
			$values = $form->getValues('array');
			$data = $form->getHttpData();

			if ($values['usagesCount'] < 0) {
				$values['usagesCount'] = 0;
			}

			$conditions = Arrays::pick($values, 'conditionsContainer');

			$discountCoupon = $discountCouponRepository->syncOne($values, null, true);

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

			$this->flashMessage('Uloženo', 'success');

			$form->processRedirect('couponsDetail', 'coupons', [$discountCoupon], [$discount ?? $discountCoupon->discount], [$discount]);
		};

		$this->addComponent($form, 'form');
	}

	public function render(): void
	{
		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render(__DIR__ . '/discountCouponForm.latte');
	}
}
