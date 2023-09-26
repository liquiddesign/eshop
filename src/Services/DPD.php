<?php

declare(strict_types=1);

namespace Eshop\Services;

use Eshop\Admin\SettingsPresenter;
use Eshop\DB\Order;
use Eshop\DB\OrderDeliveryStatus;
use Eshop\DB\OrderDeliveryStatusRepository;
use Eshop\DB\OrderRepository;
use Eshop\Providers\Helpers;
use Eshop\Services\DPD\DeclaredSender;
use Nette\Application\Application;
use Nette\DI\Container;
use Nette\Localization\Translator;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use setasign\Fpdi\Fpdi;
use StORM\Collection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

/**
 * Supports only Normal packages at the moment!
 */
class DPD
{
	public const NOTE_MAX_LENGTH = 35;

	/** @var array<callable(): bool> */
	public array $onBeforeOrdersSent = [];

	/** @var array<callable(\Eshop\DB\Order): bool> */
	public array $onBeforeOrderSent = [];

	private string $url;

	private string $login;

	private string $password;

	private string $idCustomer;

	private string $idAddress;

	private string $labelPrintType;

	private SettingRepository $settingRepository;

	private Container $container;

	private Application $application;

	public function __construct(
		string $url,
		string $login,
		string $password,
		string $idCustomer,
		string $idAddress,
		string $labelPrintType = 'PDF',
		?SettingRepository $settingRepository = null,
		?Container $container = null,
		?Application $application = null,
		/** @codingStandardsIgnoreStart */
		private ?OrderRepository $orderRepository = null,
		private ?OrderDeliveryStatusRepository $orderDeliveryStatusRepository = null,
		private ?Translator $translator = null,
		/** @codingStandardsIgnoreEnd */
	) {
		$this->url = $url;
		$this->login = $login;
		$this->password = $password;
		$this->idCustomer = $idCustomer;
		$this->idAddress = $idAddress;
		$this->settingRepository = $settingRepository;
		$this->labelPrintType = $labelPrintType;
		$this->container = $container;
		$this->application = $application;
	}

	public function getDeclaredSender(): ?DeclaredSender
	{
		return null;
	}

	public function getDpdDeliveryTypePK(): ?string
	{
		return $this->settingRepository->getValueByName('dpdDeliveryType');
	}

