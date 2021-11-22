<?php

declare(strict_types=1);

namespace Eshop\Bridges;

use Eshop\CheckoutManager;
use Eshop\CompareManager;
use Eshop\Integration\Comgate;
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
			'projectUrl' => Expect::string('lqd.cz'),
			'country' => Expect::string('CZ'),
			'currency' => Expect::string('CZK'),
			'registration' => Expect::structure([
				'enabled' => Expect::bool(true),
				'confirmation' => Expect::bool(true),
				'emailAuthorization' => Expect::bool(true),
			]),
			'checkoutSequence' => Expect::list([
				'cart',
				'addresses',
				'deliveryPayment',
				'summary',
			]),
			'showWithoutVat' => Expect::bool(true),
			'showVat' => Expect::bool(true),
			'editOrderAfterCreation' => Expect::bool(false),
		]);
	}
	
	public function loadConfiguration(): void
	{
		$config = (array) $this->getConfig();

		$builder = $this->getContainerBuilder();
		
		$shopper = $builder->addDefinition($this->prefix('shopper'))->setType(Shopper::class);
		$cartManager = $builder->addDefinition($this->prefix('cartManager'))->setType(CheckoutManager::class);
		$builder->addDefinition($this->prefix('comgate'))->setType(Comgate::class);
		$builder->addDefinition($this->prefix('compareManager'))->setType(CompareManager::class);

		/** @var \Nette\DI\Definitions\ServiceDefinition $latteDefinition */
		$latteDefinition = $builder->getDefinition('latte.templateFactory');
		$latteDefinition->addSetup('$onCreate[]', [['@shopper.shopper', 'addFilters']]);

		$shopper->addSetup('setProjectUrl', [$config['projectUrl']]);
		$shopper->addSetup('setRegistrationConfiguration', [(array) $config['registration']]);
		$shopper->addSetup('setCountry', [$config['country']]);
		$shopper->addSetup('setCurrency', [$config['currency']]);
		$shopper->addSetup('setShowWithoutVat', [$config['showWithoutVat']]);
		$shopper->addSetup('setShowVat', [$config['showVat']]);
		$shopper->addSetup('setEditOrderAfterCreation', [$config['editOrderAfterCreation']]);

		$cartManager->addSetup('setCheckoutSequence', [$config['checkoutSequence']]);
	}
}
