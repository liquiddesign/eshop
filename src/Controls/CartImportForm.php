<?php

declare(strict_types=1);

namespace Eshop\Controls;

use Eshop\BuyException;
use Eshop\CheckoutManager;
use Eshop\DB\ProductRepository;
use Nette\Application\UI\Form;
use Nette\Http\FileUpload;

class CartImportForm extends Form
{
	private CheckoutManager $checkoutManager;
	
	private ProductRepository $productRepository;
	
	/**
	 * @var string[]
	 */
	private array $items = [];
	
	public function __construct(CheckoutManager $checkoutManager, ProductRepository $productRepository)
	{
		parent::__construct();
		
		$this->checkoutManager = $checkoutManager;
		$this->productRepository = $productRepository;
		
		$this->addTextArea('pasteArea');
		$this->addUpload('importFile');
//			->addRule(Form::MIME_TYPE, 'Soubor musí být ve formátu CSV', 'text/csv');
		$this->addSubmit('submit');
		$this->onSuccess[] = [$this, 'success'];
	}
	
	public function success(Form $form): void
	{
		$values = $form->getValues();
		
		if ($values->importFile->hasFile()) {
			$this->parseCSVFile($values->importFile);
		}
		
		if ($values->pasteArea !== '') {
			$this->parsePasteArea($values->pasteArea);
		}
		
		$notFoundProducts = [];
		
		foreach ($this->items as $code => $amount) {
			$product = $this->productRepository->getProducts()->where('IF(this.subCode, CONCAT(this.code,".",this.subCode), this.code) = :code OR this.ean = :code', ['code' => $code])->first();
			
			if ($product) {
				try {
					$this->checkoutManager->addItemToCart($product, null, \intval($amount), false, false, false);
				} catch (BuyException $exception) {
					$notFoundProducts[] = $code . ' ' .$amount;
				}
			} else {
				$notFoundProducts[] = $code . ' ' .$amount;
			}
		}
		
		/* produkty které nebyly nalezeny nebo byly chybně zadány se vloží zpět do pastArea */
		if (\count($notFoundProducts) > 0) {
			$form->addError('Některé z produktů nebyly nalezeny. Zkontrolujte prosím jejich zadání');
			$form['pasteArea']->value = \implode("\n", $notFoundProducts);
		} else {
			$this->getPresenter()->flashMessage('Import produktů proběhl úspěšně.', 'success');
			$this->getPresenter()->redirect('this');
		}
	}
	
	private function parseCSVFile(FileUpload $importFile): void
	{
		$delimiter = $this->detectDelimiter($importFile->getTemporaryFile());
		
		if (($handle = \fopen($importFile->getTemporaryFile(), "r")) !== false) {
			while (($data = \fgetcsv($handle, 1000, $delimiter)) !== false) {
				[$productId, $amount] = $data;
				
				if (!$productId || !$amount) {
					continue;
				}
				
				$this->items[$productId] = isset($items[$productId]) ? $items[$productId] + $amount : $amount;
			}
			
			\fclose($handle);
		}
	}
	
	private function parsePasteArea(string $pasteArea): void
	{
		foreach (\explode('<br />', \nl2br(\trim($pasteArea))) as $row) {
			$productRow = \preg_split('/\s+/', \trim($row));
			$productId = $productRow[0] ?? false;
			$amount = $productRow[1] ?? false;
			
			if (!$productId) {
				continue;
			}
			
			$this->items[$productId] = isset($items[$productId]) ? $items[$productId] + $amount : $amount;
		}
	}
	
	private function detectDelimiter($csvFile): string
	{
		$delimiters = [
			';' => 0,
			',' => 0,
			"\t" => 0,
			"|" => 0,
		];
		
		$handle = \fopen($csvFile, "r");
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
