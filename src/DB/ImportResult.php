<?php

declare(strict_types=1);

namespace Eshop\DB;

use Nette\Utils\DateTime;

/**
 * Výsledek
 * @table
 */
class ImportResult extends \StORM\Entity
{
	/**
	 * ID importu
	 * @column
	 */
	public string $id;
	
	/**
	 * Stav
	 * @column{"type":"enum","length":"'started','received','ok','error'"}
	 */
	public string $status = 'started';
	
	/**
	 * Typ
	 * @column{"type":"enum","length":"'import','entry'"}
	 */
	public string $type = 'import';
	
	/**
	 * Možná chyba
	 * @column
	 */
	public ?string $warningMessages;
	
	/**
	 * Chyba
	 * @column
	 */
	public ?string $errorMessage;
	
	/**
	 * Počet nových
	 * @column
	 */
	public ?int $insertedCount;
	
	/**
	 * Počet upravených
	 * @column
	 */
	public ?int $updatedCount;
	
	/**
	 * Počet stažených obrázků
	 * @column
	 */
	public ?int $imageDownloadCount;
	
	/**
	 * Počet stažených obrázků
	 * @column
	 */
	public ?int $imageErrorCount;
	
	/**
	 * Název importniho souboru
	 * @column
	 */
	public ?string $importFile;
	
	/**
	 * Velikost importniho souboru
	 * @column
	 */
	public ?float $importSize;
	
	/**
	 * Zahájeno
	 * @column{"type":"timestamp","default":"CURRENT_TIMESTAMP"}
	 */
	public string $startedTs;
	
	/**
	 * Data obdrženy
	 * @column{"type":"timestamp"}
	 */
	public ?string $receivedTs;
	
	/**
	 * Ukončeno
	 * @column{"type":"timestamp"}
	 */
	public ?string $finishedTs;
	
	/**
	 * Dodavatel
	 * @relation
	 * @constraint{"onUpdate":"CASCADE","onDelete":"CASCADE"}
	 */
	public Supplier $supplier;
	
	public function getRuntime(): ?float
	{
		$started = (new DateTime($this->startedTs))->getTimestamp();
		$finished = $this->finishedTs ? (new DateTime($this->finishedTs))->getTimestamp() : null;
		
		return $finished ? \round(($finished - $started) / 60, 2) : null;
	}
}
