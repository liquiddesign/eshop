package writer

import (
	"database/sql"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/liquiddesign/eshop/go-cache-warmup/internal/model"
)

// Writer handles all cache DB write operations.
type Writer struct {
	cacheDB *sql.DB
	verbose bool
}

// New creates a new Writer.
func New(cacheDB *sql.DB, verbose bool) *Writer {
	return &Writer{cacheDB: cacheDB, verbose: verbose}
}

// EnsureMappingTable creates the price_table_map table if not exists.
func (w *Writer) EnsureMappingTable() error {
	_, err := w.cacheDB.Exec(`
		CREATE TABLE IF NOT EXISTS price_table_map (
			price_index VARCHAR(255) NOT NULL PRIMARY KEY,
			physical_table VARCHAR(64) NOT NULL,
			content_hash VARCHAR(64) NOT NULL,
			INDEX idx_content_hash (content_hash)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	`)

	return err
}

// LoadExistingMappings loads all rows from price_table_map.
func (w *Writer) LoadExistingMappings() (map[string]*model.TableMapping, error) {
	rows, err := w.cacheDB.Query("SELECT price_index, physical_table, content_hash FROM price_table_map")
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	mappings := make(map[string]*model.TableMapping)

	for rows.Next() {
		m := &model.TableMapping{}
		if err := rows.Scan(&m.PriceIndex, &m.PhysicalTable, &m.ContentHash); err != nil {
			return nil, err
		}

		mappings[m.PriceIndex] = m
	}

	return mappings, nil
}

// LoadExistingPricesTables returns set of existing price cache tables in cache DB.
// Matches: old-style (prices_*), new hashed (cache_prices_*), and legacy PHP MD5 (32-char hex).
func (w *Writer) LoadExistingPricesTables() (map[string]bool, error) {
	tables := make(map[string]bool)

	// Old-style and new hashed tables
	for _, pattern := range []string{"prices\\_%", "cache\\_prices\\_%"} {
		rows, err := w.cacheDB.Query(fmt.Sprintf("SHOW TABLES LIKE '%s'", pattern))
		if err != nil {
			return nil, err
		}

		for rows.Next() {
			var name string
			if err := rows.Scan(&name); err != nil {
				rows.Close()
				return nil, err
			}

			tables[name] = true
		}

		rows.Close()
	}

	// Legacy PHP MD5 tables (32-char hex strings, no prefix)
	rows, err := w.cacheDB.Query(
		"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME REGEXP '^[0-9a-f]{32}$'",
	)
	if err != nil {
		return nil, err
	}

	for rows.Next() {
		var name string
		if err := rows.Scan(&name); err != nil {
			rows.Close()
			return nil, err
		}

		tables[name] = true
	}

	rows.Close()

	return tables, nil
}

// MigrateOldTableNames checks if any mappings reference old-style table names
// (prices_* or raw MD5 hashes from PHP) and truncates the mapping table to force
// a full rebuild with new cache_prices_* hashed names.
func (w *Writer) MigrateOldTableNames() (bool, error) {
	var count int

	err := w.cacheDB.QueryRow(
		"SELECT COUNT(*) FROM price_table_map WHERE physical_table NOT LIKE 'cache\\_prices\\_%' AND physical_table != '__group'",
	).Scan(&count)
	if err != nil {
		return false, err
	}

	if count == 0 {
		return false, nil
	}

	_, err = w.cacheDB.Exec("TRUNCATE TABLE price_table_map")
	if err != nil {
		return false, fmt.Errorf("truncate price_table_map: %w", err)
	}

	return true, nil
}

// CreatePriceTable creates a prices_* table if not exists.
func (w *Writer) CreatePriceTable(tableName string) error {
	query := fmt.Sprintf(`
		CREATE TABLE IF NOT EXISTS %s (
			product BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			price DOUBLE NOT NULL,
			priceVat DOUBLE,
			priceBefore DOUBLE,
			priceVatBefore DOUBLE,
			priceList INT NOT NULL,
			hidden BOOL NOT NULL,
			hiddenInMenu BOOL NOT NULL,
			priority SMALLINT NOT NULL,
			unavailable BOOL NOT NULL,
			recommended BOOL NOT NULL
		)
	`, quoteIdentifier(tableName))

	_, err := w.cacheDB.Exec(query)

	return err
}

// BulkLoadPriceRows loads price rows into a table using LOAD DATA LOCAL INFILE.
func (w *Writer) BulkLoadPriceRows(tableName string, rows map[int64]*model.PriceRow) error {
	if len(rows) == 0 {
		return nil
	}

	return writeTSVAndLoad(w.cacheDB, tableName, rows)
}

// SwapTiming holds per-phase timing for a single AtomicSwap call.
type SwapTiming struct {
	Table    string
	RowCount int
	LoadMs   int64
	SwapMs   int64
	TotalMs  int64
}

