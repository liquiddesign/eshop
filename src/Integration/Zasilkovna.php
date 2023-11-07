<?php

namespace Eshop\Integration;

use Eshop\Admin\SettingsPresenter;
use Eshop\DB\AddressRepository;
use Eshop\DB\DeliveryRepository;
use Eshop\DB\OpeningHoursRepository;
use Eshop\DB\Order;
use Eshop\DB\OrderDeliveryStatus;
use Eshop\DB\OrderDeliveryStatusRepository;
use Eshop\DB\OrderRepository;
use Eshop\DB\PickupPointRepository;
use Eshop\DB\PickupPointTypeRepository;
use Eshop\DB\PurchaseRepository;
use GuzzleHttp\Client;
use Nette\Application\Application;
use Nette\DI\Container;
use Nette\Localization\Translator;
use Nette\Utils\Arrays;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Salamek\Zasilkovna\ApiRest;
use SimpleXMLElement;
use StORM\Collection;
use StORM\DIConnection;
use Tracy\Debugger;
use Tracy\ILogger;
use Web\DB\SettingRepository;

class Zasilkovna
{
	public const DAYS = [
		1 => 'monday',
		2 => 'tuesday',
		3 => 'wednesday',
		4 => 'thursday',
		5 => 'friday',
		6 => 'saturday',
		7 => 'sunday',
	];

	private PickupPointTypeRepository $pickupPointTypeRepository;

	private PickupPointRepository $pickupPointRepository;

	private SettingRepository $settingRepository;

	private AddressRepository $addressRepository;

	private OpeningHoursRepository $openingHoursRepository;

	private Translator $translator;

	private PurchaseRepository $purchaseRepository;

	private ApiRest $api;

	public function __construct(
		PickupPointTypeRepository $pickupPointTypeRepository,
		PickupPointRepository $pickupPointRepository,
		SettingRepository $settingRepository,
		AddressRepository $addressRepository,
		OpeningHoursRepository $openingHoursRepository,
		Translator $translator,
		PurchaseRepository $purchaseRepository,
		/* @codingStandardsIgnoreStart */
		protected Application $application,
		protected Container $container,
		protected OrderRepository $orderRepository,
		protected OrderDeliveryStatusRepository $orderDeliveryStatusRepository,
		protected DIConnection $connection,
		protected DeliveryRepository $deliveryRepository,
		/* @codingStandardsIgnoreEnd */
	) {
		$this->pickupPointRepository = $pickupPointRepository;
		$this->pickupPointTypeRepository = $pickupPointTypeRepository;
		$this->settingRepository = $settingRepository;
		$this->addressRepository = $addressRepository;
		$this->openingHoursRepository = $openingHoursRepository;
		$this->translator = $translator;
		$this->purchaseRepository = $purchaseRepository;
	}

	public function getApi(): ApiRest
	{
		if (!$zasilkovnaApiPassword = $this->settingRepository->getValueByName('zasilkovnaApiPassword')) {
			throw new ZasilkovnaException('Zasilkovna: API password missing.', ZasilkovnaException::MISSING_API_KEY);
		}

//		if (!$zasilkovnaApiPassword = $this->settingRepository->many()->where('name = "zasilkovnaApiPassword"')->first()) {
//			throw new ZasilkovnaException('Zasilkovna: API password missing.');
//		}

		return $this->api ??= new \Salamek\Zasilkovna\ApiRest($zasilkovnaApiPassword);
	}

