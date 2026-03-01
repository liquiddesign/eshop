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

// LoadExistingPricesTables returns set of existing prices_* tables in cache DB.
func (w *Writer) LoadExistingPricesTables() (map[string]bool, error) {
	rows, err := w.cacheDB.Query("SHOW TABLES LIKE 'prices\\_%'")
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

// DiffTiming holds per-phase timing for a single DiffUpdate call.
type DiffTiming struct {
	Table     string
	Existing  int
	New       int
	Created   int
	Updated   int
	Deleted   int
	SelectMs  int64
	CompareMs int64
	InsertMs  int64
	UpdateMs  int64
	DeleteMs  int64
	TotalMs   int64
}

// DiffUpdate performs diff-update on an existing price table.
// Returns row counts and timing breakdown.
func (w *Writer) DiffUpdate(tableName string, newRows map[int64]*model.PriceRow) (*DiffTiming, error) {
	totalStart := time.Now()
	timing := &DiffTiming{Table: tableName, New: len(newRows)}

	// SELECT existing cache
	selectStart := time.Now()

	query := fmt.Sprintf(
		"SELECT product, price, priceVat, priceBefore, priceVatBefore, priceList, hidden, hiddenInMenu, priority, unavailable, recommended FROM %s",
		quoteIdentifier(tableName),
	)

	dbRows, err := w.cacheDB.Query(query)
	if err != nil {
		return nil, fmt.Errorf("select cache prices: %w", err)
	}
	defer dbRows.Close()

	existingRows := make(map[int64]*model.PriceRow)

	for dbRows.Next() {
		row := &model.PriceRow{}
		var priceVat, priceBefore, priceVatBefore sql.NullFloat64

		if err := dbRows.Scan(
			&row.Product, &row.Price, &priceVat,
			&priceBefore, &priceVatBefore,
			&row.PriceList, &row.Hidden, &row.HiddenInMenu,
			&row.Priority, &row.Unavailable, &row.Recommended,
		); err != nil {
			return nil, fmt.Errorf("scan cache price: %w", err)
		}

		if priceVat.Valid {
			row.PriceVat = priceVat.Float64
		}

		if priceBefore.Valid {
			row.PriceBefore = priceBefore.Float64
		}

		if priceVatBefore.Valid {
			row.PriceVatBefore = priceVatBefore.Float64
		}

		existingRows[row.Product] = row
	}

	timing.SelectMs = time.Since(selectStart).Milliseconds()
	timing.Existing = len(existingRows)

	// Compare
	compareStart := time.Now()

	var toCreate []*model.PriceRow
	var toUpdate []*model.PriceRow
	var toDelete []int64

	for product, newRow := range newRows {
		existing, ok := existingRows[product]
		if !ok {
			toCreate = append(toCreate, newRow)
			continue
		}

		if !rowsEqual(existing, newRow) {
			toUpdate = append(toUpdate, newRow)
		}

		delete(existingRows, product)
	}

	for product := range existingRows {
		toDelete = append(toDelete, product)
	}

	timing.CompareMs = time.Since(compareStart).Milliseconds()

	// Execute updates
	quotedTable := quoteIdentifier(tableName)

	// INSERT new rows
	insertStart := time.Now()

	if len(toCreate) > 0 {
		if err := w.insertRows(quotedTable, toCreate); err != nil {
			return nil, err
		}
	}

	timing.InsertMs = time.Since(insertStart).Milliseconds()

	// UPDATE changed rows
	updateStart := time.Now()

	if len(toUpdate) > 0 {
		if err := w.updateRows(quotedTable, toUpdate); err != nil {
			return nil, err
		}
	}

	timing.UpdateMs = time.Since(updateStart).Milliseconds()

	// DELETE removed rows
	deleteStart := time.Now()

	if len(toDelete) > 0 {
		if err := w.deleteRows(quotedTable, toDelete); err != nil {
			return nil, err
		}
	}

	timing.DeleteMs = time.Since(deleteStart).Milliseconds()

	timing.Created = len(toCreate)
	timing.Updated = len(toUpdate)
	timing.Deleted = len(toDelete)
	timing.TotalMs = time.Since(totalStart).Milliseconds()

	return timing, nil
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

func (w *Writer) insertRows(quotedTable string, rows []*model.PriceRow) error {
	for i := 0; i < len(rows); i += chunkSize {
		end := min(i+chunkSize, len(rows))

		chunk := rows[i:end]

		var sb strings.Builder

		sb.WriteString(fmt.Sprintf("INSERT IGNORE INTO %s (product, price, priceVat, priceBefore, priceVatBefore, priceList, hidden, hiddenInMenu, priority, unavailable, recommended) VALUES ", quotedTable))

		for j, row := range chunk {
			if j > 0 {
				sb.WriteByte(',')
			}

			sb.WriteString(fmt.Sprintf("(%d,%g,%g,%s,%s,%d,%v,%v,%d,%v,%v)",
				row.Product, row.Price, row.PriceVat,
				formatNullable(row.PriceBefore, "NULL"), formatNullable(row.PriceVatBefore, "NULL"),
				row.PriceList, boolToInt(row.Hidden), boolToInt(row.HiddenInMenu),
				row.Priority, boolToInt(row.Unavailable), boolToInt(row.Recommended),
			))
		}

		if _, err := w.cacheDB.Exec(sb.String()); err != nil {
			return fmt.Errorf("insert rows: %w", err)
		}
	}

	return nil
}

func (w *Writer) updateRows(quotedTable string, rows []*model.PriceRow) error {
	for i := 0; i < len(rows); i += chunkSize {
		end := min(i+chunkSize, len(rows))

		chunk := rows[i:end]

		tx, err := w.cacheDB.Begin()
		if err != nil {
			return err
		}

		for _, row := range chunk {
			query := fmt.Sprintf(
				"UPDATE %s SET price=%g, priceVat=%g, priceBefore=%s, priceVatBefore=%s, priceList=%d, hidden=%d, hiddenInMenu=%d, priority=%d, unavailable=%d, recommended=%d WHERE product=%d",
				quotedTable, row.Price, row.PriceVat,
				formatNullable(row.PriceBefore, "NULL"), formatNullable(row.PriceVatBefore, "NULL"),
				row.PriceList, boolToInt(row.Hidden), boolToInt(row.HiddenInMenu),
				row.Priority, boolToInt(row.Unavailable), boolToInt(row.Recommended),
				row.Product,
			)

			if _, err := tx.Exec(query); err != nil {
				tx.Rollback()
				return fmt.Errorf("update row: %w", err)
			}
		}

		if err := tx.Commit(); err != nil {
			return err
		}
	}

	return nil
}

func (w *Writer) deleteRows(quotedTable string, products []int64) error {
	for i := 0; i < len(products); i += chunkSize {
		end := min(i+chunkSize, len(products))

		chunk := products[i:end]
		placeholders := make([]string, len(chunk))
		args := make([]any, len(chunk))

		for j, p := range chunk {
			placeholders[j] = "?"
			args[j] = p
		}

		query := fmt.Sprintf("DELETE FROM %s WHERE product IN (%s)", quotedTable, strings.Join(placeholders, ","))

		if _, err := w.cacheDB.Exec(query, args...); err != nil {
			return fmt.Errorf("delete rows: %w", err)
		}
	}

	return nil
}

func rowsEqual(a, b *model.PriceRow) bool {
	return a.Price == b.Price &&
		a.PriceVat == b.PriceVat &&
		a.PriceBefore == b.PriceBefore &&
		a.PriceVatBefore == b.PriceVatBefore &&
		a.PriceList == b.PriceList &&
		a.Hidden == b.Hidden &&
		a.HiddenInMenu == b.HiddenInMenu &&
		a.Priority == b.Priority &&
		a.Unavailable == b.Unavailable &&
		a.Recommended == b.Recommended
}

func quoteIdentifier(name string) string {
	return "`" + strings.ReplaceAll(name, "`", "``") + "`"
}

// chunkSize is the batch size for bulk SQL operations.
const chunkSize = 10000

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
