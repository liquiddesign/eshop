<?php

declare(strict_types=1);

namespace Eshop\Bridges;

use Eshop\Common\Services\ProductExporter;
use Eshop\Common\Services\ProductImporter;
use Eshop\Common\Services\ProductTester;
use Eshop\CompareManager;
use Eshop\Services\Comgate;
use Eshop\Services\ProductsCache\RustDaemonClient;
use Eshop\Services\ProductsCache\RustProductsProvider;
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
			'maxCustomerOrderPrice' => Expect::structure([
				// Show inputs in admin customer detail
				'show' => Expect::bool(false),
				// False - only warning, True - can't order @TODO condition is not implemented!
				'restrict' => Expect::bool(false),
			]),
			'rustDaemon' => Expect::structure([
				// Unix socket path. Default is computed from %appDir% at loadConfiguration time.
				'socketPath' => Expect::string()->nullable()->default(null),
				// Absolute path to the compiled daemon binary. Default is computed from %vendorDir%.
				'binaryPath' => Expect::string()->nullable()->default(null),
				// Absolute path to the daemon .env file. Default is computed from %appDir%.
				'envPath' => Expect::string()->nullable()->default(null),
				// Connect + read timeout in milliseconds.
				'timeoutMs' => Expect::int(1000),
				// Attempt to spawn the binary when the socket is unreachable.
				// Set to false when a cron watchdog (ProductsDaemonTasks) manages the daemon lifecycle.
				'spawnOnDemand' => Expect::bool(false),
			])->castTo('array'),
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

		$rustDaemonConfig = (array) ($config['rustDaemon'] ?? []);

		// Compute zero-config defaults from Nette container parameters so the daemon works
		// out of the box with no explicit rustDaemon config needed.
		$appDir = (string) ($builder->parameters['appDir'] ?? '');
		$vendorDir = (string) ($builder->parameters['vendorDir'] ?? '');
		$projectRoot = $appDir !== '' ? \dirname($appDir) : '';

		$socketPath = $rustDaemonConfig['socketPath'] ?? ($projectRoot !== '' ? $projectRoot . '/temp/abel-products-daemon.sock' : '/tmp/abel-products-daemon.sock');
		$binaryPath = $rustDaemonConfig['binaryPath'] ?? ($vendorDir !== '' ? $vendorDir . '/liquiddesign/eshop/bin/products-daemon-linux-x86_64' : null);
		$envPath = $rustDaemonConfig['envPath'] ?? ($projectRoot !== '' ? $projectRoot . '/products-daemon.env' : null);

		$clientServiceName = $this->prefix('rustDaemonClient');
		$builder
			->addDefinition($clientServiceName)
			->setType(RustDaemonClient::class)
			->setArguments([
				'socketPath' => $socketPath,
				'binaryPath' => $binaryPath,
				'envPath' => $envPath,
				'timeoutSec' => (int) ($rustDaemonConfig['timeoutMs'] ?? 1000) / 1000.0,
				'spawnOnDemand' => (bool) ($rustDaemonConfig['spawnOnDemand'] ?? false),
			])
			->setAutowired(false);

		$builder->addDefinition($this->prefix('productsProvider'))
			->setType(RustProductsProvider::class)
			->setArguments([
				'client' => '@' . $clientServiceName,
			]);

		$builder->addDefinition($this->prefix('productTester'))->setType(ProductTester::class);

		/** @var \Nette\DI\Definitions\ServiceDefinition $latteDefinition */
		$latteDefinition = $builder->getDefinition('latte.templateFactory');
		$latteDefinition->addSetup('$onCreate[]', [['@security.user', 'addFilters']]);
		$latteDefinition->addSetup('$onCreate[]', [['@security.user', 'addFiltersSecondary']]);

		$shopperUser->addSetup('setRegistrationConfiguration', [(array) $config['registration']]);
		$shopperUser->addSetup('setConfig', [$config]);
	}

	public function afterCompile(\Nette\PhpGenerator\ClassType $class): void
	{
		$clientServiceName = $this->prefix('rustDaemonClient');
		$class->getMethod('initialize')
			->addBody(
				'\\Tracy\\Debugger::getBar()->addPanel(new \\Eshop\\Services\\ProductsCache\\RustDaemonBarPanel($this->getService(?)));',
				[$clientServiceName],
			);
	}
}
