<?php

declare(strict_types=1);

namespace Eshop\Admin\Controls;

use Eshop\Common\Services\ProductTester;
use Eshop\DB\CustomerGroupRepository;
use Eshop\DB\CustomerRepository;
use Eshop\DB\ProductRepository;
use Nette\Application\UI\Control;

class ProductTesterResult extends Control
{
	public function __construct(
		protected readonly ProductRepository $productRepository,
		protected readonly ProductTester $productTester,
		protected readonly CustomerRepository $customerRepository,
		protected readonly CustomerGroupRepository $customerGroupRepository,
		protected array|null $tester = null,
	) {
	}

	public function render(): void
	{
		$this->template->testResult = null;

		$tester = $this->tester;

		if (isset($tester['product'])) {
			$product = $this->productRepository->one($tester['product'], true);
		}

		if (isset($tester['customer'])) {
			$customer = $this->customerRepository->one($tester['customer'], true);
		}

		if (isset($tester['group'])) {
			$group = $this->customerGroupRepository->one($tester['group'], true);
		}

		if (!isset($product)) {
			return;
		}

		if (isset($customer)) {
			$this->template->testResult = $this->productTester->testProductByCustomer($product, $customer);
		} elseif (isset($group)) {
			$this->template->testResult = $this->productTester->testProductByGroup($product, $group);
		} else {
			//@TODO custom test
		}

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;
		$template->render(__DIR__ . '/productTesterResult.latte');
	}
}
