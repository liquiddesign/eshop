<?php
declare(strict_types=1);

namespace Eshop\Admin;

use Admin\Controls\AdminForm;
use Admin\Controls\AdminGrid;
use Carbon\Carbon;
use Eshop\BackendPresenter;
use Eshop\DB\CustomerRepository;
use Eshop\DB\ProductRepository;
use Eshop\DB\Review;
use Eshop\DB\ReviewRepository;
use Eshop\Shopper;
use StORM\Collection;
use StORM\DIConnection;

class ReviewPresenter extends BackendPresenter
{
	/** @inject */
	public ReviewRepository $reviewRepository;

	/** @inject */
	public CustomerRepository $customerRepository;

	/** @inject */
	public ProductRepository $productRepository;

	/** @inject */
	public Shopper $shopper;

	public function createComponentGrid(): AdminGrid
	{
		$grid = $this->gridFactory->create($this->reviewRepository->many(), 20, 'this.createdTs', 'DESC', true);
		$grid->addColumnSelector();

		$grid->addColumnText('Vytvořeno', 'createdTs|date', '%s', 'createdTs', ['class' => 'fit']);
		$grid->addColumn('Recenzováno', function (Review $review): string {
			return $review->isReviewed() ? \Carbon\Carbon::parse($review->reviewedTs)->format('d. m. Y') : '<i class="fa fa-times text-danger"></i>';
		}, '%s', null, ['class' => 'fit']);
		$grid->addColumn('Zákazník', function (Review $review): string {
			if ($customer = $review->customer) {
				$link = $this->link(':Eshop:Admin:Customer:edit', ['customer' => $customer]);

				return "<a href=\"$link\"><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;$customer->fullname ($customer->email)</a>";
			}

			return "$review->customerFullName ($review->customerEmail)";
		});
		$grid->addColumn('Produkt', function (Review $review): string {
			$product = $review->product;
			$code = $product->getFullCode();
			$link = $this->link(':Eshop:Admin:Product:edit', ['product' => $product]);

			return "<a href=\"$link\"><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;$product->name ($code)</a>";
		});
		$grid->addColumnText('Hodnocení', 'score', '%s', 'score', ['class' => 'fit']);
		$grid->addColumn('Link pro hodnocení', function (Review $review): string {
			try {
				$link = $this->link('//:Eshop:Review:default', ['uuid' => $review->getPK()]);

				return "<a href=\"$link\" target='_blank'><i class='fa fa-external-link-alt fa-sm'></i>&nbsp;$link</a>";
			} catch (\Throwable $e) {
				return '';
			}
		});

		$grid->addColumnLinkDetail();
		$grid->addColumnActionDelete();
		$grid->addButtonDeleteSelected();

		$mutationSuffix = $this->reviewRepository->getConnection()->getMutationSuffix();

		$grid->addFilterTextInput('search', ['this.customerFullName', 'this.customerFullName', 'customer.fullname', 'customer.email'], null, 'Zákazník - E-mail, jméno');
		$grid->addFilterTextInput('product', ["product.name$mutationSuffix", 'product.code', 'product.ean'], null, 'Produkt - Jméno, kód, ean');

		$grid->addFilterDataSelect(function (Collection $source, $value): void {
			$source->where($value ? 'this.score IS NOT NULL' : 'this.score IS NULL');
		}, '', 'reviewed', null, [false => 'Ne', true => 'Ano'])->setPrompt('- Recenzováno -');

		$grid->addFilterButtons();

		return $grid;
	}