	public function syncPickupPoints(): void
	{
		if (!$apiKey = $this->settingRepository->many()->where('name = "zasilkovnaApiKey"')->where('value IS NOT NULL')->first()) {
			throw new ZasilkovnaException('Missing API key! Set it in Admin.', ZasilkovnaException::MISSING_API_KEY);
		}

		if (!$zasilkovnaType = $this->pickupPointTypeRepository->one('zasilkovna')) {
			throw new ZasilkovnaException('Missing Zasilkovna PickupPointType!', ZasilkovnaException::MISSING_PICKUP_POINT_TYPE);
		}

		$client = new Client([
			'base_uri' => "http://www.zasilkovna.cz/api/v4/$apiKey->value/branch.json",
			'timeout' => 20.0,
		]);

		$response = $client->request('GET');

		if ($response->getStatusCode() !== 200) {
			throw new ZasilkovnaException('Invalid response from API!', ZasilkovnaException::INVALID_RESPONSE);
		}

		try {
			$responseContent = Json::decode($response->getBody()->getContents(), Json::FORCE_ARRAY);
		} catch (JsonException $e) {
			throw new ZasilkovnaException('Response JSON parse error!', ZasilkovnaException::JSON_PARSE_ERROR);
		}

		foreach ($responseContent['data'] as $value) {
			$address = $this->addressRepository->syncOne([
				'uuid' => 'zasilkovna_' . $value['id'],
				'street' => $value['street'],
				'city' => $value['city'],
				'zipcode' => $value['zip'],
				'state' => $value['country'],
				'note' => $value['special'] ?? null,
			]);

//			$open = true;
//			$openSince = isset($value['openSince']) ? new DateTime($value['openSince']) : null;
//			$openUntil = isset($value['openUntil']) ? new DateTime($value['openUntil']) : null;
//			$enterableUntil = isset($value['enterableUntil']) ? new DateTime($value['enterableUntil']) : null;
//			$today = (new DateTime())->setTime(0, 0);

//			if ($openSince && $openSince > $today) {
//				$open = false;
//			}
//
//			if ($openUntil && $openUntil < $today) {
//				$open = false;
//			}
//
//			if ($enterableUntil && $enterableUntil < $today) {
//				$open = false;
//			}

			$point = $this->pickupPointRepository->syncOne([
				'uuid' => 'zasilkovna_' . $value['id'],
				'code' => 'zasilkovna_' . $value['id'],
				'pickupPointType' => $zasilkovnaType->getPK(),
				'name' => [
					'cs' => $value['place'],
				],
				'address' => $address->getPK(),
				'gpsN' => \floatval($value['latitude']),
				'gpsE' => \floatval($value['longitude']),
				'hidden' => false,
				'description' => [
					'cs' => $this->translator->translate('.status', 'Stav') . ': ' . $value['status']['description'] . '  ' .
						(\is_array($value['directions']) ? null : \trim(\strip_tags($value['directions']))),
				],
			]);

			$openingHours = $value['openingHours'];

			if ($regularOpeningHours = ($openingHours['regular'] ?? null)) {
				foreach ($regularOpeningHours as $day => $hours) {
					if (\is_array($hours) || (\is_string($hours) && \strlen($hours) === 0)) {
						continue;
					}

					$dayIndex = \array_search($day, $this::DAYS);

					if ($dayIndex === false) {
						continue;
					}

					if (!$newOpeningHours = $this->processOpeningHours($hours)) {
						continue;
					}

					$existingOpeningHours = $this->openingHoursRepository->many()->where('day', $dayIndex)->where('fk_pickupPoint', $point->getPK())->first();

					if ($existingOpeningHours) {
						$existingOpeningHours->update($newOpeningHours);
					} else {
						$newOpeningHours += [
							'pickupPoint' => $point->getPK(),
							'day' => $dayIndex,
						];

						$this->openingHoursRepository->createOne($newOpeningHours);
					}
				}
			}

//			if ($upcomingOpeningHours = $openingHours['upcoming']['startDate'] ?? null) {
//
//			}

			$this->openingHoursRepository->many()->where('date IS NOT NULL')->where('fk_pickupPoint', $point->getPK())->delete();

			foreach ($openingHours['exceptions'] as $exceptions) {
				$exceptions = \is_array($exceptions) ? $exceptions : [$exceptions];

				foreach ($exceptions as $exception) {
					$date = $exception['date'] ?? null;

					if (!$date) {
						continue;
					}

					$openingHours = [
						'date' => $date,
						'pickupPoint' => $point->getPK(),
						'openFrom' => null,
						'openTo' => null,
						'pauseFrom' => null,
						'pauseTo' => null,
					];

					if ($hours = ($exception['hours'] ?? null)) {
						$localOpeningHours = $this->processOpeningHours($hours);

						if ($localOpeningHours) {
							$openingHours += $localOpeningHours;
						}
					}

					$existingOpeningHours = $this->openingHoursRepository->many()->where('date', $date)->where('fk_pickupPoint', $point->getPK())->first();

					if ($existingOpeningHours) {
						$existingOpeningHours->update($openingHours);
					} else {
						$this->openingHoursRepository->createOne($openingHours);
					}
				}
			}
		}

		$this->pickupPointRepository->clearCache();
	}

