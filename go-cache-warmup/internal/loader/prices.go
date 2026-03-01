package loader

import (
	"cmp"
	"database/sql"
	"fmt"
	"log"
	"slices"
	"strings"

	"github.com/liquiddesign/eshop/go-cache-warmup/internal/model"
)

// PriceData holds all product prices indexed by product ID and pricelist ID.
// allProductsWithPrice[productID][priceListID] = Price
type PriceData map[int64]map[int32]*model.Price

// LoadPrices loads all prices for the given pricelist IDs from production DB.
func LoadPrices(prodDB *sql.DB, priceListIDs []int32, verbose bool) (PriceData, error) {
	if len(priceListIDs) == 0 {
		return make(PriceData), nil
	}

	if verbose {
		log.Printf("Loading prices for %d pricelists...", len(priceListIDs))
	}

	placeholders := make([]string, len(priceListIDs))
	args := make([]any, len(priceListIDs))

	for i, id := range priceListIDs {
		placeholders[i] = "?"
		args[i] = id
	}

	query := fmt.Sprintf(`
		SELECT
			product.id AS productId,
			priceList.id AS priceListId,
			priceList.priority AS priceListPriority,
			p.price,
			p.priceVat,
			p.priceBefore,
			p.priceVatBefore,
			p.hidden AS priceHidden
		FROM eshop_price p
		INNER JOIN eshop_pricelist priceList ON p.fk_pricelist = priceList.uuid
		INNER JOIN eshop_product product ON p.fk_product = product.uuid
		WHERE priceList.id IN (%s)
	`, strings.Join(placeholders, ","))

	rows, err := prodDB.Query(query, args...)
	if err != nil {
		return nil, fmt.Errorf("query prices: %w", err)
	}
	defer rows.Close()

	data := make(PriceData)

	// Temporary storage for sorting by priority
	type priceWithPriority struct {
		price    *model.Price
		priority int32
	}

	temp := make(map[int64][]priceWithPriority)

	for rows.Next() {
		var productID int64
		var priceListID int32
		var priceListPriority int32
		var price, priceVat, priceBefore, priceVatBefore sql.NullFloat64
		p := &model.Price{}
		var hidden int

		if err := rows.Scan(
			&productID,
			&priceListID,
			&priceListPriority,
			&price,
			&priceVat,
			&priceBefore,
			&priceVatBefore,
			&hidden,
		); err != nil {
			return nil, fmt.Errorf("scan price: %w", err)
		}

		p.PriceListID = priceListID
		p.PriceListPriority = priceListPriority
		p.Hidden = hidden != 0

		if price.Valid {
			p.Price = price.Float64
		}

		if priceVat.Valid {
			p.PriceVat = priceVat.Float64
		}

		if priceBefore.Valid {
			p.PriceBefore = priceBefore.Float64
		}

		if priceVatBefore.Valid {
			p.PriceVatBefore = priceVatBefore.Float64
		}

		temp[productID] = append(temp[productID], priceWithPriority{
			price:    p,
			priority: priceListPriority,
		})
	}

	// Sort by priority and build final map
	for productID, items := range temp {
		slices.SortFunc(items, func(a, b priceWithPriority) int {
			return cmp.Compare(a.priority, b.priority)
		})

		m := make(map[int32]*model.Price, len(items))

		for _, item := range items {
			m[item.price.PriceListID] = item.price
		}

		data[productID] = m
	}

	if verbose {
		log.Printf("Loaded prices for %d products", len(data))
	}

	return data, nil
}
