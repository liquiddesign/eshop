package writer

import (
	"database/sql"
	"fmt"
	"os"
	"strings"

	"github.com/liquiddesign/eshop/go-cache-warmup/internal/model"
)

// writeTSVAndLoad writes price rows to a temp TSV file and loads via LOAD DATA LOCAL INFILE.
func writeTSVAndLoad(db *sql.DB, tableName string, rows map[int64]*model.PriceRow) error {
	tmpFile, err := os.CreateTemp("", "prices_*.tsv")
	if err != nil {
		return fmt.Errorf("create temp file: %w", err)
	}

	tmpPath := tmpFile.Name()
	defer os.Remove(tmpPath)

	var sb strings.Builder

	for _, row := range rows {
		pb := "\\N"
		if row.PriceBefore != 0 {
			pb = fmt.Sprintf("%g", row.PriceBefore)
		}

		pvb := "\\N"
		if row.PriceVatBefore != 0 {
			pvb = fmt.Sprintf("%g", row.PriceVatBefore)
		}

		sb.WriteString(fmt.Sprintf("%d\t%g\t%g\t%s\t%s\t%d\t%d\t%d\t%d\t%d\t%d\n",
			row.Product, row.Price, row.PriceVat, pb, pvb,
			row.PriceList, boolToInt(row.Hidden), boolToInt(row.HiddenInMenu),
			row.Priority, boolToInt(row.Unavailable), boolToInt(row.Recommended),
		))
	}

	if _, err := tmpFile.WriteString(sb.String()); err != nil {
		tmpFile.Close()
		return fmt.Errorf("write TSV: %w", err)
	}

	tmpFile.Close()

	query := fmt.Sprintf(
		"LOAD DATA LOCAL INFILE '%s' INTO TABLE %s FIELDS TERMINATED BY '\\t' LINES TERMINATED BY '\\n' "+
			"(product, price, priceVat, priceBefore, priceVatBefore, priceList, hidden, hiddenInMenu, priority, unavailable, recommended)",
		strings.ReplaceAll(tmpPath, "'", "\\'"),
		quoteIdentifier(tableName),
	)

	if _, err := db.Exec(query); err != nil {
		return fmt.Errorf("LOAD DATA INFILE: %w", err)
	}

	return nil
}
