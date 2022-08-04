<?php

declare(strict_types=1);

namespace Eshop\DB;

use Messages\DB\TemplateRepository;
use Nette\Application\LinkGenerator;
use Nette\Mail\Mailer;
use Nette\Utils\Validators;
use StORM\DIConnection;
use StORM\ICollection;
use StORM\SchemaManager;

/**
 * @extends \StORM\Repository<\Eshop\DB\Watcher>
 */
class WatcherRepository extends \StORM\Repository
{
	private ProductRepository $productRepository;

	private PricelistRepository $pricelistRepository;

	private Mailer $mailer;

	private TemplateRepository $templateRepository;

	private LinkGenerator $linkGenerator;

	public function __construct(
		DIConnection $connection,
		SchemaManager $schemaManager,
		ProductRepository $productRepository,
		PricelistRepository $pricelistRepository,
		Mailer $mailer,
		TemplateRepository $templateRepository,
		LinkGenerator $linkGenerator
	) {
		parent::__construct($connection, $schemaManager);

		$this->productRepository = $productRepository;
		$this->pricelistRepository = $pricelistRepository;
		$this->mailer = $mailer;
		$this->templateRepository = $templateRepository;
		$this->linkGenerator = $linkGenerator;
	}

	public function getWatchersByCustomer(Customer $customer): ICollection
	{
		return $this->many()
			->join(['products' => 'eshop_product'], 'this.fk_product=products.uuid')
			->where('this.fk_customer', $customer->getPK());
	}

	/**
	 * @param \Eshop\DB\Customer $customer
	 * @param array<string> $products
	 */
	public function getWatchersByCustomerAndProducts(Customer $customer, array $products): ICollection
	{
		return $this->many()
			->where('this.fk_product', $products)
			->where('this.fk_customer', $customer->getPK());
	}

	/**
	 * Return two arrays.
	 * First array (active): changed watchers in positive way, for example: amountFrom=1, beforeAmountFrom=0, currentAmount=1
	 * Second array (nonActive): changed watchers in negative way, for example: amountFrom=1, beforeAmountFrom=1, currentAmount=0
	 * Watchers without change will not be returned.
	 * @return \Eshop\DB\Watcher[][]
	 */
	public function getChangedAmountWatchers(bool $email = false): array
	{
		/** @var \Eshop\DB\Watcher[] $activeWatchers */
		$activeWatchers = [];
		/** @var \Eshop\DB\Watcher[] $nonActiveWatchers */
		$nonActiveWatchers = [];

		$watchers = $this->many()
			->join(['product' => 'eshop_product'], 'this.fk_product = product.uuid')
			->join(['displayAmount' => 'eshop_displayamount'], 'product.fk_displayAmount = displayAmount.uuid')
			->where('product.fk_displayAmount IS NOT NULL AND displayAmount.amountFrom IS NOT NULL AND this.amountFrom IS NOT NULL');

		while ($watcher = $watchers->fetch()) {
			/** @var \Eshop\DB\Watcher $watcher */
			if ($watcher->product->displayAmount->amountFrom >= $watcher->amountFrom && $watcher->amountFrom > $watcher->beforeAmountFrom) {
				$activeWatchers[] = $watcher;

				if ($email && Validators::isEmail($watcher->customer->email)) {
					$mail = $this->templateRepository->createMessage('watchdog.changed', $this->getEmailVariables($watcher), $watcher->customer->email);

					$this->mailer->send($mail);
				}

				if (!$watcher->keepAfterNotify) {
					$watcher->delete();

					continue;
				}
			}

			if ($watcher->product->displayAmount->amountFrom < $watcher->amountFrom && $watcher->product->displayAmount->amountFrom < $watcher->beforeAmountFrom) {
				$nonActiveWatchers[] = $watcher;
			}

			$watcher->update([
				'beforeAmountFrom' => $watcher->product->displayAmount->amountFrom,
			]);
		}

		return [
			'active' => $activeWatchers,
			'nonActive' => $nonActiveWatchers,
		];
	}

