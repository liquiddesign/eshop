<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\CheckoutManager;
use Eshop\DB\OrderRepository;
use Nette;

class NoteForm extends \Nette\Application\UI\Form
{
	private CheckoutManager $checkoutManager;
	
	private OrderRepository $orderRepository;
	
	public function __construct(CheckoutManager $checkoutManager, OrderRepository $orderRepository)
	{
		parent::__construct();
		
		$this->checkoutManager = $checkoutManager;
		$this->orderRepository = $orderRepository;
		
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
		
		$this->checkoutManager->syncPurchase($values);
	}
}