	/**
	 * Send orders DPD
	 * @param \StORM\Collection<\Eshop\DB\Order> $orders
	 * @return array<mixed>
	 * @throws \Exception
	 */
	public function syncOrders(Collection $orders): array
	{
		if (\in_array(false, Arrays::invoke($this->onBeforeOrdersSent), true)) {
			throw new \Exception('Not allowed');
		}

		$client = $this->getClient();

		$dpdDeliveryType = $this->getDpdDeliveryTypePK();

		if (!$dpdDeliveryType) {
			throw new \Exception('Delivery type for DPD service is not set!');
		}

		$dpdCodType = $this->settingRepository->getValuesByName(SettingsPresenter::COD_TYPE);

		$ordersCompleted = [];
		$ordersIgnored = [];
		$ordersWithError = [];

		/** @var \Eshop\DB\Order $order */
		foreach ($orders as $order) {
			if (\in_array(false, Arrays::invoke($this->onBeforeOrderSent, $order), true)) {
				$ordersIgnored[] = $order;

				continue;
			}

			$purchase = $order->purchase;
			$deliveryAddress = $purchase->deliveryAddress ?? $purchase->billAddress;

			if ($order->getDpdCode()) {
				$ordersIgnored[] = $order;

				continue;
			}

			try {
				$request = [
					'login' => $this->login,
					'password' => $this->password,
					'_ShipmentDetailVO' => [],
				];

				$newShipmentVO = [
					'ID_Customer' => $this->idCustomer,
					'ID_Customer_Address' => $this->idAddress,
					'REF1' => $order->code,
					'REF3' => $order->code,
					'REF4' => Strings::substring($order->purchase->deliveryNote, 0, self::NOTE_MAX_LENGTH),
					'Receiver' => [
						'RNAME1' => $purchase->fullname,
						'RSTREET' => $deliveryAddress ? $deliveryAddress->street : '',
						'RCITY' => $deliveryAddress ? $deliveryAddress->city : '',
						'RPOSTAL' => $deliveryAddress ? $deliveryAddress->zipcode : '',
						'RCOUNTRY' => $deliveryAddress && $deliveryAddress->state ? $deliveryAddress->state : 'CZ',
						'RPHONE' => $purchase->phone,
						'REMAIL' => $purchase->email,
					],
				];

				$parcelReferences = [];

				foreach ($order->packages as $package) {
					$delivery = $package->delivery;
					$deliveryType = $delivery->type;

					if (!$deliveryType || $deliveryType->getPK() !== $dpdDeliveryType) {
						continue;
					}

					if ($delivery->getDpdCode()) {
						continue;
					}

					$parcelReferences[] = [
						'REF1' => $order->code . '_' . $package->getPK(),
						'REF3' => $order->code,
						'REF4' => Strings::substring($order->purchase->deliveryNote, 0, self::NOTE_MAX_LENGTH),
					];
				}

				if (!$parcelReferences) {
					$ordersIgnored[] = $order;

					continue;
				}

				$newShipmentVO['Parcel_References_and_Insurance'] = $parcelReferences;

				if ($dpdCodType && $order->purchase->paymentType && Arrays::contains($dpdCodType, $order->purchase->paymentType->getPK())) {
					$newShipmentVO['Additional_Services'] = [
						'COD' => (string)\number_format($order->getTotalPriceVat(), 2, '.', ''),
						'CURRENCY' => $order->purchase->currency->code,
						'PAYMENT' => 1,
						'PURPOSE' => $order->code,
					];
				}

				if ($declaredSender = $this->getDeclaredSender()) {
					$newShipmentVO['Sender_Declared'] = $declaredSender->getShipmentArray();
				}

				$request['_ShipmentDetailVO'][] = $newShipmentVO;

				\bdump($request);

				$result = $client->NewShipment($request);

				\bdump($result);

				/** @codingStandardsIgnoreStart */
				if (\is_array($result->NewShipmentResult->NewShipmentResultVO)) {
					$dpdCodes = null;

					foreach ($result->NewShipmentResult->NewShipmentResultVO as $parcel) {
						$dpdCodes .= $parcel->ParcelVO->PARCELNO . ',';
					}
					/** @codingStandardsIgnoreEnd */

					$order->update(['dpdCode' => $dpdCodes, 'dpdError' => false,]);

					$ordersCompleted[] = $order;
				/** @codingStandardsIgnoreStart */
				} elseif ($dpdCode = $result->NewShipmentResult->NewShipmentResultVO->ParcelVO->PARCELNO) {
					/** @codingStandardsIgnoreEnd */
					$order->update(['dpdCode' => $dpdCode, 'dpdError' => false,]);

					$ordersCompleted[] = $order;
				} else {
					$order->update(['dpdError' => true]);

					$ordersWithError[] = $order;
				}
			} catch (\Throwable $e) {
				$order->update(['dpdError' => true]);

				$ordersWithError[] = $order;

				\bdump($e);

				$tempDir = $this->container->getParameters()['tempDir'] . '/dpd';

				FileSystem::createDir($tempDir);

				FileSystem::write("$tempDir/" . $order->getPK(), $e->getMessage());
			}
		}

		return [
			'completed' => $ordersCompleted,
			'failed' => $ordersWithError,
			'ignored' => $ordersIgnored,
		];
	}

