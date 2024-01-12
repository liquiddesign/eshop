<?php

declare(strict_types=1);

namespace Eshop\Bridges;

use Eshop\Common\Services\ProductExporter;
use Eshop\Common\Services\ProductImporter;
use Eshop\Common\Services\ProductTester;
use Eshop\CompareManager;
use Eshop\ProductsProvider;
use Eshop\Services\Comgate;
use Eshop\ShopperUser;
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
			'preloadCategoryCounts' => Expect::array([2,3]),
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
			])->mergeDefaults(false),
			'showWithoutVat' => Expect::bool(true),
			'showVat' => Expect::bool(true),
			'priorityPrice' => Expect::anyOf('withoutVat', 'withVat')->firstIsDefault(),
			'showZeroPrices' => Expect::bool(true),
			'editOrderAfterCreation' => Expect::bool(false),
			'alwaysCreateCustomerOnOrderCreated' => Expect::bool(false),
			'allowBannedEmailOrder' => Expect::bool(false),
			'integrations' => Expect::structure([
				'eHub' => Expect::bool(false),
			]),
			'reviews' => Expect::structure([
				'type' => Expect::anyOf('int', 'float')->firstIsDefault(),
				'minScore' => Expect::float(1),
				'maxScore' => Expect::float(5),
				'maxRemindersCount' => Expect::int(1),
			]),
			'invoices' => Expect::structure([
				'autoTaxDateInDays' => Expect::int(0),
				'autoDueDateInDays' => Expect::int(0),
			]),
			'categories' => Expect::structure([
				'image' => Expect::structure([
					'detail' => Expect::structure([
						'width' => Expect::int(600),
						'height' => Expect::int(null),
					])->castTo('array'),
					'thumb' => Expect::structure([
						'width' => Expect::int(300),
						'height' => Expect::int(null),
					])->castTo('array'),
				])->castTo('array'),
				'fallbackImage' => Expect::structure([
					'detail' => Expect::structure([
						'width' => Expect::int(600),
						'height' => Expect::int(null),
					])->castTo('array'),
					'thumb' => Expect::structure([
						'width' => Expect::int(300),
						'height' => Expect::int(null),
					])->castTo('array'),
				])->castTo('array'),
			]),
			'autoFixCart' => Expect::bool(true),
			'discountConditions' => Expect::structure([
				'categories' => Expect::bool(false),
			]),
		]);
	}
	
	public function loadConfiguration(): void
	{
		$config = (array) $this->getConfig();

		$builder = $this->getContainerBuilder();

		if ($builder->hasDefinition('security.user')) {
			$builder->removeDefinition('security.user');
		}

		$shopperUser = $builder->addDefinition('security.user')->setType(ShopperUser::class);

		$builder->addDefinition($this->prefix('comgate'))->setType(Comgate::class);
		$builder->addDefinition($this->prefix('compareManager'))->setType(CompareManager::class);
		$builder->addDefinition($this->prefix('productExporter'))->setType(ProductExporter::class);
		$builder->addDefinition($this->prefix('productImporter'))->setType(ProductImporter::class);
		$builder->addDefinition($this->prefix('productsProvider'))->setType(ProductsProvider::class);
		$builder->addDefinition($this->prefix('productTester'))->setType(ProductTester::class);

		/** @var \Nette\DI\Definitions\ServiceDefinition $latteDefinition */
		$latteDefinition = $builder->getDefinition('latte.templateFactory');
		$latteDefinition->addSetup('$onCreate[]', [['@security.user', 'addFilters']]);

		$shopperUser->addSetup('setRegistrationConfiguration', [(array) $config['registration']]);
		$shopperUser->addSetup('setConfig', [$config]);
	}
}
