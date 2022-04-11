<?php
declare(strict_types=1);

namespace Eshop\Front;

use Eshop\DB\Order;
use Eshop\DB\OrderRepository;
use Eshop\DB\Review;
use Eshop\DB\ReviewRepository;
use Forms\Form;
use Nette\Localization\Translator;

abstract class ReviewPresenter extends FrontendPresenter
{
	/** @inject */
	public ReviewRepository $reviewRepository;

	/** @inject */
	public OrderRepository $orderRepository;

	/** @inject */
	public Translator $translator;

	private ?Review $review = null;

	private ?Order $order = null;

	public function actionDefault(string $uuid, ?string $order = null): void
	{
		$this->review = $this->template->review = $this->reviewRepository->one($uuid);

		if ($order) {
			$this->order = $order = $this->orderRepository->one($order, true);
		}

		/** @var \Eshop\DB\Customer|null $customer */
		$customer = $this->getUser()->getIdentity();

		/** @var \Web\Controls\Breadcrumb $breadcrumb */
		$breadcrumb = $this['breadcrumb'];

		if ($customer) {
			$breadcrumb->addItem($this->translator->translate('.myAccount', 'Můj účet'), $this->link(':Eshop:Profile:edit'));
			$breadcrumb->addItem($this->translator->translate('.myOrders', 'Moje objednávky'), $this->link(':Eshop:Order:orders'));

			if ($order) {
				$breadcrumb->addItem($this->translator->translate('oO.orderNumber', 'Objednávka č.') . $order->code, $this->link(':Eshop:Order:order', $order->getPK()));
			}
		}

		$breadcrumb->addItem($this->translator->translate('Review.reviewBrdcrmb', 'Hodnotit produkt'));
	}

	public function createComponentReviewForm(): Form
	{
		$form = $this->formFactory->create();

		$options = [];

		for ($i = 1; $i <= 5; $i++) {
			$options["o_$i"] = $i;
		}

		$form->addRadioList('score', null, $options)->setRequired()->setDefaultValue($this->review && $this->review->isReviewed() ? 'o_' . $this->review->score : null);
		$form->addSubmit('submit');

		$form->onSuccess[] = function (Form $form): void {
			$values = $form->getValues('array');

			$this->review->update([
				'score' => (int) \explode('_', $values['score'])[1],
				'reviewedTs' => \date('Y-m-d\TH:i:s'),
			]);

			$this->flashMessage($this->translator->translate('Review.thankYouNow', 'Děkujeme!'), 'success');
			$this->redirect($this->order ? ':Eshop:Order:order' : 'this', $this->order ? ['orderId' => $this->order->getPK()] : []);
		};

		return $form;
	}
}
