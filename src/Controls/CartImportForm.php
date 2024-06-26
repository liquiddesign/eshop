<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\BuyException;
use Eshop\CheckoutManager;
use Eshop\DB\Customer;
use Eshop\DB\ProductRepository;
use Eshop\ShopperUser;
use Nette\Application\UI\Form;
use Nette\Http\FileUpload;
use Nette\Utils\Strings;

class CartImportForm extends Form
{
	/**
	 * @var array<callable(self, array|object): void|callable(array|object): void>
	 */
	public array $onValidate = [];
	
	public ?string $cartId = CheckoutManager::ACTIVE_CART_ID;
	
	public ?Customer $customer = null;
	
	/**
	 * @var array<string>
	 */
	protected array $items = [];
	
	public function __construct(private readonly ShopperUser $shopperUser, private readonly ProductRepository $productRepository)
	{
		parent::__construct();
		
		$this->addTextArea('pasteArea');
		$this->addUpload('importFile');
		//			->addRule(Form::MIME_TYPE, 'Soubor musí být ve formátu CSV', 'text/csv');
		$this->addSubmit('submit');
		$this->onValidate[] = [$this, 'importAttempt'];
	}
	
	public function importAttempt(Form $form): void
	{
		$values = $form->getValues('array');
		
		if ($values['importFile']->hasFile()) {
			$this->parseCSVFile($values['importFile']);
		}
		
		if ($values['pasteArea'] !== '') {
			$this->parsePasteArea($values['pasteArea']);
		}
		
		$notFoundProducts = [];
		
		$customer = $this->customer ?: null;
		$priceLists = $this->customer?->pricelists->toArray(true);
		
		foreach ($this->items as $code => $amount) {
			/** @var \Eshop\DB\Product|null $product */
			$product = $this->productRepository->getProducts($priceLists, $customer)->where('this.code = :code OR this.ean = :code', ['code' => $code])->setTake(1)->first();
			
			if ($product) {
				try {
					$this->shopperUser->getCheckoutManager()->addItemToCart($product, null, \intval($amount), checkCanBuy: false, cartId: $this->cartId);
				} catch (BuyException) {
					$notFoundProducts[] = $code . ' ' . $amount;
				}
			} else {
				$notFoundProducts[] = $code . ' ' . $amount;
			}
		}
		
		/* produkty které nebyly nalezeny nebo byly chybně zadány se vloží zpět do pastArea */
		if (!$notFoundProducts) {
			return;
		}
		
		$form->addError('Některé z produktů nebyly nalezeny. Zkontrolujte prosím jejich zadání');
		
		/** @var \Nette\Forms\Controls\TextArea $control */
		$control = $form['pasteArea'];
		
		$control->value = \implode("\n", $notFoundProducts);
	}
	
	protected function parseCSVFile(FileUpload $importFile): void
	{
		$delimiter = $this->detectDelimiter($importFile->getTemporaryFile());
		
		if (($handle = \fopen($importFile->getTemporaryFile(), 'r')) === false) {
			return;
		}
		
		while (($data = \fgetcsv($handle, 1000, $delimiter)) !== false) {
			[$productId, $amount] = $data;
			
			if (!$productId || !$amount) {
				continue;
			}
			
			$this->items[$productId] = isset($this->items[$productId]) ? (int) $this->items[$productId] + (int) $amount : $amount;
		}
		
		\fclose($handle);
	}
	
	protected function parsePasteArea(string $pasteArea): void
	{
		foreach (\explode('<br />', \nl2br(Strings::trim($pasteArea))) as $row) {
			$productRow = \preg_split('/\s+/', Strings::trim($row));
			$productId = $productRow[0] ?? false;
			$amount = $productRow[1] ?? false;
			
			if (!$productId) {
				continue;
			}
			
			$this->items[$productId] = isset($this->items[$productId]) ? (int) $this->items[$productId] + (int) $amount : $amount;
		}
	}
	
	private function detectDelimiter($csvFile): string
	{
		$delimiters = [
			';' => 0,
			',' => 0,
			"\t" => 0,
			'|' => 0,
		];
		
		$handle = \fopen($csvFile, 'r');
		$firstLine = \fgets($handle);
		$secondLine = \fgets($handle);
		$line = $secondLine ?: $firstLine;
		\fclose($handle);
		
		foreach ($delimiters as $delimiter => &$count) {
			$count = \count(\str_getcsv($line, $delimiter));
		}
		
		return \array_search(\max($delimiters), $delimiters);
	}
}
