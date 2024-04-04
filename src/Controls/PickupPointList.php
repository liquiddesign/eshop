<?php
declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\PickupPointRepository;
use GuzzleHttp\Client;
use Nette;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\ICollection;

class PickupPointList extends \Grid\Datalist
{
	private PickupPointRepository $pickupPointRepository;

	private Nette\Localization\Translator $translator;

	/**
	 * @var array|array<float>|null
	 */
	private ?array $gpsLocation;

	public function __construct(PickupPointRepository $pickupPointRepository, Nette\Localization\Translator $translator, Nette\Http\Request $request)
	{
		$this->pickupPointRepository = $pickupPointRepository;
		$this->translator = $translator;

		//@TODO only for testing on localhost, in production remove default GPS
		$this->gpsLocation = $this->getGpsByIP($request->getRemoteAddress()) ?? ['N' => 49.1920700, 'E' => 16.6125203];

		$source = $pickupPointRepository->getCollection();

		//latitude => N
		//longitude => E

		if ($this->gpsLocation) {
			// scaling factor
			$sf = 3.14159 / 180;
			// earth radius in kilometers, approximate
			$er = 10219;

			$source->where('CEIL(ACOS(SIN(gpsN*:sf)*SIN(:lat*:sf) + COS(gpsN*:sf)*COS(:lat*:sf)*COS((gpsE-:lon)*:sf)) * 10000) >= 0', [
				'er' => $er,
				'sf' => $sf,
				'lon' => $this->gpsLocation['E'],
				'lat' => $this->gpsLocation['N'],
			]);
			$source->select(['distance' => 'CEIL(ACOS(SIN(gpsN*:sf)*SIN(:lat*:sf) + COS(gpsN*:sf)*COS(:lat*:sf)*COS((gpsE-:lon)*:sf)) * 10000)']);
		}

		parent::__construct($source, 100);

		$this->addFilterExpression('city', function (ICollection $source, $value): void {
			$source->where('address.city', $value);
		}, '');

		$this->addFilterExpression('name', function (Collection $source, $value): void {
			$suffix = $source->getConnection()->getMutationSuffix();
			$source->where("name$suffix LIKE :n", ['n' => "%$value%"]);
		}, '');

		$this->addOrderExpression('distance', function (ICollection $source, $dir): void {
			if ($this->gpsLocation) {
				$source->orderBy(['ACOS(SIN(gpsN*:sf)*SIN(:lat*:sf) + COS(gpsN*:sf)*COS(:lat*:sf)*COS((gpsE-:lon)*:sf))' => $dir]);
			}
		});

		$this->setDefaultOrder('distance');
		$this->setDefaultOnPage(100);

		$cities = $pickupPointRepository->getCitiesArrayForSelect();

		/** @var \Forms\Form $form */
		$form = $this->getFilterForm();

		$form->addText('name', $translator->translate('pointList.name', 'Název'));
		$form->addSelect('city', $translator->translate('pointList.city', 'Město'), \array_combine(\array_values($cities), \array_values($cities)))
			->setPrompt($translator->translate('pointList.all', 'Vše'));
		$form->addSubmit('submit', $translator->translate('pointList.showPlaces', 'Zobrazit místa'));
	}

