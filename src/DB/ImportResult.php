<?php

declare(strict_types=1);

namespace Eshop\DB;

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
	 * @column{"type":"enum","length":"'import','entry','importAmount'"}
	 */
	public string $type = 'import';
	
	/**
	 * Možná chyba
	 * @column
	 */
	public ?string $warningMessages;
	
	/**
	 * Chyba
	 * @column{"type":"longtext"}
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
	 * Počet přeskočených
	 * @column
	 */
	public ?int $skippedCount;
	
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
		$started = (new \Carbon\Carbon($this->startedTs))->getTimestamp();
		$finished = $this->finishedTs ? (new \Carbon\Carbon($this->finishedTs))->getTimestamp() : null;
		
		return $finished ? \round(($finished - $started) / 60, 2) : null;
	}
}
