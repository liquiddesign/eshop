<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Carbon\Carbon;
use Eshop\DB\WatcherRepository;
use Eshop\Helpers\SqlHelper;
use Eshop\ShopperUser;
use Grid\Datalist;
use Messages\DB\TemplateRepository;
use Nette\Mail\Mailer;
use Nette\Utils\Validators;
use StORM\DIConnection;
use StORM\Exception\NotFoundException;
use StORM\ICollection;

/**
 * Class WatcherList
 * @package Eshop\Controls
 */
class WatcherList extends Datalist
{
	public function __construct(
		private readonly WatcherRepository $watcherRepository,
		private readonly ShopperUser $shopperUser,
		private readonly TemplateRepository $templateRepository,
		private readonly Mailer $mailer,
		DIConnection $connection,
		private readonly bool $email = false
	) {
		parent::__construct($watcherRepository->getWatchersByCustomer($shopperUser->getCustomer()));

		$this->setDefaultOnPage(20);

		$langSuffix = $connection->getMutationSuffix();

		$this->addFilterExpression('productName', function (ICollection $collection, $value) use ($langSuffix): void {
			$collection->where("products.name$langSuffix LIKE :query", ['query' => $value]);
		}, '');

		/** @var \Forms\Form $filterForm */
		$filterForm = $this->getFilterForm();

		$filterForm->addText('productName');
		$filterForm->addSubmit('submit');
	}

	public function handleDeleteWatcher(string $watcherId): void
	{
		try {
			$watcher = $this->watcherRepository->one($watcherId);

			if ($this->email && $watcher !== null && Validators::isEmail($watcher->customer->email)) {
				$mail = $this->templateRepository->createMessage('watchdog.removed', $this->watcherRepository->getEmailVariables($watcher), $watcher->customer->email);

				$this->mailer->send($mail);
			}

			$this->watcherRepository->delete($watcher);
		} catch (NotFoundException $e) {
		}

		$this->redirect('this');
	}

	public function render(): void
	{
		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$notifiedWatchers = [];

		$customer = $this->shopperUser->getCustomer();

		if ($customer !== null) {
			foreach ($this->watcherRepository->getWatchersByCustomer($customer)->toArray() as $notifiedWatcher) {
				if ($notifiedWatcher->notifiedTs !== null && $notifiedWatcher->notificationViewedTs === null) {
					$notifiedWatchers[] = $notifiedWatcher->getPk();
				}
			}

			$this->watcherRepository->getWatchersByCustomer($customer)->update(
				[
					'notificationViewedTs' => Carbon::now()->toDateTimeString(),
				]
			);

			$template->notifiedWatchers = $notifiedWatchers;
		}

		$template->render($template->getFile() ?: __DIR__ . '/watcherList.latte');
	}
}
