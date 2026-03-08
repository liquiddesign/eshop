package loader

import (
	"database/sql"
	"fmt"
	"log"
	"strings"

	"github.com/liquiddesign/eshop/go-cache-warmup/internal/model"
)

// VLIData holds all VLI entries indexed by product ID and VL ID.
// allProductsWithVLI[productID][vlID] = VLI
type VLIData map[int64]map[int32]*model.VLI

// LoadVLI loads all visibility list items for the given VL IDs from production DB.
func LoadVLI(prodDB *sql.DB, vlIDs []int32, verbose bool) (VLIData, error) {
	if len(vlIDs) == 0 {
		return make(VLIData), nil
	}

	if verbose {
		log.Printf("Loading VLI for %d visibility lists...", len(vlIDs))
	}

	placeholders := make([]string, len(vlIDs))
	args := make([]any, len(vlIDs))

	for i, id := range vlIDs {
		placeholders[i] = "?"
		args[i] = id
	}

	query := fmt.Sprintf(`
		SELECT
			product.id AS productId,
			vl.id AS visibilityListId,
			vli.hidden,
			vli.hiddenInMenu,
			vli.priority,
			vli.unavailable,
			vli.recommended
		FROM eshop_visibilitylistitem vli
		INNER JOIN eshop_visibilitylist vl ON vli.fk_visibilityList = vl.uuid
		INNER JOIN eshop_product product ON vli.fk_product = product.uuid
		WHERE vl.id IN (%s)
		ORDER BY product.id ASC, vl.priority ASC
	`, strings.Join(placeholders, ","))

	rows, err := prodDB.Query(query, args...)
	if err != nil {
		return nil, fmt.Errorf("query VLI: %w", err)
	}
	defer rows.Close()

	data := make(VLIData)

	for rows.Next() {
		var productID int64
		var vlID int32
		vli := &model.VLI{}

		if err := rows.Scan(
			&productID,
			&vlID,
			&vli.Hidden,
			&vli.HiddenInMenu,
			&vli.Priority,
			&vli.Unavailable,
			&vli.Recommended,
		); err != nil {
			return nil, fmt.Errorf("scan VLI: %w", err)
		}

		if _, ok := data[productID]; !ok {
			data[productID] = make(map[int32]*model.VLI)
		}

		// Only keep first match per VL (ordered by priority)
		if _, exists := data[productID][vlID]; !exists {
			data[productID][vlID] = vli
		}
	}

	if verbose {
		log.Printf("Loaded VLI for %d products", len(data))
	}

	return data, nil
}