// AtomicSwap replaces a price table atomically using CREATE tmp → LOAD DATA → RENAME.
func (w *Writer) AtomicSwap(tableName string, rows map[int64]*model.PriceRow) (*SwapTiming, error) {
	totalStart := time.Now()
	timing := &SwapTiming{Table: tableName, RowCount: len(rows)}

	tmpTable := tableName + "__new"
	oldTable := tableName + "__old"

	// Drop leftover tmp/old tables from previous failed runs
	for _, t := range []string{tmpTable, oldTable} {
		if _, err := w.cacheDB.Exec(fmt.Sprintf("DROP TABLE IF EXISTS %s", quoteIdentifier(t))); err != nil {
			return nil, fmt.Errorf("drop leftover %s: %w", t, err)
		}
	}

	// Create tmp table
	if err := w.createPriceTableAs(tmpTable); err != nil {
		return nil, fmt.Errorf("create tmp table: %w", err)
	}

	// LOAD DATA into tmp table
	loadStart := time.Now()

	if len(rows) > 0 {
		if err := writeTSVAndLoad(w.cacheDB, tmpTable, rows); err != nil {
			return nil, fmt.Errorf("load data into %s: %w", tmpTable, err)
		}
	}

	timing.LoadMs = time.Since(loadStart).Milliseconds()

	// Atomic RENAME
	swapStart := time.Now()

	// Check if original table exists
	var exists int

	err := w.cacheDB.QueryRow(
		"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
		tableName,
	).Scan(&exists)
	if err != nil {
		return nil, fmt.Errorf("check table existence: %w", err)
	}

	if exists > 0 {
		// Atomic swap: original→old, new→original
		renameQuery := fmt.Sprintf(
			"RENAME TABLE %s TO %s, %s TO %s",
			quoteIdentifier(tableName), quoteIdentifier(oldTable),
			quoteIdentifier(tmpTable), quoteIdentifier(tableName),
		)

		if _, err := w.cacheDB.Exec(renameQuery); err != nil {
			return nil, fmt.Errorf("atomic rename: %w", err)
		}

		// Drop old table
		if _, err := w.cacheDB.Exec(fmt.Sprintf("DROP TABLE IF EXISTS %s", quoteIdentifier(oldTable))); err != nil {
			return nil, fmt.Errorf("drop old table: %w", err)
		}
	} else {
		// New table — just rename tmp to target
		renameQuery := fmt.Sprintf(
			"RENAME TABLE %s TO %s",
			quoteIdentifier(tmpTable), quoteIdentifier(tableName),
		)

		if _, err := w.cacheDB.Exec(renameQuery); err != nil {
			return nil, fmt.Errorf("rename new table: %w", err)
		}
	}

	timing.SwapMs = time.Since(swapStart).Milliseconds()
	timing.TotalMs = time.Since(totalStart).Milliseconds()

	return timing, nil
}

// createPriceTableAs creates a price table with the standard schema.
func (w *Writer) createPriceTableAs(tableName string) error {
	query := fmt.Sprintf(`
		CREATE TABLE IF NOT EXISTS %s (
			product BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			price DOUBLE NOT NULL,
			priceVat DOUBLE,
			priceBefore DOUBLE,
			priceVatBefore DOUBLE,
			priceList INT NOT NULL,
			hidden BOOL NOT NULL,
			hiddenInMenu BOOL NOT NULL,
			priority SMALLINT NOT NULL,
			unavailable BOOL NOT NULL,
			recommended BOOL NOT NULL
		)
	`, quoteIdentifier(tableName))

	_, err := w.cacheDB.Exec(query)

	return err
}

// RegisterMapping inserts/updates a mapping in price_table_map.
func (w *Writer) RegisterMapping(index, physicalTable, hash string) error {
	_, err := w.cacheDB.Exec(
		"INSERT INTO price_table_map (price_index, physical_table, content_hash) VALUES (?, ?, ?) "+
			"ON DUPLICATE KEY UPDATE physical_table = VALUES(physical_table), content_hash = VALUES(content_hash)",
		index, physicalTable, hash,
	)

	return err
}

// FindExistingTableByHash finds a physical table with matching content hash.
func (w *Writer) FindExistingTableByHash(hash string) (string, error) {
	var table string

	err := w.cacheDB.QueryRow(
		"SELECT physical_table FROM price_table_map WHERE content_hash = ? AND physical_table != '"+GroupTableName+"' LIMIT 1",
		hash,
	).Scan(&table)

	if errors.Is(err, sql.ErrNoRows) {
		return "", nil
	}

	if err != nil {
		return "", err
	}

	return table, nil
}

// DeleteMapping removes a mapping from price_table_map.
func (w *Writer) DeleteMapping(index string) error {
	_, err := w.cacheDB.Exec("DELETE FROM price_table_map WHERE price_index = ?", index)
	return err
}

// DeleteStaleMappings removes stale mappings not in the touched set.
func (w *Writer) DeleteStaleMappings(staleIndexes []string) error {
	if len(staleIndexes) == 0 {
		return nil
	}

	placeholders := make([]string, len(staleIndexes))
	args := make([]any, len(staleIndexes))

	for i, idx := range staleIndexes {
		placeholders[i] = "?"
		args[i] = idx
	}

	_, err := w.cacheDB.Exec(
		"DELETE FROM price_table_map WHERE price_index IN ("+strings.Join(placeholders, ",")+")",
		args...,
	)

	return err
}

// GetReferencedTables returns all physical tables referenced in price_table_map.
func (w *Writer) GetReferencedTables() (map[string]bool, error) {
	rows, err := w.cacheDB.Query("SELECT DISTINCT physical_table FROM price_table_map")
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	tables := make(map[string]bool)

	for rows.Next() {
		var name string
		if err := rows.Scan(&name); err != nil {
			return nil, err
		}

		tables[name] = true
	}

	return tables, nil
}

// DropTable drops a table from cache DB.
func (w *Writer) DropTable(tableName string) error {
	_, err := w.cacheDB.Exec(fmt.Sprintf("DROP TABLE IF EXISTS %s", quoteIdentifier(tableName)))
	return err
}


func quoteIdentifier(name string) string {
	return "`" + strings.ReplaceAll(name, "`", "``") + "`"
}

// formatNullable formats a float64 value, returning nullStr when the value is 0.
func formatNullable(value float64, nullStr string) string {
	if value != 0 {
		return fmt.Sprintf("%g", value)
	}

	return nullStr
}

func boolToInt(b bool) int {
	if b {
		return 1
	}

	return 0
}
