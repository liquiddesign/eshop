<?php

declare(strict_types=1);

namespace Eshop\Services;

use Eshop\Providers\Helpers;
use StORM\Collection;
use Web\DB\SettingRepository;

class DPD
{
	private string $url;

	private string $login;

	private string $password;

	private string $idCustomer;

	private string $labelPrintType;

	private SettingRepository $settingRepository;

	public function __construct(
		string $url,
		string $login,
		string $password,
		string $idCustomer,
		string $labelPrintType = 'PDF',
		?SettingRepository $settingRepository = null
	) {
		$this->url = $url;
		$this->login = $login;
		$this->password = $password;
		$this->idCustomer = $idCustomer;
		$this->settingRepository = $settingRepository;
		$this->labelPrintType = $labelPrintType;
	}

	public function getDPDDeliveryTypePK(): ?string
	{
		return $this->settingRepository->getValueByName('dpdDeliveryType');
	}

	/**
	 * Send orders DPD
	 * @param \StORM\Collection<\Eshop\DB\Order> $orders
	 * @throws \Exception
	 */
	public function syncOrders(Collection $orders): ?\stdClass
	{
		$client = $this->getClient();

		$dpdDeliveryType = $this->getDPDDeliveryTypePK();

		if (!$dpdDeliveryType) {
			throw new \Exception('Delivery type for DPD service is not set!');
		}

		$dpdCodType = $this->settingRepository->getValueByName('codType');

		try {
			$request = [
				'NewShipment' => [
					'login' => $this->login,
					'password' => $this->password,
					'ShipmentDetailVO' => [],
				],
			];

			/** @var \Eshop\DB\Order $order */
			foreach ($orders as $order) {
				$deliveryType = $order->purchase->deliveryType;

				if (!$deliveryType || $deliveryType->getPK() !== $dpdDeliveryType) {
					continue;
				}

				$purchase = $order->purchase;
				$deliveryAddress = $purchase->deliveryAddress ?? $purchase->billAddress;

				$newShipmentVO = [
					'ID_Customer' => $this->idCustomer,
					'REF1' => '***TEST-LQD***' . $order->code,
					'Receiver' => [
						'RNAME1' => $purchase->fullname,
						'RSTREET' => $deliveryAddress ? $deliveryAddress->street : null,
						'RCITY' => $deliveryAddress ? $deliveryAddress->city : null,
						'RPOSTAL' => $deliveryAddress ? $deliveryAddress->zipcode : null,
						'RCOUNTRY' => $deliveryAddress && $deliveryAddress->state ? $deliveryAddress->state : 'CZ',
						'RCONTACT' => $purchase->fullname,
						'RPHONE' => $purchase->phone,
						'REMAIL' => $purchase->email,
					],
					'Parcel_References_and_insurance' => [
						'REF1' => $order->code,
					],
				];

				if ($dpdCodType) {
					$newShipmentVO['Additional_Services'] = [
						'COD' => $order->getTotalPriceVat(),
					];
				}

				$request['NewShipment']['ShipmentDetailVO'] = $newShipmentVO;
			}

			\bdump($request);

			$result = (array) $client->__soapCall('NewShipment', $request);

			\bdump($result);

			return $result['NewShipmentResult'];
		} catch (\Throwable $e) {
			\bdump($e);

			return null;
		}
	}

	/**
	 * Get labels from DPD for orders
	 * @param \StORM\Collection<\Eshop\DB\Order> $orders
	 * @param string|null $printType
	 */
	public function getLabels(Collection $orders, ?string $printType = null): ?\stdClass
	{
		$client = $this->getClient();

		$ids = $orders->where('this.dpdCode IS NOT NULL')->toArrayOf('dpdCode', [], true);

		if (!$ids) {
			return null;
		}

		try {
			$result = (array) $client->__soapCall('GetLabel', array(
				'GetLabel' => array (
					'login' => $this->login,
					'password' => $this->password,
					'type' => $printType ?? $this->labelPrintType,
					'parcelno' => $ids,
				)
			));

			\bdump($result);

			return $result['GetCustomerDSWResult'];
		} catch (\Throwable $e) {
			return null;
		}
	}

	public function getCustomers(): ?\stdClass
	{
		$client = $this->getClient();

		try {
			$result = (array) $client->__soapCall('GetCustomerDSW', array(
				'GetCustomerDSW' => array (
					'login' => $this->login,
					'password' => $this->password,
				)
			));

			return $result['GetCustomerDSWResult'];
		} catch (\Throwable $e) {
			return null;
		}
	}

	protected function getClient(): \SoapClient
	{
		return Helpers::createSoapClient($this->url);
	}
}