	/**
	 * Get labels from DPD for orders
	 * @param \StORM\Collection<\Eshop\DB\Order> $orders
	 * @param string|null $printType
	 * @param array<mixed> $individualFiles
	 */
	public function getLabels(Collection $orders, ?string $printType = null, array &$individualFiles = []): ?string
	{
		$client = $this->getClient();

		$ids = $orders
			->where('this.dpdCode IS NOT NULL')
			->where('this.dpdError', false)
			->toArrayOf('dpdCode', [], true);

		if (!$ids) {
			return null;
		}

		$dpdCodes = [];

		foreach ($ids as $id) {
			$tempIds = \explode(',', $id);

			foreach ($tempIds as $tempId) {
				if (!$tempId) {
					continue;
				}

				$dpdCodes[] = $tempId;
			}
		}

		try {
			$result = $client->GetLabel([
				'login' => $this->login,
				'password' => $this->password,
				'type' => $printType ?? $this->labelPrintType,
				'parcelno' => $dpdCodes,
			]);

			\bdump($result);

			/** @codingStandardsIgnoreStart */
			$result = $result->GetLabelResult->LabelVO;
			/** @codingStandardsIgnoreEnd */

			$pdf = new \Jurosh\PDFMerge\PDFMerger();

			$dir = $this->container->getParameters()['tempDir'] . '/pdfs/';
			FileSystem::createDir($dir);

			if (!\is_array($result)) {
				$result = [$result];
			}

			foreach ($result as $item) {
				$filename = \tempnam($dir, 'dpd');

				$this->application->onShutdown[] = function () use ($filename): void {
					if (!\is_file($filename)) {
						return;
					}

					FileSystem::delete($filename);
				};

				FileSystem::write($filename, \base64_decode($item->BASE64));

				$individualFiles[] = $filename;
				$pdf->addPDF($filename);
			}

			$filename = \tempnam($dir, 'dpd');

			$this->application->onShutdown[] = function () use ($filename): void {
				if (!\is_file($filename)) {
					return;
				}

				FileSystem::delete($filename);
			};

			$pdf->merge('file', $filename);

			$orders->clear(true)->where('this.dpdCode', $ids)->update(['this.dpdPrinted' => true]);

			return $filename;
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::ERROR);
			\bdump($e);

			return null;
		}
	}

	/**
	 * @param array<string, \Eshop\DB\Order>|null $orders
	 * @throws \Exception
	 */
	public function syncOrdersStatus(?array $orders = null): void
	{
		$client = $this->getClient();

		$ordersByPackages = [];

		$orders ??= $this->orderRepository->many()
			->where('this.dpdCode IS NOT NULl')
			->where('this.dpdError', false)
			->toArray();

		foreach ($orders as $order) {
			if (!$order->dpdCode || $order->dpdError) {
				continue;
			}

			foreach (\explode(',', $order->dpdCode) as $dpdCode) {
				$ordersByPackages[$dpdCode] = $order;
			}
		}

		$ordersDeliveryStatuses = [];

		foreach ($this->orderDeliveryStatusRepository->many()->where('this.fk_order', \array_keys($orders)) as $orderDeliveryStatus) {
			$ordersDeliveryStatuses[$orderDeliveryStatus->getValue('order')][$orderDeliveryStatus->status] = $orderDeliveryStatus;
		}

		$allTrackingDetails = [];

		foreach (\array_chunk($ordersByPackages, 100, true) as $chunkedOrders) {
			try {
				$response = $client->GetTrackingByParcelno([
					'login' => $this->login,
					'password' => $this->password,
					'parcelno' => \array_keys($chunkedOrders),
				]);
			} catch (\Exception $e) {
				\bdump($e);

				throw new \Exception('Invalid request: ' . $e->getMessage());
			}

			// phpcs:ignore
			if (!isset($response->GetTrackingByParcelnoResult->TrackingDetailVO) || !\is_array($response->GetTrackingByParcelnoResult->TrackingDetailVO)) {
				throw new \Exception('Invalid response data');
			}

			// phpcs:ignore
			$allTrackingDetails = \array_merge($allTrackingDetails, $response->GetTrackingByParcelnoResult->TrackingDetailVO);
		}

		// phpcs:ignore
		foreach ($allTrackingDetails as $trackingDetail) {
			// phpcs:ignore
			$order = $ordersByPackages[$trackingDetail->PARCELNO] ?? null;

			if (!$order) {
				continue;
			}

			$orderDeliverStatuses = $ordersDeliveryStatuses[$order->getPK()] ?? [];

			// phpcs:ignore
			if (isset($orderDeliverStatuses[$trackingDetail->SCANCODE])) {
				continue;
			}

			// phpcs:ignore
			switch ($trackingDetail->SCANCODE) {
				case '02':
				case '03':
				case '04':
				case '05':
				case '06':
				case '08':
				case '09':
				case '10':
				case '13':
				case '14':
				case '23':
					// phpcs:ignore
					$ordersDeliveryStatuses[$order->getPK()][$trackingDetail->SCANCODE] = $this->orderDeliveryStatusRepository->createOne([
						'service' => OrderDeliveryStatus::SERVICE_DPD,
						'order' => $order->getPK(),
						// phpcs:ignore
						'createdTs' => $trackingDetail->SCANDATETIME ?? null,
						// phpcs:ignore
						'status' => $trackingDetail->SCANCODE,
						// phpcs:ignore
						'packageCode' => $trackingDetail->PARCELNO,
					]);
			}
		}
	}

	public function getIsOrderDelivered(Order $order): ?bool
	{
		if (!$order->dpdCode || $order->dpdError) {
			return null;
		}

		$delivered = true;

		foreach (\explode(',', $order->dpdCode) as $dpdCode) {
			$deliveryStatuses = $this->orderDeliveryStatusRepository->many()->setIndex('this.status')->where('this.packageCode', $dpdCode)->toArray();

			if (!isset($deliveryStatuses['13']) && !isset($deliveryStatuses['23'])) {
				$delivered = false;

				break;
			}
		}

		return $delivered;
	}

	/**
	 * @param \Eshop\DB\Order $order
	 * @return array<string>|null
	 */
	public function getDeliveryStatusText(Order $order): ?array
	{
		if (!$order->dpdCode || $order->dpdError) {
			return null;
		}

		$result = [];

		foreach (\explode(',', $order->dpdCode) as $dpdCode) {
			$deliveryStatuses = $this->orderDeliveryStatusRepository->many()->setIndex('this.status')->where('this.packageCode', $dpdCode)->toArray();

			if (isset($deliveryStatuses['13'])) {
				$result[$dpdCode] = $this->translator->translate('dpdStatus.13', 'Balíček jsme úspěšně doručili.');
			} elseif (isset($deliveryStatuses['23'])) {
				$result[$dpdCode] = $this->translator->translate('dpdStatus.23', 'Doručení do výdejního místa.');
			} elseif (isset($deliveryStatuses['14'])) {
				$result[$dpdCode] = $this->translator->translate('dpdStatus.14', 'Bohužel se nám nepodařilo balíček doručit.');
			} elseif (isset($deliveryStatuses['04'])) {
				$result[$dpdCode] = $this->translator->translate('dpdStatus.04', 'Balíček se nepodařilo doručit a vrátili jsme ho na depo.');
			} elseif (isset($deliveryStatuses['03'])) {
				$result[$dpdCode] = $this->translator->translate('dpdStatus.03', 'Balíček jsme předali kurýrovi. Dnes ho můžete čekat.');
			} elseif (isset($deliveryStatuses['02'])) {
				$result[$dpdCode] = $this->translator->translate('dpdStatus.02', 'Balíček už je v našem depu.');
			} elseif (isset($deliveryStatuses['05'])) {
				$result[$dpdCode] = $this->translator->translate('dpdStatus.05', 'Balíček už jsme vyzvedli u odesílatele.');
			}
		}

		return $result;
	}
	
	public function deletePackages(array $list): void
	{
		$client = $this->getClient();
		
		$result = $client->DeleteParcelByParcelno([
			'login' => $this->login,
			'password' => $this->password,
			'parcelno' => $list,
		]);
		
		\bdump($result);
		
		return;
	}
	
	public function deletePickups(array $list): void
	{
		$client = $this->getClient();
		
		$result = $client->DeletePickup([
			'login' => $this->login,
			'password' => $this->password,
			'deleteList' => $list,
		]);
		
		\bdump($result);
		
		return;
	}

	/**
	 * @param array<string> $filenames
	 */
	public function mergePdfs(array $filenames): ?string
	{
		if (!$filenames) {
			return null;
		}

		$pdf = new Fpdi();

		$i = 0;

		foreach ($filenames as $filename) {
			if ($i % 4 === 0) {
				$pdf->addPage();
			}

			$pdf->setSourceFile($filename);
			$tplIdxA = $pdf->importPage(1, '/MediaBox');

			$x = 0;
			$y = 0;

			switch ($i % 4) {
				case 0:
					$x = 10;
					$y = 10;

					break;
				case 1:
					$x = 110;
					$y = 10;

					break;
				case 2:
					$x = 10;
					$y = 150;

					break;
				case 3:
					$x = 110;
					$y = 150;

					break;
			}

			$pdf->useTemplate($tplIdxA, $x, $y, 90);

			$i++;
		}

		$filename = \tempnam($this->container->getParameters()['tempDir'] . '/pdfs/', 'dpd');

		$this->application->onShutdown[] = function () use ($filename): void {
			if (!\is_file($filename)) {
				return;
			}

			FileSystem::delete($filename);
		};

		FileSystem::write($filename, $pdf->Output('S'));

		return $filename;
	}

	public function getCustomers(): ?\stdClass
	{
		$client = $this->getClient();

		try {
			return $client->GetCustomerDSW([
				'login' => $this->login,
				'password' => $this->password,
			]);
		} catch (\Throwable $e) {
			return null;
		}
	}

	protected function getClient(): \SoapClient
	{
		return Helpers::createSoapClient($this->url);
	}

	protected function getProductApiClient(): \SoapClient
	{
		return Helpers::createSoapClient('https://reg-prijemce.dpd.cz/Product_api_v1_1/Product_api.svc?singleWsdl');
	}
}
