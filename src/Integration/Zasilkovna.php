<?php

namespace Eshop\Integration;

use Eshop\Admin\SettingsPresenter;
use Eshop\DB\AddressRepository;
use Eshop\DB\OpeningHoursRepository;
use Eshop\DB\Order;
use Eshop\DB\PickupPointRepository;
use Eshop\DB\PickupPointTypeRepository;
use Eshop\DB\PurchaseRepository;
use Eshop\Shopper;
use GuzzleHttp\Client;
use Nette\Localization\Translator;
use Nette\Utils\Arrays;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use SimpleXMLElement;
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

	private Shopper $shopper;

	public function __construct(
		PickupPointTypeRepository $pickupPointTypeRepository,
		PickupPointRepository $pickupPointRepository,
		SettingRepository $settingRepository,
		AddressRepository $addressRepository,
		OpeningHoursRepository $openingHoursRepository,
		Translator $translator,
		PurchaseRepository $purchaseRepository,
		Shopper $shopper
	) {
		$this->pickupPointRepository = $pickupPointRepository;
		$this->pickupPointTypeRepository = $pickupPointTypeRepository;
		$this->settingRepository = $settingRepository;
		$this->addressRepository = $addressRepository;
		$this->openingHoursRepository = $openingHoursRepository;
		$this->translator = $translator;
		$this->purchaseRepository = $purchaseRepository;
		$this->shopper = $shopper;
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
			$this->createZasilkovnaPackage($order, $zasilkovnaApiPassword);
		}
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
		/** @var \Eshop\DB\Purchase $purchase */
		$purchase = $this->purchaseRepository->many()->join(['orders' => 'eshop_order'], 'this.uuid = orders.fk_purchase')->where('orders.uuid', $order->getPK())->first();

		$client = new Client([
			'base_uri' => 'https://www.zasilkovna.cz/api/rest',
			'timeout' => 2.0,
			'verify' => true,
		]);

		$sumWeight = $purchase->getSumWeight();

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
			        <eshop>' . $this->shopper->getProjectUrl() . '</eshop>
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
			$order->update(['zasilkovnaCompleted' => false, 'zasilkovnaError' => 'Chyba při odesílání pomocí API!',]);

			throw new \Exception("Order {$order->code} error sending!");
		}

		$order->update(['zasilkovnaCompleted' => true, 'zasilkovnaError' => null,]);
	}
}
