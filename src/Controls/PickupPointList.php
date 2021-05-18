<?php
declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\OpeningHoursRepository;
use Eshop\DB\PickupPointRepository;
use Eshop\DB\PickupPointTypeRepository;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use StORM\ICollection;
use Nette;
use GuzzleHttp\Client;

class PickupPointList extends \Grid\Datalist
{
	private PickupPointRepository $pickupPointRepository;

	private PickupPointTypeRepository $pickupPointTypeRepository;

	private OpeningHoursRepository $openingHoursRepository;

	private Nette\Localization\Translator $translator;

	private ?array $gpsLocation;

	private Cache $cache;

	public function __construct(PickupPointRepository $pickupPointRepository, PickupPointTypeRepository $pickupPointTypeRepository, OpeningHoursRepository $openingHoursRepository, Nette\Localization\Translator $translator, Nette\Http\Request $request, Storage $storage)
	{
		$this->pickupPointRepository = $pickupPointRepository;
		$this->pickupPointTypeRepository = $pickupPointTypeRepository;
		$this->openingHoursRepository = $openingHoursRepository;
		$this->translator = $translator;
		$this->cache = new Cache($storage);

		//@TODO only for testing on localhost, in production remove default GPS
		$this->gpsLocation = $this->getGpsByIP($request->getRemoteAddress()) ?? ['N' => 49.1920700, 'E' => 16.6125203];

		$source = $pickupPointRepository->getCollection();

		//latitude => N
		//longitude => E

		if ($this->gpsLocation) {
			$sf = 3.14159 / 180; // scaling factor
			$er = 10219; // earth radius in kilometers, approximate

			$source->where('CEIL(ACOS(SIN(gpsN*:sf)*SIN(:lat*:sf) + COS(gpsN*:sf)*COS(:lat*:sf)*COS((gpsE-:lon)*:sf)) * 10000) >= 0', [
				'er' => $er,
				'sf' => $sf,
				'lon' => $this->gpsLocation['E'],
				'lat' => $this->gpsLocation['N']
			]);
			$source->select(['distance' => 'CEIL(ACOS(SIN(gpsN*:sf)*SIN(:lat*:sf) + COS(gpsN*:sf)*COS(:lat*:sf)*COS((gpsE-:lon)*:sf)) * 10000)']);
		}

		parent::__construct($source, 100);

		$this->addFilterExpression('city', function (ICollection $source, $value) {
			$source->where("address.city", $value);
		}, '');

		$this->addFilterExpression('name', function (ICollection $source, $value) {
			$suffix = $source->getConnection()->getMutationSuffix();
			$source->where("name$suffix LIKE :n", ['n' => "%$value%"]);
		}, '');

		$this->addOrderExpression('distance', function (ICollection $source, $dir) use ($sf, $er) {
			if ($this->gpsLocation) {
				$source->orderBy(['ACOS(SIN(gpsN*:sf)*SIN(:lat*:sf) + COS(gpsN*:sf)*COS(:lat*:sf)*COS((gpsE-:lon)*:sf))' => $dir]);
			}
		});

		$this->setDefaultOrder('distance');
		$this->setDefaultOnPage(100);

		$cities = $pickupPointRepository->getCitiesArrayForSelect();

		$this->getFilterForm()->addText('name', $translator->translate('pointList.name', 'Název'));
		$this->getFilterForm()->addSelect('city', $translator->translate('pointList.city', 'Město'), \array_combine(\array_values($cities), \array_values($cities)))->setPrompt($translator->translate('pointList.all', 'Vše'));
		$this->getFilterForm()->addSubmit('submit', $translator->translate('pointList.showPlaces', 'Zobrazit místa'));
	}

	private function getGpsByIP(string $ip): ?array
	{
		$client = new Client([
			'base_uri' => 'http://ip-api.com/json/',
			'timeout' => 2.0,
		]);

		$response = $client->request('GET', $ip);
		$responseContent = Nette\Utils\Json::decode($response->getBody()->getContents());

		return $response->getStatusCode() == 200 && $responseContent->status === 'success' ? ['N' => $responseContent->lat, 'E' => $responseContent->lon] : null;
	}