	/**
	 * @param \Eshop\DB\Order[] $orders
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function syncOrders($orders): void
	{
		if (!$zasilkovnaApiPassword = $this->settingRepository->many()->where('name = "zasilkovnaApiPassword"')->first()) {
			return;
		}

		foreach ($orders as $order) {
			try {
				$this->createZasilkovnaPackage($order, $zasilkovnaApiPassword);
			} catch (\Exception $e) {
				Debugger::log($e, ILogger::ERROR);
			}
		}
	}

	/**
	 * @param \StORM\Collection<\Eshop\DB\Order> $orders
	 * @return string Filename with PDF
	 * @throws \Eshop\Integration\ZasilkovnaException
	 */
	public function printLabels(Collection $orders): string
	{
		$api = $this->getApi();

		$orders->where('purchase.zasilkovnaId IS NOT NULL AND LENGTH(purchase.zasilkovnaId) > 0');
		$orders->where('this.zasilkovnaCompleted', true);
		$orders->where('this.zasilkovnaCode IS NOT NULL');

		$ordersArray = $orders->toArrayOf('zasilkovnaCode');

		$result = $api->packetsLabelsPdf(\array_values($ordersArray), 'A6 on A4');

		$tempFilename = \tempnam($this->container->getParameters()['tempDir'], 'zasilkovna');

		$this->application->onShutdown[] = function () use ($tempFilename): void {
			try {
				FileSystem::delete($tempFilename);
			} catch (\Throwable $e) {
				Debugger::log($e, ILogger::WARNING);
			}
		};

		FileSystem::write($tempFilename, \base64_decode($result));

		$this->orderRepository->many()->where('this.uuid', \array_keys($ordersArray))->update(['zasilkovnaPrinted' => true]);

		return $tempFilename;
	}

	public function syncOrdersStatus(): void
	{
		$api = $this->getApi();

		$orders = $this->orderRepository->many()
			->where('this.zasilkovnaCompleted', true)
			->where('this.zasilkovnaCode IS NOT NULL');

		foreach ($orders as $order) {
			$zasilkovnaCodes = \explode(',', $order->zasilkovnaCode);

			foreach ($zasilkovnaCodes as $zasilkovnaCode) {
				$orderStatus = $api->packetStatus($zasilkovnaCode);

				$this->orderDeliveryStatusRepository->syncOne([
					'service' => OrderDeliveryStatus::SERVICE_ZASILKOVNA,
					'order' => $order->getPK(),
					'createdTs' => $orderStatus['dateTime'],
					'status' => $orderStatus['statusCode'],
					'packageCode' => $zasilkovnaCode,
				]);
			}
		}
	}

	/**
	 * @param \Eshop\DB\Order $order
	 * @return array<string>|null
	 */
	public function getDeliveryStatusText(Order $order): ?array
	{
		if (!$order->zasilkovnaCode || $order->zasilkovnaError) {
			return null;
		}

		$result = [];

		foreach (\explode(',', $order->zasilkovnaCode) as $code) {
			$deliveryStatuses = $this->orderDeliveryStatusRepository->many()
				->setIndex('this.status')
				->where('this.service', OrderDeliveryStatus::SERVICE_ZASILKOVNA)
				->where('this.packageCode', $code)
				->toArray();

			if (isset($deliveryStatuses['7'])) {
				$result[$code] = $this->translator->translate('zasStatus.7', 'Zásilka byla předána příjemci.');
			} elseif (isset($deliveryStatuses['11'])) {
				$result[$code] = $this->translator->translate('zasStatus.11', 'Doručení zásilky bylo zrušeno.');
			} elseif (isset($deliveryStatuses['10'])) {
				$result[$code] = $this->translator->translate('zasStatus.10', 'Zásilka byla vrácena odesílateli.');
			} elseif (isset($deliveryStatuses['9'])) {
				$result[$code] = $this->translator->translate('zasStatus.9', 'Zásilka se vrací odesílateli.');
			} elseif (isset($deliveryStatuses['5'])) {
				$result[$code] = $this->translator->translate('zasStatus.5', 'Zásilka byla doručena na místo určení.');
			} elseif (isset($deliveryStatuses['4'])) {
				$result[$code] = $this->translator->translate('zasStatus.4', 'Zásilka je na cestě.');
			} elseif (isset($deliveryStatuses['2'])) {
				$result[$code] = $this->translator->translate('zasStatus.2', 'Přijetí zásilky na depu.');
			} elseif (isset($deliveryStatuses['3'])) {
				$result[$code] = $this->translator->translate('zasStatus.3', 'Zásilka se připravuje k odeslání.');
			} elseif (isset($deliveryStatuses['6'])) {
				$result[$code] = $this->translator->translate('zasStatus.6', 'Zásilka předána externímu přepravci.');
			} elseif (isset($deliveryStatuses['12'])) {
				$result[$code] = $this->translator->translate('zasStatus.12', 'Zásilka byla převzata a je na cestě na depo.');
			} elseif (isset($deliveryStatuses['1'])) {
				$result[$code] = $this->translator->translate('zasStatus.1', 'Příjem elektronických dat k zásilce. Čekáme na předání zboží od odesílatele k přepravě.');
			}
		}

		return $result;
	}

