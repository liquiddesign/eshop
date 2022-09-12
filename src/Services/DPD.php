<?php

declare(strict_types=1);

namespace Eshop\Services;

use Eshop\Providers\Helpers;
use Nette\Application\Application;
use Nette\DI\Container;
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
		?Application $application = null
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

		$dpdCodType = $this->settingRepository->getValueByName('codType');

		$ordersCompleted = [];
		$ordersIgnored = [];
		$ordersWithError = [];

		/** @var \Eshop\DB\Order $order */
		foreach ($orders as $order) {
			if (\in_array(false, Arrays::invoke($this->onBeforeOrderSent, $order), true)) {
				$ordersIgnored[] = $order;

				continue;
			}

			$orderDpdCodes = null;
			$purchase = $order->purchase;
			$deliveryAddress = $purchase->deliveryAddress ?? $purchase->billAddress;

			foreach ($order->packages as $package) {
				try {
					$delivery = $package->delivery;
					$deliveryType = $delivery->type;

					if (!$deliveryType || $deliveryType->getPK() !== $dpdDeliveryType) {
						$ordersIgnored[$order->code] = isset($ordersIgnored[$order->code]) ? $ordersIgnored[$order->code] + 1 : 1;

						continue;
					}

					if ($dpdCode = $delivery->getDpdCode()) {
						$ordersIgnored[$order->code] = isset($ordersIgnored[$order->code]) ? $ordersIgnored[$order->code] + 1 : 1;
						$orderDpdCodes .= "$dpdCode,";

						continue;
					}

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
						'Parcel_References_and_Insurance' => [
							[
								'REF1' => $order->code,
								'REF3' => $order->code,
								'REF4' => Strings::substring($order->purchase->deliveryNote, 0, self::NOTE_MAX_LENGTH),
							],
						],
					];

					if ($dpdCodType && $order->purchase->paymentType && $order->purchase->paymentType->getPK() === $dpdCodType) {
						$newShipmentVO['Additional_Services'] = [
							'COD' => (string)\number_format($order->getTotalPriceVat() / \count($order->packages), 2, '.', ''),
							'CURRENCY' => $order->purchase->currency->code,
							'PAYMENT' => 1,
							'PURPOSE' => $order->code,
						];
					}

					$request['_ShipmentDetailVO'][] = $newShipmentVO;

					\bdump($request);

					$result = $client->NewShipment($request);

					\bdump($result);

					/** @codingStandardsIgnoreStart */
					if ($dpdCode = $result->NewShipmentResult->NewShipmentResultVO->ParcelVO->PARCELNO) {
						/** @codingStandardsIgnoreEnd */
						$delivery->update(['dpdCode' => $dpdCode, 'dpdError' => false,]);

						$ordersCompleted[$order->code] = isset($ordersCompleted[$order->code]) ? $ordersCompleted[$order->code] + 1 : 1;
						$orderDpdCodes .= "$dpdCode,";
					} else {
						$delivery->update(['dpdError' => true]);

						$ordersWithError[$order->code] = isset($ordersWithError[$order->code]) ? $ordersWithError[$order->code] + 1 : 1;
					}
				} catch (\Throwable $e) {
					$delivery->update(['dpdError' => true]);

					$ordersWithError[$order->code] = isset($ordersWithError[$order->code]) ? $ordersWithError[$order->code] + 1 : 1;

					\bdump($e);

					$tempDir = $this->container->getParameters()['tempDir'] . '/dpd';

					FileSystem::createDir($tempDir);

					FileSystem::write("$tempDir/" . $delivery->getPK(), $e->getMessage());
				}
			}

			$order->update([
				'dpdCode' => $orderDpdCodes,
				'dpdError' => isset($ordersWithError[$order->code]),
			]);
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
	
	public function getStatus(array $list): void
	{
		$client = $this->getClient();
		
		$result = $client->GetPickupStatus([
			'login' => $this->login,
			'password' => $this->password,
			'statusList' => $list,
		]);
		
		\bdump($result);
		
		return;
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

		FileSystem::write($filename, $pdf->Output());

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
}