	public function render(): void
	{
		$openingHours = $this->pickupPointRepository->getAllOpeningHours();

		$weekDay = (int)(new Nette\Utils\DateTime())->format('w') - 1;
		$currentDate = (new Nette\Utils\DateTime())->format('Y-m-d');
		$nextDate = (new Nette\Utils\DateTime())->modify('+1 day')->format('Y-m-d');

		$openingHoursTexts = [];

		foreach ($this->getItemsOnPage() as $key => $point) {
			//today
			if (isset($openingHours[$key]['special'][$currentDate]->openFrom)) {
				$openingHoursTexts[$key] = $this->translator->translate('pickupPointList.todayFrom', 'dnes od') . ' ' . \substr($openingHours[$key]['special'][$currentDate]->openFrom, 0, -3);
				$openingHoursTexts[$key] .= isset($openingHours[$key]['special'][$currentDate]->openTo) ? ' ' . $this->translator->translate('pickupPointList.to', 'do') . ' ' . \substr($openingHours[$key]['special'][$currentDate]->openTo, 0, -3) : '';
				$openingHoursTexts[$key] .= isset($openingHours[$key]['special'][$currentDate]->pauseFrom) ? '<br>(' . $this->translator->translate('pickupPointList.pauseFrom', 'pauza od') . ' ' . \substr($openingHours[$key]['special'][$currentDate]->pauseFrom, 0, -3) : '';
				$openingHoursTexts[$key] .= isset($openingHours[$key]['special'][$currentDate]->pauseTo) ? ' ' . $this->translator->translate('pickupPointList.to', 'do') . ' ' . \substr($openingHours[$key]['special'][$currentDate]->pauseTo, 0, -3) . ')' : '';
			} else {
				if (isset($openingHours[$key]['special'][$currentDate])) {
					$openingHoursTexts[$key] = '<span class="text-danger">' . $this->translator->translate('pickupPointList.todaySClosed', 'dnes mimořádně zavřeno') . '</span>';
				} else {
					if (isset($openingHours[$key]['normal'][$weekDay]->openFrom)) {
						$openingHoursTexts[$key] = $this->translator->translate('pickupPointList.todayFrom', 'dnes od') . ' ' . \substr($openingHours[$key]['normal'][$weekDay]->openFrom, 0, -3);
						$openingHoursTexts[$key] .= isset($openingHours[$key]['normal'][$weekDay]->openTo) ? ' ' . $this->translator->translate('pickupPointList.to', 'do') . ' ' . \substr($openingHours[$key]['normal'][$weekDay]->openTo, 0, -3) : '';
						$openingHoursTexts[$key] .= isset($openingHours[$key]['normal'][$weekDay]->pauseFrom) ? '<br>(' . $this->translator->translate('pickupPointList.pauseFrom', 'pauza od') . ' ' . \substr($openingHours[$key]['normal'][$weekDay]->pauseFrom, 0, -3) : '';
						$openingHoursTexts[$key] .= isset($openingHours[$key]['normal'][$weekDay]->pauseTo) ? ' ' . $this->translator->translate('pickupPointList.to', 'do') . ' ' . \substr($openingHours[$key]['normal'][$weekDay]->pauseTo, 0, -3) . ')' : '';
					} else {
						$openingHoursTexts[$key] = '<span class="text-danger">' . $this->translator->translate('pickupPointList.todayClosed', 'dnes zavřeno') . '</span>';
					}
				}
			}

			//tomorrow
			if (isset($openingHours[$key]['special'][$nextDate]->openFrom)) {
				$openingHoursTexts[$key] .= '<br>' . $this->translator->translate('pickupPointList.tomorrowFrom', 'zítra od') . ' ' . \substr($openingHours[$key]['special'][$nextDate]->openFrom, 0, -3);
				$openingHoursTexts[$key] .= isset($openingHours[$key]['special'][$nextDate]->openTo) ? ' ' . $this->translator->translate('pickupPointList.to', 'do') . ' ' . \substr($openingHours[$key]['special'][$nextDate]->openTo, 0, -3) : '';
			} else {
				if (isset($openingHours[$key]['special'][$nextDate])) {
					$openingHoursTexts[$key] .= '<br><span class="text-danger">' . $this->translator->translate('pickupPointList.tomorrowSClosed', 'zítra mimořádně zavřeno') . '</span>';
				} else {
					if (isset($openingHours[$key]['normal'][$weekDay + 1]->openFrom)) {
						$openingHoursTexts[$key] .= '<br>' . $this->translator->translate('pickupPointList.tomorrowFrom', 'zítra od') . ' ' . \substr($openingHours[$key]['normal'][$weekDay + 1]->openFrom, 0, -3);
						$openingHoursTexts[$key] .= isset($openingHours[$key]['normal'][$weekDay + 1]->openTo) ? ' ' . $this->translator->translate('pickupPointList.to', 'do') . ' ' . \substr($openingHours[$key]['normal'][$weekDay + 1]->openTo, 0, -3) : '';
					} else {
						$openingHoursTexts[$key] .= '<br><span class="text-danger">' . $this->translator->translate('pickupPointList.tomorrowClosed', 'zítra zavřeno') . '</span>';
					}
				}
			}
		}

		$this->template->gps = $this->gpsLocation;
		$this->template->openingHours = $openingHours;
		$this->template->openingHoursTexts = $openingHoursTexts;
		$this->template->paginator = $this->getPaginator();
		$this->template->render($this->template->getFile() ?: __DIR__ . '/pickupPointList.latte');
	}

	public function handleClearFilters()
	{
		$this->filters = [];
		$this->redirect('this');
	}
}