<?php

declare(strict_types=1);

namespace Eshop\Services;

use Eshop\DB\OrderRepository;
use Nette\Application\Application;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use Salamek\PplMyApi\Api;
use Salamek\PplMyApi\Enum\Country;
use Salamek\PplMyApi\Enum\Currency;
use Salamek\PplMyApi\Enum\LabelDecomposition;
use Salamek\PplMyApi\Enum\Product;
use Salamek\PplMyApi\Model\CityRouting;
use Salamek\PplMyApi\Model\Package;
use Salamek\PplMyApi\Model\PackageNumberInfo;
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
		?OrderRepository $orderRepository = null
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
	}

	public function getPplDeliveryTypePK(): ?string
	{
		return $this->settingRepository->getValueByName('pplDeliveryType');
	}

	/**
	 * @param \StORM\Collection $orders
	 * @return array<\Eshop\DB\Order> Orders with errors
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function syncOrders(Collection $orders): array
	{
		$pplDeliveryType = $this->getPplDeliveryTypePK();

		if (!$pplDeliveryType) {
			throw new \Exception('Delivery type for PPL service is not set!');
		}

		$pplCodType = $this->settingRepository->getValueByName('codType');

		$client = $this->getClient();

		$ordersWithError = [];

		$packageNumberInfo = new PackageNumberInfo(
			$this->packageSeriesNumberId,
			Product::PPL_PARCEL_CZ_PRIVATE,
			$this->depoCode,
		);

		$packageNumber = Tools::generatePackageNumber($packageNumberInfo);
		$packageNumber[3] = $this->nonCodType;

		$firstAvailablePackageSeriesNumber = $this->orderRepository->many()
			->where('this.pplCode LIKE :s', ['s' => Strings::substring($packageNumber, 0, 4) . '%'])
			->orderBy(['this.pplCode' => 'DESC'])
			->firstValue('pplCode');

		$firstAvailablePackageSeriesNumber = $firstAvailablePackageSeriesNumber ? (int) $firstAvailablePackageSeriesNumber + 1 : (int) $packageNumber;

		$packageNumberInfo = new PackageNumberInfo(
			$this->packageSeriesNumberIdCod,
			Product::PPL_PARCEL_CZ_PRIVATE_COD,
			$this->depoCodeCod,
			true,
		);

		$packageNumber = Tools::generatePackageNumber($packageNumberInfo);
		$packageNumber[3] = $this->codType;

		$firstAvailablePackageSeriesNumberCod = $this->orderRepository->many()
			->where('this.pplCode LIKE :s', ['s' => Strings::substring($packageNumber, 0, 4) . '%'])
			->orderBy(['this.pplCode' => 'DESC'])
			->firstValue('pplCode');

		$firstAvailablePackageSeriesNumberCod = $firstAvailablePackageSeriesNumberCod ? (int) $firstAvailablePackageSeriesNumberCod + 1 : (int) $packageNumber;

		/** @var \Eshop\DB\Order $order */
		foreach ($orders as $order) {
			try {
				if ($order->pplCode) {
					continue;
				}

				$deliveryType = $order->purchase->deliveryType;

				if (!$deliveryType || $deliveryType->getPK() !== $pplDeliveryType) {
					continue;
				}

				$isCod = $pplCodType && $order->purchase->paymentType && $order->purchase->paymentType->getPK() === $pplCodType;

				$packageNumber = $isCod ? $firstAvailablePackageSeriesNumberCod : $firstAvailablePackageSeriesNumber;

				$purchase = $order->purchase;
				$deliveryAddress = $purchase->deliveryAddress ?? $purchase->billAddress;

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
					$cityRoutingResponse->Highlighted
				/** @codingStandardsIgnoreEnd */
				);

				if ($isCod) {
					$cashOnDeliveryPrice = $order->getTotalPriceVat();
					$cashOnDeliveryCurrency = Currency::CZK;
					$cashOnDeliveryVariableSymbol = (int)$order->code;

					if ($cashOnDeliveryVariableSymbol === 0) {
						$cashOnDeliveryVariableSymbol = $packageNumber;
					}

					$paymentInfo = new PaymentInfo($cashOnDeliveryPrice, $cashOnDeliveryCurrency, $cashOnDeliveryVariableSymbol);

					$package = new Package(
						(string) $packageNumber,
						Product::PPL_PARCEL_CZ_PRIVATE_COD,
						$order->purchase->note,
						$recipient,
						$cityRouting,
						null,
						null,
						null,
						$paymentInfo,
					);
				} else {
					$package = new Package((string) $packageNumber, Product::PPL_PARCEL_CZ_PRIVATE, $order->purchase->note, $recipient, $cityRouting);
				}

				/** Don´t delete array type!!! By doc createPackages returns array but that is NOT true in all cases! */
				$result = (array)$client->createPackages([$package]);

				\bdump($result);

				if ($result['Code'] !== '0') {
					$ordersWithError[] = $order;

					continue;
				}

				if ($isCod) {
					$firstAvailablePackageSeriesNumberCod++;
				} else {
					$firstAvailablePackageSeriesNumber++;
				}

				$order->update(['pplCode' => $result['ItemKey']]);
			} catch (\Throwable $e) {
				\bdump($e);

				$ordersWithError[] = $order;
			}
		}

		return $ordersWithError;
	}

	/**
	 * Get labels from DPD for orders
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
			foreach ($orders->where('this.pplCode IS NOT NULL')->toArray() as $order) {
				$purchase = $order->purchase;
				$deliveryAddress = $purchase->deliveryAddress ?? $purchase->billAddress;

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

				$packageNumber = $order->pplCode;

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
					$cashOnDeliveryPrice = $order->getTotalPriceVat();
					$cashOnDeliveryCurrency = Currency::CZK;
					$cashOnDeliveryVariableSymbol = (int)$order->code;

					if ($cashOnDeliveryVariableSymbol === 0) {
						$cashOnDeliveryVariableSymbol = (int)$packageNumber;
					}

					$paymentInfo = new PaymentInfo($cashOnDeliveryPrice, $cashOnDeliveryCurrency, $cashOnDeliveryVariableSymbol);

					$packages[] = new Package(
						$packageNumber,
						Product::PPL_PARCEL_CZ_PRIVATE_COD,
						$order->purchase->note,
						$recipient,
						$cityRouting,
						$this->sender,
						null,
						null,
						$paymentInfo,
					);
				} else {
					$packages[] = new Package($packageNumber, Product::PPL_PARCEL_CZ_PRIVATE, $order->purchase->note, $recipient, $cityRouting, $this->sender);
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

	protected function getClient(): Api
	{
		$client = new Api($this->login, $this->password, $this->idCustomer, $this->secureStorage);

		if (!$client->isHealthy()) {
			throw new \Exception('Connection to PPL service is not valid!');
		}

		return $client;
	}
}
