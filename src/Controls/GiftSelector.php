<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\DB\Product;
use Eshop\DB\ProductRepository;
use Eshop\Services\GiftService;
use Eshop\ShopperUser;
use Nette\Application\UI\Control;
use Nette\Utils\Arrays;

/**
 * Komponenta pro výběr dárku k nákupu
 */
class GiftSelector extends Control
{
	/**
	 * @var array<callable(): void> Occurs when gift is selected or removed
	 */
	public array $onGiftChange = [];

	/**
	 * @var array<callable(self): void> Occurs when component is anchored to presenter
	 */
	public array $onAnchor = [];

	public function __construct(
		private readonly GiftService $giftService,
		private readonly ShopperUser $shopperUser,
		private readonly ProductRepository $productRepository,
	) {
	}

	/**
	 * Handler pro výběr dárku
	 */
	public function handleSelectGift(string $productId): void
	{
		$cart = $this->shopperUser->getCheckoutManager()->getCart();

		if ($cart === null) {
			$this->redirect('this');

			return;
		}

		$product = $this->productRepository->one($productId);

		if ($product === null) {
			$this->flashMessage('Produkt nebyl nalezen', 'error');
			$this->redirect('this');

			return;
		}

		// Ověříme, že produkt je stále mezi dostupnými dárky
		$availableGifts = $this->giftService->getAvailableGifts($cart);
		$isAvailable = false;

		foreach ($availableGifts as $gift) {
			if ($gift->getPK() === $productId) {
				$isAvailable = true;

				break;
			}
		}

		if (!$isAvailable) {
			$this->flashMessage('Tento dárek již není dostupný pro vaši objednávku', 'warning');
			$this->redirect('this');

			return;
		}

		$this->giftService->addGiftToCart($cart, $product);

		Arrays::invoke($this->onGiftChange);

		$this->redirect('this');
	}

	/**
	 * Handler pro odebrání dárku
	 */
	public function handleRemoveGift(): void
	{
		$cart = $this->shopperUser->getCheckoutManager()->getCart();

		if ($cart === null) {
			$this->redirect('this');

			return;
		}

		$this->giftService->removeGiftFromCart($cart);

		Arrays::invoke($this->onGiftChange);

		$this->redirect('this');
	}

	public function render(): void
	{
		Arrays::invoke($this->onAnchor, $this);

		$cart = $this->shopperUser->getCheckoutManager()->getCart();

		$this->template->availableGifts = [];
		$this->template->selectedGift = null;
		$this->template->canSelectGift = false;

		if ($cart !== null) {
			$this->template->availableGifts = $this->giftService->getAvailableGifts($cart);
			$this->template->selectedGift = $this->giftService->getSelectedGift($cart);
			$this->template->canSelectGift = $this->giftService->canSelectGift($cart);
		}

		/** @var \Nette\Bridges\ApplicationLatte\Template $template */
		$template = $this->template;

		$template->render($this->template->getFile() ?: __DIR__ . '/giftSelector.latte');
	}
}