	public function render(): void
	{
		$openingHours = $this->pickupPointRepository->getAllOpeningHours();

		$weekDay = (int) (new \Carbon\Carbon())->format('w') - 1;
		$currentDate = (new \Carbon\Carbon())->format('Y-m-d');
		$nextDate = (new \Carbon\Carbon())->modify('+1 day')->format('Y-m-d');

		$openingHoursTexts = [];

		foreach (\array_keys($this->getItemsOnPage()) as $key) {
			//today
			if (isset($openingHours[$key]['special'][$currentDate]->openFrom)) {
				$openingHoursTexts[$key] = $this->translator->translate('pickupPointList.todayFrom', 'dnes od') . ' ' .
					Strings::substring($openingHours[$key]['special'][$currentDate]->openFrom, 0, -3);
				$openingHoursTexts[$key] .= isset($openingHours[$key]['special'][$currentDate]->openTo) ?
					' ' . $this->translator->translate('pickupPointList.to', 'do') . ' ' . Strings::substring($openingHours[$key]['special'][$currentDate]->openTo, 0, -3) : '';
				$openingHoursTexts[$key] .= isset($openingHours[$key]['special'][$currentDate]->pauseFrom)
					? '<br>(' . $this->translator->translate('pickupPointList.pauseFrom', 'pauza od') . ' ' . Strings::substring($openingHours[$key]['special'][$currentDate]->pauseFrom, 0, -3) : '';
				$openingHoursTexts[$key] .= isset($openingHours[$key]['special'][$currentDate]->pauseTo)
					? ' ' . $this->translator->translate('pickupPointList.to', 'do') . ' ' . Strings::substring($openingHours[$key]['special'][$currentDate]->pauseTo, 0, -3) . ')' : '';
			} elseif (isset($openingHours[$key]['special'][$currentDate])) {
				$openingHoursTexts[$key] = '<span class="text-danger">' . $this->translator->translate('pickupPointList.todaySClosed', 'dnes mimořádně zavřeno') . '</span>';
			} elseif (isset($openingHours[$key]['normal'][$weekDay]->openFrom)) {
				$openingHoursTexts[$key] = $this->translator->translate('pickupPointList.todayFrom', 'dnes od') . ' ' . Strings::substring($openingHours[$key]['normal'][$weekDay]->openFrom, 0, -3);
				$openingHoursTexts[$key] .= isset($openingHours[$key]['normal'][$weekDay]->openTo) ?
					' ' . $this->translator->translate('pickupPointList.to', 'do') . ' ' . Strings::substring($openingHours[$key]['normal'][$weekDay]->openTo, 0, -3) : '';
				$openingHoursTexts[$key] .= isset($openingHours[$key]['normal'][$weekDay]->pauseFrom) ?
					'<br>(' . $this->translator->translate('pickupPointList.pauseFrom', 'pauza od') . ' ' . Strings::substring($openingHours[$key]['normal'][$weekDay]->pauseFrom, 0, -3) : '';
				$openingHoursTexts[$key] .= isset($openingHours[$key]['normal'][$weekDay]->pauseTo) ?
					' ' . $this->translator->translate('pickupPointList.to', 'do') . ' ' . Strings::substring($openingHours[$key]['normal'][$weekDay]->pauseTo, 0, -3) . ')' : '';
			} else {
				$openingHoursTexts[$key] = '<span class="text-danger">' . $this->translator->translate('pickupPointList.todayClosed', 'dnes zavřeno') . '</span>';
			}

			//tomorrow
			if (isset($openingHours[$key]['special'][$nextDate]->openFrom)) {
				$openingHoursTexts[$key] .= '<br>' . $this->translator->translate('pickupPointList.tomorrowFrom', 'zítra od') . ' ' .
					Strings::substring($openingHours[$key]['special'][$nextDate]->openFrom, 0, -3);
				$openingHoursTexts[$key] .= isset($openingHours[$key]['special'][$nextDate]->openTo) ? ' ' . $this->translator->translate('pickupPointList.to', 'do') . ' ' .
					Strings::substring($openingHours[$key]['special'][$nextDate]->openTo, 0, -3) : '';
			} elseif (isset($openingHours[$key]['special'][$nextDate])) {
				$openingHoursTexts[$key] .= '<br><span class="text-danger">' . $this->translator->translate('pickupPointList.tomorrowSClosed', 'zítra mimořádně zavřeno') . '</span>';
			} elseif (isset($openingHours[$key]['normal'][$weekDay + 1]->openFrom)) {
				$openingHoursTexts[$key] .= '<br>' . $this->translator->translate('pickupPointList.tomorrowFrom', 'zítra od') . ' ' .
					Strings::substring($openingHours[$key]['normal'][$weekDay + 1]->openFrom, 0, -3);
				$openingHoursTexts[$key] .= isset($openingHours[$key]['normal'][$weekDay + 1]->openTo) ? ' ' . $this->translator->translate('pickupPointList.to', 'do') . ' ' .
					Strings::substring($openingHours[$key]['normal'][$weekDay + 1]->openTo, 0, -3) : '';
			} else {
				$openingHoursTexts[$key] .= '<br><span class="text-danger">' . $this->translator->translate('pickupPointList.tomorrowClosed', 'zítra zavřeno') . '</span>';
			}
		}

		$this->template->gps = $this->gpsLocation;
		$this->template->openingHours = $openingHours;
		$this->template->openingHoursTexts = $openingHoursTexts;
		$this->template->paginator = $this->getPaginator();

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/pickupPointList.latte');
	}

	public function handleClearFilters(): void
	{
		$this->filters = [];
		$this->redirect('this');
	}

	/**
	 * @param string $ip
	 * @return array<string>|null
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 * @throws \Nette\Utils\JsonException
	 */
	private function getGpsByIP(string $ip): ?array
	{
		$client = new Client([
			'base_uri' => 'http://ip-api.com/json/',
			'timeout' => 2.0,
		]);

		$response = $client->request('GET', $ip);
		$responseContent = Nette\Utils\Json::decode($response->getBody()->getContents());

		return $response->getStatusCode() === 200 && $responseContent->status === 'success' ? ['N' => $responseContent->lat, 'E' => $responseContent->lon] : null;
	}
}