	public function createComponentForm(): AdminForm
	{
		/** @var \Eshop\DB\Review|null $review */
		$review = $this->getParameter('review');

		$form = $this->formFactory->create();
		$form->addText('customerEmail', 'E-mail zákazníka')->addRule($form::EMAIL)->setRequired();
		$form->addText('customerFullName', 'Jméno zákazníka')->setRequired();
		$form->addSelect2('customer', 'Zákazník', $this->customerRepository->getArrayForSelect())->setPrompt('- Zvolte zákazníka -');

		$productInput = $form->addSelect2Ajax('product', $this->link('getProductsForSelect2!'), 'Produkt', [], 'Zvolte produkt');

		if ($review) {
			$this->template->select2AjaxDefaults[$productInput->getHtmlId()] = [$review->getValue('product') => $review->product->name];
		}

		$reviewInputInfoType = $this->shopper->getReviewsType() === 'int' ? 'Vyplňujte celá čísla v intervalu' : 'Vyplňujte celá nebo desetinná čísla v intervalu';

		$form->addText('score', 'Hodnocení')
			->setHtmlAttribute('data-info', "Při nevyplnění se považuje recenze jako nehodnocená.<br>$reviewInputInfoType " .
				$this->shopper->getReviewsMinScore() .
				' - ' .
				$this->shopper->getReviewsMaxScore() .
				' (včetně)')
			->setNullable()->addCondition($form::FILLED)->addRule($this->shopper->getReviewsType() === 'int' ? $form::INTEGER : $form::FLOAT);

		$form->addText('remindersSentCount', 'Počet zaslaných upozornění e-mailem')->setDisabled()
			->setHtmlAttribute('data-info', 'Probíhá automaticky. Maximální počet: ' . $this->shopper->getReviewsMaxRemindersCount());

		$form->addSubmits(!$review);

		$form->onValidate[] = function (AdminForm $form): void {
			if (!$form->isValid()) {
				return;
			}

			$values = $form->getValues();
			/** @var \Nette\Forms\Controls\TextInput $scoreInput */
			$scoreInput = $form['score'];

			if ($values['score'] !== null && $values['score'] < $this->shopper->getReviewsMinScore()) {
				$scoreInput->addError('Hodnota musí být větší než nebo rovna číslu ' . $this->shopper->getReviewsMinScore());

				return;
			}

			if ($values['score'] !== null && $values['score'] > $this->shopper->getReviewsMaxScore()) {
				$scoreInput->addError('Hodnota musí být menší než nebo rovna číslu ' . $this->shopper->getReviewsMaxScore());

				return;
			}

			$data = $this->getHttpRequest()->getPost();

			if (!isset($data['product'])) {
				/** @var \Nette\Forms\Controls\SelectBox $input */
				$input = $form['product'];
				$input->addError('Toto pole je povinné!');

				return;
			}
		};

		$form->onSuccess[] = function (AdminForm $form) use ($review): void {
			$values = $form->getValues('array');

			if (!$values['uuid']) {
				$values['uuid'] = DIConnection::generateUuid();
			}

			$values['product'] = $this->productRepository->one($form->getHttpData()['product'])->getPK();

			if ($review) {
				if ($values['score'] && !$review->reviewedTs) {
					$values['reviewedTs'] = Carbon::now()->toString();
				}

				if (!$values['score']) {
					$values['reviewedTs'] = null;
				}
			} else {
				$values['reviewedTs'] = $values['score'] ? Carbon::now()->toString() : null;
			}

			$object = $this->reviewRepository->syncOne($values, null, true);

			$this->flashMessage('Uloženo', 'success');
			$form->processRedirect('detail', 'default', [$object]);
		};

		return $form;
	}

	public function renderDefault(): void
	{
		$this->template->headerLabel = 'Recenze';
		$this->template->headerTree = [['Recenze'],];

		$this->template->displayButtons = [$this->createNewItemButton('new')];
		$this->template->displayControls = [$this->getComponent('grid')];
	}

	public function renderNew(): void
	{
		$this->template->headerLabel = 'Nová položka';
		$this->template->headerTree = [
			['Recenze', 'default'],
			['Nová položka'],
		];

		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}

	public function actionDetail(Review $review): void
	{
		/** @var \Forms\Form $form */
		$form = $this->getComponent('form');
		$form->setDefaults($review->toArray());
	}

	public function renderDetail(): void
	{
		$this->template->headerLabel = 'Detail';
		$this->template->headerTree = [
			['Recenze', 'default'],
			['Detail'],
		];
		$this->template->displayButtons = [$this->createBackButton('default')];
		$this->template->displayControls = [$this->getComponent('form')];
	}
}
