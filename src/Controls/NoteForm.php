<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\OrderRepository;
use Eshop\Shopper;

class NoteForm extends \Nette\Application\UI\Form
{
	private CheckoutManager $checkoutManager;
	
	private OrderRepository $orderRepository;

	private Shopper $shopper;
	
	public function __construct(CheckoutManager $checkoutManager, OrderRepository $orderRepository, Shopper $shopper)
	{
		parent::__construct();
		
		$this->checkoutManager = $checkoutManager;
		$this->orderRepository = $orderRepository;
		$this->shopper = $shopper;
		
		$this->addTextArea('note');
		$this->addText('internalOrderCode');
		$this->addText('desiredShippingDate')->setHtmlType('date');
		
		$this->addSubmit('submit');
		$this->onSuccess[] = [$this, 'success'];
	}
	
	public function success(NoteForm $form): void
	{
		$values = $this->getValues();
		$values['desiredShippingDate'] = $values['desiredShippingDate'] ?: null;
		
		$values['account'] = $this->shopper->getCustomer() ? $this->shopper->getCustomer()->getAccount() : null;
		$values['accountFullname'] = $values['account'] ? $values['account']->fullname : null;
		$values['currency'] = $this->shopper->getCurrency();
		
		$this->checkoutManager->syncPurchase($values);
	}
}
