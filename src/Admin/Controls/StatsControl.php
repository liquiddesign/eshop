<?php

namespace Eshop\Admin\Controls;

use Eshop\DB\CurrencyRepository;
use Eshop\DB\MerchantRepository;
use Eshop\DB\OrderRepository;
use Eshop\Shopper;
use Forms\Form;
use Forms\FormFactory;
use Nette;
use Nette\Application\UI\Control;

class StatsControl extends Control
{
	/** @persistent */
	public array $state = [];

	public Shopper $shopper;

	private FormFactory $formFactory;

	private OrderRepository $orderRepository;

	private MerchantRepository $merchantRepository;

	private CurrencyRepository $currencyRepository;

	private $user;

	/**
	 * StatsControl constructor.
	 * @param \Forms\FormFactory $formFactory
	 * @param \Eshop\Shopper $shopper
	 * @param \Eshop\DB\Customer|\Eshop\DB\Merchant|null $user
	 */
	public function __construct(FormFactory $formFactory, Shopper $shopper, OrderRepository $orderRepository, MerchantRepository $merchantRepository, CurrencyRepository $currencyRepository, $user = null)
	{
		$this->formFactory = $formFactory;
		$this->shopper = $shopper;
		$this->user = $user;
		$this->orderRepository = $orderRepository;
		$this->merchantRepository = $merchantRepository;
		$this->currencyRepository = $currencyRepository;
	}

	public function createComponentStatsFilterForm(): Form
	{
		$form = $this->formFactory->create();

		$form->addText('from', 'Od')
			->setHtmlAttribute('min', (new Nette\Utils\DateTime())->modify('- 1 year')->format('Y-m-d'))
			->setHtmlAttribute('max', (new Nette\Utils\DateTime())->format('Y-m-d'))
			->setHtmlType('date')
			->setRequired()
			->setDefaultValue((new Nette\Utils\DateTime())->modify('- 1 year')->format('Y-m-d'));
		$form->addText('to', 'Do')
			->setHtmlAttribute('min', (new Nette\Utils\DateTime())->modify('- 1 year')->format('Y-m-d'))
			->setHtmlAttribute('max', (new Nette\Utils\DateTime())->format('Y-m-d'))
			->setHtmlType('date')
			->setRequired()
			->setDefaultValue((new Nette\Utils\DateTime())->format('Y-m-d'));
		$form->addDataSelect('merchant', 'Obchodník', $this->merchantRepository->getArrayForSelect())->setPrompt('- Obchodník -');

		$currencies = $this->currencyRepository->getArrayForSelect();

		$input = $form->addDataSelect('currency', 'Měna', $currencies)->setRequired();

		if (\count($currencies) > 0) {
			$input->setDefaultValue(Nette\Utils\Arrays::first($currencies));
		}

		$form->addSubmit('submit', 'Zobrazit');

		$form->onValidate[] = function (Form $form): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues();

			if ($values->from <= $values->to) {
				return;
			}

			$form->addError('Neplatný rozsah!');
		};

		$form->onSuccess[] = function (Form $form): void {
			$this->state = $form->getValues('array');

			$this->redirect('this');
		};

		return $form;
	}

	public function render(): void
	{
		/** @var \Nette\Application\UI\Form $form */
		$form = $this->getComponent('statsFilterForm');

		$statsFrom = $this->state['statsFrom'] ?? null;
		$statsTo = $this->state['statsTo'] ?? null;
		$merchant = $this->state['merchant'] ?? null;
		/** @var \Eshop\DB\Currency $currency */
		$currency = isset($this->state['currency']) ? $this->currencyRepository->one($this->state['currency'], true) : $this->currencyRepository->many()->first();

		$form->setDefaults($this->state);

		$user = $merchant ? $this->merchantRepository->one($merchant) : $this->user;

		$from = $statsFrom ? (new Nette\Utils\DateTime($statsFrom)) : ((new Nette\Utils\DateTime())->modify('- 1 year'));
		$to = $statsTo ? (new Nette\Utils\DateTime($statsTo)) : (new Nette\Utils\DateTime());

		$this->template->monthlyOrders = $this->orderRepository->getCustomerGroupedOrdersPrices($user, $from, $to, $currency);

		$from = $statsFrom ? (new Nette\Utils\DateTime($statsFrom)) : ((new Nette\Utils\DateTime())->modify('- 1 year'));
		$to = $statsTo ? (new Nette\Utils\DateTime($statsTo)) : (new Nette\Utils\DateTime());

		$this->template->boughtCategories = $this->orderRepository->getCustomerOrdersCategoriesGroupedByAmountPercentage($user, $from, $to, $currency);

		$from = $statsFrom ? (new Nette\Utils\DateTime($statsFrom)) : ((new Nette\Utils\DateTime())->modify('- 1 year'));
		$to = $statsTo ? (new Nette\Utils\DateTime($statsTo)) : (new Nette\Utils\DateTime());

		$this->template->topProducts = $this->orderRepository->getCustomerOrdersTopProductsByAmount($user, $from, $to, $currency);

		$this->template->render($this->template->getFile() ?: __DIR__ . \DIRECTORY_SEPARATOR . 'statsControl.latte');
	}

	public function handleResetStatsFilter(): void
	{
		$this->state = [];

		$this->redirect('this');
	}
}