	/**
	 * Return two arrays.
	 * First array (active): changed watchers in positive way, for example: watchedPrice=40, beforePrice=42, currentPrice=38
	 * Second array (nonActive): changed watchers in negative way, for example: watchedPrice=40, beforePrice=38, currentPrice=42
	 * Watchers without change will not be returned.
	 * @return \Eshop\DB\Watcher[][]
	 * @throws \StORM\Exception\NotFoundException
	 */
	public function getChangedPriceWatchers(bool $email = false): array
	{
		/** @var \Eshop\DB\Watcher[] $activeWatchers */
		$activeWatchers = [];
		/** @var \Eshop\DB\Watcher[] $nonActiveWatchers */
		$nonActiveWatchers = [];
		/** @var array<string, \Eshop\DB\Pricelist[]> $pricelistsByCustomers */
		$pricelistsByCustomers = [];

		$watchers = $this->many()->where('priceFrom IS NOT NULL AND beforePriceFrom IS NOT NULL');

		while ($watcher = $watchers->fetch()) {
			/** @var \Eshop\DB\Watcher $watcher */

			if (!isset($pricelistsByCustomers[$watcher->getValue('customer')])) {
				/** @var \Eshop\DB\Pricelist[] $pricelists */
				$pricelists = $this->pricelistRepository->getCollection()
					->join(['nxnCustomer' => 'eshop_customer_nxn_eshop_pricelist'], 'this.uuid = nxnCustomer.fk_pricelist')
					->where('nxnCustomer.fk_customer', $watcher->getValue('customer'))
					->toArray();

				$pricelistsByCustomers[$watcher->getValue('customer')] = $pricelists;
			}

			/** @var \Eshop\DB\Product|null $product */
			$product = $this->productRepository->getProducts($pricelistsByCustomers[$watcher->getValue('customer')])->where('this.uuid', $watcher->getValue('product'))->first();

			if (!$product) {
				continue;
			}

			if ($watcher->priceFrom < $watcher->beforePriceFrom && $product->getPrice() <= $watcher->priceFrom) {
				$activeWatchers[] = $watcher;

				if ($email && Validators::isEmail($watcher->customer->email)) {
					$mail = $this->templateRepository->createMessage('watchdog.changed', $this->getEmailVariables($watcher), $watcher->customer->email);

					$this->mailer->send($mail);
				}

				if (!$watcher->keepAfterNotify) {
					$watcher->delete();

					continue;
				}
			}

			if ($product->getPrice() > $watcher->beforePriceFrom && $product->getPrice() > $watcher->priceFrom) {
				$nonActiveWatchers[] = $watcher;
			}

			$watcher->update([
				'beforePriceFrom' => $product->getPrice(),
			]);
		}

		return [
			'active' => $activeWatchers,
			'nonActive' => $nonActiveWatchers,
		];
	}

	/**
	 * @param \Eshop\DB\Watcher $watcher
	 * @return array<string>
	 */
	public function getEmailVariables(Watcher $watcher): array
	{
		return [
			'productName' => $watcher->product->name,
			'link' => $this->linkGenerator->link('Eshop:Product:detail', [$watcher->product->getPK()]),
		];
	}

	public function create(array $data, bool $email = false): ?Watcher
	{
		try {
			$watcher = $this->createOne($data);

			if ($email && Validators::isEmail($watcher->customer->email)) {
				$mail = $this->templateRepository->createMessage('watchdog.created', $this->getEmailVariables($watcher), $watcher->customer->email);

				$this->mailer->send($mail);
			}

			return $watcher;
		} catch (\Throwable $e) {
			return null;
		}
	}

	public function delete(?Watcher $watcher, bool $email = false): void
	{
		if ($watcher === null) {
			return;
		}

		try {
			if ($email && Validators::isEmail($watcher->customer->email)) {
				$mail = $this->templateRepository->createMessage('watchdog.removed', $this->getEmailVariables($watcher), $watcher->customer->email);

				$this->mailer->send($mail);
			}

			$watcher->delete();
		} catch (\Throwable $e) {
		}
	}
}