	/**
	 * @param string $openingHours
	 * @return array<string, string|null>|null
	 */
	private function processOpeningHours(string $openingHours): ?array
	{
		$openingHours = \explode(',', $openingHours);

		try {
			if (\count($openingHours) === 2) {
				[$openFrom, $pauseFrom] = \explode('–', $openingHours[0]);
				[$pauseTo, $openTo] = \explode('–', $openingHours[1]);
			} elseif (\count($openingHours) === 1) {
				[$openFrom, $openTo] = \explode('–', $openingHours[0]);
			} else {
				return null;
			}
		} catch (\Throwable $e) {
			return null;
		}

		return [
			'openFrom' => $openFrom,
			'pauseFrom' => $pauseFrom ?? null,
			'pauseTo' => $pauseTo ?? null,
			'openTo' => $openTo,
		];
	}

	private function createZasilkovnaPackage(Order $order, $zasilkovnaApiPassword): void
	{
		$eshop = $this->settingRepository->getValueByName('zasilkovnaSender');

		if (!$eshop) {
			throw new ZasilkovnaException('Zasilkovna: No sender available.');
		}

		/** @var \Eshop\DB\Purchase $purchase */
		$purchase = $this->purchaseRepository->many()->join(['orders' => 'eshop_order'], 'this.uuid = orders.fk_purchase')->where('orders.uuid', $order->getPK())->first();

		$client = new Client([
			'base_uri' => 'https://www.zasilkovna.cz/api/rest',
			'timeout' => 2.0,
			'verify' => true,
		]);

		$sumWeight = ($sumWeight = $purchase->getSumWeight()) > 0 ? $sumWeight / 1000 : 1;
		\bdump($sumWeight);

		$codPaymentType = $this->settingRepository->getValuesByName(SettingsPresenter::COD_TYPE);

		$payment = $order->getPayment();
		$cod = false;

		if ($payment && $codPaymentType) {
			$orderPaymentType = $payment->type;

			if ($orderPaymentType && Arrays::contains($codPaymentType, $orderPaymentType->getPK())) {
				$cod = true;
			}
		}

		$xml = '
			<createPacket>
			    <apiPassword>' . $zasilkovnaApiPassword->value . '</apiPassword>
			    <packetAttributes>
			        <number>' . $order->code . '</number>
			        <name>' . $purchase->fullname . '</name>
			        <email>' . $purchase->email . '</email>
			        <phone>' . $purchase->phone . '</phone>
			        <addressId>' . $purchase->zasilkovnaId . '</addressId>
			        <currency>' . $order->purchase->currency->code . '</currency>
			        <value>' . $order->getTotalPriceVat() . '</value>
			        ' . ($cod ? '<cod>' . \round($order->getTotalPriceVat()) . '</cod>' : null) . '
			        <eshop>' . $eshop . '</eshop>
			        <weight>' . ($sumWeight > 0 ? $sumWeight : 1) . '</weight>
			    </packetAttributes>
			</createPacket>
			';

		\bdump($xml);

		$options = [
			'headers' => [
				'Content-Type' => 'text/xml; charset=UTF8',
			],
			'body' => $xml,
		];

		$response = $client->request('POST', '', $options);
		$xmlResponse = new SimpleXMLElement($response->getBody()->getContents());

		\bdump($xmlResponse);

		if ((string)$xmlResponse->status !== 'ok') {
			Debugger::log($xmlResponse->result, ILogger::ERROR);

			$order->update(['zasilkovnaCompleted' => false, 'zasilkovnaError' => 'Chyba při odesílání pomocí API!',]);

			throw new ZasilkovnaException("Order {$order->code} error sending!", ZasilkovnaException::INVALID_RESPONSE);
		}

		$order->update(['zasilkovnaCompleted' => true, 'zasilkovnaError' => null, 'zasilkovnaCode' => (string) $xmlResponse->result->id]);
	}
}
