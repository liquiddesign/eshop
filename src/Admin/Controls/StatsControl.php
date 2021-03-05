<?php

namespace Eshop\Admin\Controls;

use Eshop\Shopper;
use Eshop\DB\MerchantRepository;
use Eshop\DB\OrderRepository;
use Nette;
use Forms\Form;
use Forms\FormFactory;
use Nette\Application\UI\Control;

class StatsControl extends Control
{
	/** @persistent */
	public $statsFrom;

	/** @persistent */
	public $statsTo;

	/** @persistent */
	public $merchant;

	public $templateFile;

	private FormFactory $formFactory;

	private Shopper $shopper;

	private OrderRepository $orderRepository;

	private MerchantRepository $merchantRepository;

	private $user;

	/**
	 * StatsControl constructor.
	 * @param \Forms\FormFactory $formFactory
	 * @param \Eshop\Shopper $shopper
	 * @param \Eshop\DB\Customer|\Eshop\DB\Merchant|null $user
	 */
	public function __construct(FormFactory $formFactory, Shopper $shopper, OrderRepository $orderRepository, MerchantRepository $merchantRepository, $user = null)
	{
		$this->formFactory = $formFactory;
		$this->shopper = $shopper;
		$this->user = $user;
		$this->orderRepository = $orderRepository;
		$this->merchantRepository = $merchantRepository;
	}

	public function createComponentStatsFilterForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addText('from', 'Od')
			->setHtmlAttribute('min', (new Nette\Utils\DateTime())->modify('- 1 year')->format('Y-m-d'))
			->setHtmlAttribute('max', (new Nette\Utils\DateTime())->format('Y-m-d'))
			->setHtmlType('date');
		$form->addText('to', 'Do')
			->setHtmlAttribute('min', (new Nette\Utils\DateTime())->modify('- 1 year')->format('Y-m-d'))
			->setHtmlAttribute('max', (new Nette\Utils\DateTime())->format('Y-m-d'))
			->setHtmlType('date');
		$form->addDataSelect('merchant', 'Obchodník', $this->merchantRepository->getListForSelect())->setPrompt('- Obchodník -');

		$form->addSubmit('submit', 'Zobrazit');

		$form->onValidate[] = function (Form $form) {
			$values = $form->getValues();

			if ($values->from > $values->to) {
				$form->addError('Neplatný rozsah!');
			}
		};

		$form->onSuccess[] = function (Form $form) {
			$values = $form->getValues();

			$this->statsFrom = $values->from;
			$this->statsTo = $values->to;
			$this->merchant = $values->merchant;

			$this->redirect('this');
		};

		return $form;
	}

	public function render()
	{
		/** @var Nette\Application\UI\Form $form */
		$form = $this->getComponent('statsFilterForm');

		$form['from']->setDefaultValue($this->statsFrom ?? (new Nette\Utils\DateTime())->modify('- 1 year')->format('Y-m-d'));
		$form['to']->setDefaultValue($this->statsTo ?? (new Nette\Utils\DateTime())->format('Y-m-d'));
		$form['merchant']->setDefaultValue($this->merchant);

		$currency = $this->shopper->getCurrency();

		$user = $this->merchant ? $this->merchantRepository->one($this->merchant) : $this->user;

		$from = isset($this->statsFrom) ? (new Nette\Utils\DateTime($this->statsFrom)) : ((new Nette\Utils\DateTime())->modify('- 1 year'));
		$to = isset($this->statsTo) ? (new Nette\Utils\DateTime($this->statsTo)) : (new Nette\Utils\DateTime());

		$this->template->monthlyOrders = $this->orderRepository->getCustomerGroupedOrdersPrices($user, $from, $to, $currency);

		$from = isset($this->statsFrom) ? (new Nette\Utils\DateTime($this->statsFrom)) : ((new Nette\Utils\DateTime())->modify('- 1 year'));
		$to = isset($this->statsTo) ? (new Nette\Utils\DateTime($this->statsTo)) : (new Nette\Utils\DateTime());

		$this->template->boughtCategories = $this->orderRepository->getCustomerOrdersCategoriesGroupedByAmountPercentage($user, $from, $to, $currency);

		$from = isset($this->statsFrom) ? (new Nette\Utils\DateTime($this->statsFrom)) : ((new Nette\Utils\DateTime())->modify('- 1 year'));
		$to = isset($this->statsTo) ? (new Nette\Utils\DateTime($this->statsTo)) : (new Nette\Utils\DateTime());

		$this->template->topProducts = $this->orderRepository->getCustomerOrdersTopProductsByAmount($user, $from, $to, $currency);

		$this->template->render($this->templateFile ?? __DIR__ . \DIRECTORY_SEPARATOR . 'statsControl.latte');
	}

	public function handleResetStatsFilter()
	{
		$this->statsFrom = null;
		$this->statsTo = null;
		$this->redirect('this');
	}
}