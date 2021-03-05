<?php

declare(strict_types=1);

namespace Eshop\Bridges;

use Eshop\CheckoutManager;
use Eshop\CompareManager;
use Eshop\Shopper;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * @package App\Eshop
 */
class ShopperDI extends \Nette\DI\CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'country' => Expect::string('CZ'),
			'currency' => Expect::string('CZK'),
			'registration' => Expect::structure([
				'enabled' =>  Expect::bool(true),
				'confirmation' => Expect::bool(true),
				'emailAuthorization' => Expect::bool(true),
			]),
			'checkoutSequence' => Expect::list([
				'cart',
				'addresses',
				'deliveryPayment',
				'summary',
			]),
		]);
	}
	
	public function loadConfiguration(): void
	{
		$config = (array) $this->getConfig();
		
		/** @var \Nette\DI\ContainerBuilder $builder */
		$builder = $this->getContainerBuilder();
		
		$shopper = $builder->addDefinition($this->prefix('shopper'))->setType(Shopper::class);
		$cartManager = $builder->addDefinition($this->prefix('cartManager'))->setType(CheckoutManager::class);
		$builder->addDefinition($this->prefix('compareManager'))->setType(CompareManager::class);
		$builder->getDefinition('latte.templateFactory')->addSetup('$onCreate[]', [['@shopper.shopper', 'addFilters']]);
		
		$shopper->addSetup('setRegistrationConfiguration',[(array) $config['registration']]);
		$shopper->addSetup('setCountry',[$config['country']]);
		$shopper->addSetup('setCurrency',[$config['currency']]);
		$cartManager->addSetup('setCheckoutSequence',[$config['checkoutSequence']]);
		
		return;
	}
}