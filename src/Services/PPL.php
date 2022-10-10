<?php

declare(strict_types=1);

namespace Eshop\Services;

use Eshop\Admin\SettingsPresenter;
use Eshop\DB\DeliveryRepository;
use Eshop\DB\OrderRepository;
use Nette\Application\Application;
use Nette\DI\Container;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use Nette\Utils\Validators;
use Salamek\PplMyApi\Api;
use Salamek\PplMyApi\Enum\Country;
use Salamek\PplMyApi\Enum\Currency;
use Salamek\PplMyApi\Enum\LabelDecomposition;
use Salamek\PplMyApi\Enum\Product;
use Salamek\PplMyApi\Model\CityRouting;
use Salamek\PplMyApi\Model\Flag;
use Salamek\PplMyApi\Model\Package;
use Salamek\PplMyApi\Model\PackageNumberInfo;
use Salamek\PplMyApi\Model\PackageSet;
use Salamek\PplMyApi\Model\PaymentInfo;
use Salamek\PplMyApi\Model\Recipient;
use Salamek\PplMyApi\Model\Sender;
use Salamek\PplMyApi\PdfLabel;
use Salamek\PplMyApi\Tools;
use StORM\Collection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class PPL
{
	public const NOTE_MAX_LENGTH = 35;

	/** @var array<callable(): bool> */
	public array $onBeforeOrdersSent = [];

	/** @var array<callable(\Eshop\DB\Order): bool> */
	public array $onBeforeOrderSent = [];

	private string $login;

	private string $password;

	private int $idCustomer;

	private int $packageSeriesNumberId;

	private int $packageSeriesNumberIdCod;

	private string $depoCode;

	private string $depoCodeCod;

	private string $secureStorage;

	private int $codType;

	private int $nonCodType;

	private SettingRepository $settingRepository;

	private Container $container;

	private Application $application;

	private ?Sender $sender;

	private OrderRepository $orderRepository;

	private DeliveryRepository $deliveryRepository;

	public function __construct(
		string $login,
		string $password,
		int $idCustomer,
		int $packageSeriesNumberId,
		int $packageSeriesNumberIdCod,
		string $depoCode,
		string $depoCodeCod,
		int $codType = 9,
		int $nonCodType = 5,
		?Sender $sender = null,
		?SettingRepository $settingRepository = null,
		?Container $container = null,
		?Application $application = null,
		?OrderRepository $orderRepository = null,
		?DeliveryRepository $deliveryRepository = null
	) {
		$this->login = $login;
		$this->password = $password;
		$this->idCustomer = $idCustomer;
		$this->settingRepository = $settingRepository;
		$this->packageSeriesNumberId = $packageSeriesNumberId;
		$this->packageSeriesNumberIdCod = $packageSeriesNumberIdCod;
		$this->depoCode = $depoCode;
		$this->depoCodeCod = $depoCodeCod;
		$this->codType = $codType;
		$this->nonCodType = $nonCodType;
		$this->container = $container;
		$this->application = $application;
		$this->sender = $sender;
		$this->orderRepository = $orderRepository;
		$this->secureStorage = $container->getParameters()['tempDir'];
		$this->deliveryRepository = $deliveryRepository;
	}

	public function getPplDeliveryTypePK(): ?string
	{
		return $this->settingRepository->getValueByName('pplDeliveryType');
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\Order> $orders
	 * @return array<mixed> Orders with errors
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function syncOrders(Collection $orders): array
	{
		if (\in_array(false, Arrays::invoke($this->onBeforeOrdersSent), true)) {
			throw new \Exception('Not allowed');
		}

		$pplDeliveryType = $this->getPplDeliveryTypePK();

		if (!$pplDeliveryType) {
			throw new \Exception('Delivery type for PPL service is not set!');
		}

		$pplCodType = $this->settingRepository->getValueByName('codType');

		$client = $this->getClient();

		$ordersCompleted = [];
		$ordersIgnored = [];
		$ordersWithError = [];

		$packageNumberInfo = new PackageNumberInfo(
			$this->packageSeriesNumberId,
			Product::PPL_PARCEL_CZ_PRIVATE,
			$this->depoCode,
		);

		/** @var \Eshop\DB\Order $order */
		foreach ($orders as $order) {
			if (\in_array(false, Arrays::invoke($this->onBeforeOrderSent, $order), true)) {
				$ordersIgnored[] = $order;

				continue;
			}

			$orderPplCodes = null;
			$purchase = $order->purchase;
			$deliveryAddress = $purchase->deliveryAddress ?? $purchase->billAddress;

			$packageSet = null;
			$packagesCount = $order->packages->count();
			$i = 1;

			foreach ($order->packages as $package) {
				try {
					$delivery = $package->delivery;
					$deliveryType = $delivery->type;

					if (!$deliveryType || $deliveryType->getPK() !== $pplDeliveryType) {
						$ordersIgnored[$order->code] = isset($ordersIgnored[$order->code]) ? $ordersIgnored[$order->code] + 1 : 1;

						continue;
					}

					if ($pplCode = $delivery->getPplCode()) {
						$ordersIgnored[$order->code] = isset($ordersIgnored[$order->code]) ? $ordersIgnored[$order->code] + 1 : 1;
						$orderPplCodes .= "$pplCode,";

						continue;
					}

					$isCod = $pplCodType && $order->purchase->paymentType && $order->purchase->paymentType->getPK() === $pplCodType;

					$packageNumber = $isCod ? $this->getPackageNumberCod() : $this->getPackageNumber();

					if ($packagesCount > 1) {
						$packageSet = new PackageSet(
							$packageSet === null ? $packageNumber : $packageSet->getMasterPackageNumber(),
							$i,
							$packagesCount,
						);
					}

					$country = Country::CZ;
					$city = $deliveryAddress ? $deliveryAddress->city : '';
					$street = $deliveryAddress ? $deliveryAddress->street : '';
					$zipCode = $deliveryAddress ? $deliveryAddress->zipcode : '';

					$recipient = new Recipient(
						$city,
						$purchase->fullname ?: '',
						$street,
						$zipCode,
						$purchase->email,
						$purchase->phone,
						null,
						$country,
						$purchase->billAddress && $purchase->billAddress->companyName ? $purchase->billAddress->companyName : null,
					);

					$cityRoutingResponse = $client->getCitiesRouting($country, null, $zipCode, $street);

					if ($cityRoutingResponse instanceof \stdClass) {
						$cityRoutingResponse = [$cityRoutingResponse];
					}

					if (!\is_array($cityRoutingResponse) || !isset($cityRoutingResponse[0])) {
						$ordersWithError[$order->code] = $order;

						continue;
					}

					$cityRoutingResponse = $cityRoutingResponse[0];

					/** @codingStandardsIgnoreStart Camel caps */
					if (!isset($cityRoutingResponse->RouteCode) || !isset($cityRoutingResponse->DepoCode) || !isset($cityRoutingResponse->Highlighted)) {
						$ordersWithError[$order->code] = $order;

						continue;
					}

					$cityRouting = new CityRouting(
					/** @codingStandardsIgnoreStart Camel caps */
						$cityRoutingResponse->RouteCode,
						$cityRoutingResponse->DepoCode,
						$cityRoutingResponse->Highlighted
					/** @codingStandardsIgnoreEnd */
					);

					if ($isCod) {
						$cashOnDeliveryPrice = \round($order->getTotalPriceVat() / \count($order->packages), 2);
						$cashOnDeliveryCurrency = Currency::CZK;
						$cashOnDeliveryVariableSymbol = (int)$order->code;

						if ($cashOnDeliveryVariableSymbol === 0) {
							$cashOnDeliveryVariableSymbol = $packageNumber;
						}

						$paymentInfo = new PaymentInfo($cashOnDeliveryPrice, $cashOnDeliveryCurrency, $cashOnDeliveryVariableSymbol);

						$package = new Package(
							(string)$packageNumber,
							Product::PPL_PARCEL_CZ_PRIVATE_COD,
							$order->code . ($order->purchase->deliveryNote ? ', ' . Strings::substring($order->purchase->deliveryNote, 0, self::NOTE_MAX_LENGTH) : null),
							$recipient,
							$cityRouting,
							null,
							null,
							null,
							$paymentInfo,
							[],
							[],
							[new Flag('SL', true)],
							null,
							null,
							$packageSet,
						);
					} else {
						$package = new Package(
							(string)$packageNumber,
							Product::PPL_PARCEL_CZ_PRIVATE,
							$order->code . ($order->purchase->deliveryNote ? ', ' . Strings::substring($order->purchase->deliveryNote, 0, self::NOTE_MAX_LENGTH) : null),
							$recipient,
							$cityRouting,
							null,
							null,
							null,
							null,
							[],
							[],
							[new Flag('SL', true)],
							null,
							null,
							$packageSet,
						);
					}

					/** Don´t delete array type!!! By doc createPackages returns array but that is NOT true in all cases! */
					$result = (array)$client->createPackages([$package]);

					\bdump($result);

					if ($result['Code'] !== '0') {
						$delivery->update(['pplError' => true]);

						$ordersWithError[$order->code] = $order;

						$tempDir = $this->container->getParameters()['tempDir'] . '/ppl';

						FileSystem::createDir($tempDir);

						FileSystem::write("$tempDir/" . $delivery->getPK(), $result['Message'] ?? 'Neznámá chyba');

						continue;
					}

					$isCod ? $this->incrementPackageNumberCod() : $this->incrementPackageNumber();

					$pplCode = $result['ItemKey'];

					$delivery->update(['pplCode' => $pplCode, 'pplError' => false,]);

					$ordersCompleted[$order->code] = isset($ordersCompleted[$order->code]) ? $ordersCompleted[$order->code] + 1 : 1;
					$orderPplCodes .= "$pplCode,";
				} catch (\Throwable $e) {
					\bdump($e);

					$ordersWithError[$order->code] = $order;

					$delivery->update(['pplError' => true]);

					$tempDir = $this->container->getParameters()['tempDir'] . '/ppl';

					FileSystem::createDir($tempDir);

					FileSystem::write("$tempDir/" . $delivery->getPK(), $e->getMessage());
				}
			}

			$order->update([
				'pplCode' => $orderPplCodes,
				'pplError' => isset($ordersWithError[$order->code]),
			]);
		}

		return [
			'completed' => $ordersCompleted,
			'failed' => $ordersWithError,
			'ignored' => $ordersIgnored,
		];
	}

	/**
	 * Get labels from PPL for orders
	 * @param \StORM\Collection<\Eshop\DB\Order> $orders
	 */
	public function getLabels(Collection $orders): ?string
	{
		if (!$this->sender) {
			throw new \Exception('Sender not set!');
		}

		$client = $this->getClient();

		$pplCodType = $this->settingRepository->getValueByName('codType');

		try {
			$packages = [];
			$ids = [];

			/** @var \Eshop\DB\Order $order */
			foreach ($orders->where('this.pplCode IS NOT NULL')->where('this.pplError', false)->toArray() as $order) {
				$purchase = $order->purchase;
				$deliveryAddress = $purchase->deliveryAddress ?? $purchase->billAddress;

				$pplCodes = \explode(',', $order->pplCode);
				$deliveries = $this->deliveryRepository->many()->where('this.pplCode IS NOT NULL')->setIndex('this.pplCode')->where('this.fk_order', $order->getPK())->toArray();

				foreach ($pplCodes as $pplCode) {
					if (!$pplCode) {
						continue;
					}

					$delivery = $deliveries[$pplCode] ?? null;

					if ($delivery) {
						if ($delivery->pplError) {
							continue;
						}
					}

					$country = Country::CZ;
					$city = $deliveryAddress ? $deliveryAddress->city : '';
					$street = $deliveryAddress ? $deliveryAddress->street : '';
					$zipCode = $deliveryAddress ? $deliveryAddress->zipcode : '';

					$recipient = new Recipient(
						$city,
						$purchase->fullname ?: '',
						$street,
						$zipCode,
						$purchase->email,
						$purchase->phone,
						null,
						$country,
						$purchase->billAddress && $purchase->billAddress->companyName ? $purchase->billAddress->companyName : null,
					);

					$packageNumber = $pplCode;

					$cityRoutingResponse = $client->getCitiesRouting($country, null, $zipCode, $street);

					if (\is_array($cityRoutingResponse)) {
						$cityRoutingResponse = $cityRoutingResponse[0];
					}

					/** @codingStandardsIgnoreStart Camel caps */
					if (!isset($cityRoutingResponse->RouteCode) || !isset($cityRoutingResponse->DepoCode) || !isset($cityRoutingResponse->Highlighted)) {
						/** @codingStandardsIgnoreEnd */
						throw new \Exception('Štítek PPL se nepodařilo vytisknout, chybí Routing, pravděpodobně neplatná adresa!');
					}

					$cityRouting = new CityRouting(
					/** @codingStandardsIgnoreStart Camel caps */
						$cityRoutingResponse->RouteCode,
						$cityRoutingResponse->DepoCode,
						$cityRoutingResponse->Highlighted,
					/** @codingStandardsIgnoreEnd */
					);

					$isCod = $pplCodType && $order->purchase->paymentType && $order->purchase->paymentType->getPK() === $pplCodType;

					if ($isCod) {
						$cashOnDeliveryPrice = \round($order->getTotalPriceVat() / \count($order->packages), 2);
						$cashOnDeliveryCurrency = Currency::CZK;
						$cashOnDeliveryVariableSymbol = (int)$order->code;

						if ($cashOnDeliveryVariableSymbol === 0) {
							$cashOnDeliveryVariableSymbol = (int)$packageNumber;
						}

						$paymentInfo = new PaymentInfo($cashOnDeliveryPrice, $cashOnDeliveryCurrency, $cashOnDeliveryVariableSymbol);

						$packages[] = new Package(
							$packageNumber,
							Product::PPL_PARCEL_CZ_PRIVATE_COD,
							$order->code . ($order->purchase->deliveryNote ? '<br>' . Strings::substring($order->purchase->deliveryNote, 0, self::NOTE_MAX_LENGTH) : null),
							$recipient,
							$cityRouting,
							$this->sender,
							null,
							null,
							$paymentInfo,
							[],
							[],
							[new Flag('SL', true)],
						);
					} else {
						$packages[] = new Package(
							$packageNumber,
							Product::PPL_PARCEL_CZ_PRIVATE,
							$order->code . ($order->purchase->deliveryNote ? '<br>' . Strings::substring($order->purchase->deliveryNote, 0, self::NOTE_MAX_LENGTH) : null),
							$recipient,
							$cityRouting,
							$this->sender,
							null,
							null,
							null,
							[],
							[],
							[new Flag('SL', true)],
						);
					}
				}

				$ids[] = $order->getPK();
			}

			if (!\count($ids)) {
				return null;
			}

			$rawPdf = PdfLabel::generateLabels($packages, LabelDecomposition::QUARTER);

			$dir = $this->container->getParameters()['tempDir'] . '/pdfs/';
			FileSystem::createDir($dir);

			$filename = \tempnam($dir, 'ppl');

			$this->application->onShutdown[] = function () use ($filename): void {
				if (!\is_file($filename)) {
					return;
				}

				FileSystem::delete($filename);
			};

			FileSystem::write($filename, $rawPdf);

			$orders->clear(true)->where('this.uuid', $ids)->update(['this.pplPrinted' => true]);

			return $filename;
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::ERROR);
			\bdump($e);

			return null;
		}
	}

	public function getPackages(): void
	{
		$client = $this->getClient();

		$dateFrom = new \DateTime();
		$dateFrom->modify('-14 days');
		$dateTo = new \DateTime();

		$packageNumbers = [];

		$result = $client->getPackages(
			null,
			$dateFrom,
			$dateTo,
			$packageNumbers,
		);

		\bdump($result);
	}

	protected function getClient(): Api
	{
		$client = new Api($this->login, $this->password, $this->idCustomer, $this->secureStorage);

		if (!$client->isHealthy()) {
			throw new \Exception('Connection to PPL service is not valid!');
		}

		return $client;
	}

	protected function getPackageNumber(): string
	{
		$packageNumberInfo = new PackageNumberInfo(
			$this->packageSeriesNumberId,
			Product::PPL_PARCEL_CZ_PRIVATE,
			$this->depoCode,
		);

		$packageNumber = Tools::generatePackageNumber($packageNumberInfo);
		$packageNumber[3] = $this->nonCodType;

		$lastUsedPackageNumber = (int) $this->settingRepository->getValueByName(SettingsPresenter::PPL_LAST_USED_PACKAGE_NUMBER);

		if ($lastUsedPackageNumber) {
			return (string) ($lastUsedPackageNumber + 1);
		}

		return $packageNumber;
	}

	protected function getPackageNumberCod(): string
	{
		$packageNumberInfo = new PackageNumberInfo(
			$this->packageSeriesNumberId,
			Product::PPL_PARCEL_CZ_PRIVATE,
			$this->depoCode,
			true,
		);

		$packageNumber = Tools::generatePackageNumber($packageNumberInfo);
		$packageNumber[3] = $this->nonCodType;

		$lastUsedPackageNumber = (int) $this->settingRepository->getValueByName(SettingsPresenter::PPL_LAST_USED_PACKAGE_NUMBER_COD);

		if ($lastUsedPackageNumber) {
			return (string) ($lastUsedPackageNumber + 1);
		}

		return $packageNumber;
	}

	protected function incrementPackageNumber(): void
	{
		$existingSetting = $this->settingRepository->one(['name' => SettingsPresenter::PPL_LAST_USED_PACKAGE_NUMBER]);

		if (!$existingSetting || !Validators::isNumericInt($existingSetting->value)) {
			return;
		}

		$existingSetting->update([
			'value' => (string)(((int) $existingSetting->value) + 1),
		]);
	}

	protected function incrementPackageNumberCod(): void
	{
		$existingSetting = $this->settingRepository->one(['name' => SettingsPresenter::PPL_LAST_USED_PACKAGE_NUMBER_COD]);

		if (!$existingSetting || !Validators::isNumericInt($existingSetting->value)) {
			return;
		}

		$existingSetting->update([
			'value' => (string)(((int) $existingSetting->value) + 1),
		]);
	}
}
