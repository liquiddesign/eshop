<?php

declare(strict_types=1);

namespace Eshop\Bridges;

use Eshop\Common\Services\ProductExporter;
use Eshop\Common\Services\ProductImporter;
use Eshop\Common\Services\ProductTester;
use Eshop\CompareManager;
use Eshop\Services\Comgate;
use Eshop\Services\ProductsCache\LiveProductsProvider;
use Eshop\Services\ProductsCache\ProductsCacheProvider;
use Eshop\Services\ProductsCache\RustDaemonClient;
use Eshop\Services\ProductsCache\RustProxyProductsProvider;
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
			// Which products provider implementation to use as GeneralProductsCacheProvider:
			//   'cache' (default) = ProductsCacheProvider — oddělená cache DB obnovovaná Go programem
			//   'live'            = LiveProductsProvider — čtení přímo z produkční DB + denormalizované sloupce
			//   'rust'            = RustProxyProductsProvider — abel-products-daemon s fallback na LiveProductsProvider
			'productsProvider' => Expect::anyOf('cache', 'live', 'rust')->firstIsDefault(),
			'rustDaemon' => Expect::structure([
				// Unix socket path must match SOCKET_PATH in products-daemon/.env.
				'socketPath' => Expect::string('/tmp/abel-products-daemon.sock'),
				// Absolute path to the compiled daemon binary — used for spawn-on-demand.
				'binaryPath' => Expect::string()->nullable(),
				// Absolute path to the daemon's .env file — passed via --env-file on spawn.
				'envPath' => Expect::string()->nullable(),
				// Connect + read timeout in milliseconds. Keep tight so a sick daemon can't stall requests.
				'timeoutMs' => Expect::int(500),
				// Attempt to spawn the binary when the socket is unreachable.
				'spawnOnDemand' => Expect::bool(true),
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
		$productsProviderOption = $config['productsProvider'] ?? 'cache';
		$rustDaemonConfig = (array) ($config['rustDaemon'] ?? []);

		if ($productsProviderOption === 'rust') {
			// Fallback provider — registered as a separate service so PHP can still use it directly.
			$fallbackServiceName = $this->prefix('productsProviderLiveFallback');
			$builder
				->addDefinition($fallbackServiceName)
				->setType(LiveProductsProvider::class)
				->setAutowired(false);

			$clientServiceName = $this->prefix('rustDaemonClient');
			$builder
				->addDefinition($clientServiceName)
				->setType(RustDaemonClient::class)
				->setArguments([
					'socketPath' => $rustDaemonConfig['socketPath'] ?? '/tmp/abel-products-daemon.sock',
					'binaryPath' => $rustDaemonConfig['binaryPath'] ?? null,
					'envPath' => $rustDaemonConfig['envPath'] ?? null,
					'timeoutSec' => (int) ($rustDaemonConfig['timeoutMs'] ?? 500) / 1000.0,
					'spawnOnDemand' => (bool) ($rustDaemonConfig['spawnOnDemand'] ?? true),
				])
				->setAutowired(false);

			// shopperUser + productRepository are resolved by Nette DI autowire from their types —
			// ShopperUser is registered as `@security.user` above, ProductRepository is autowired
			// by StORM. Explicit wiring is only needed for the two non-autowired services (fallback + client).
			$builder->addDefinition($this->prefix('productsProvider'))
				->setType(RustProxyProductsProvider::class)
				->setArguments([
					'fallback' => '@' . $fallbackServiceName,
					'client' => '@' . $clientServiceName,
				]);
		} else {
			$productsProviderClass = $productsProviderOption === 'live' ? LiveProductsProvider::class : ProductsCacheProvider::class;
			$builder->addDefinition($this->prefix('productsProvider'))->setType($productsProviderClass);
		}

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
		$config = (array) $this->getConfig();

		if (($config['productsProvider'] ?? 'cache') !== 'rust') {
			return;
		}

		// Panel dostane klient injected, aby mohl on-demand stáhnout daemon stats
		// (uptime, RSS, total requests, avg/max ms, snapshot history). Service jméno je
		// konstantní — klient je registrován v `loadConfiguration` s `setAutowired(false)`,
		// takže ho autowire nenajde; přímý `getService(name)` je proto nutný.
		$clientServiceName = $this->prefix('rustDaemonClient');
		$class->getMethod('initialize')
			->addBody(
				'\\Tracy\\Debugger::getBar()->addPanel(new \\Eshop\\Services\\ProductsCache\\RustDaemonBarPanel($this->getService(?)));',
				[$clientServiceName],
			);
	}
}
