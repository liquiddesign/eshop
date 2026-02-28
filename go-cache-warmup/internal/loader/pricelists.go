package loader

import (
	"database/sql"
	"fmt"
	"log"

	"github.com/liquiddesign/eshop/go-cache-warmup/internal/db"
	"github.com/liquiddesign/eshop/go-cache-warmup/internal/model"
)

// PricelistData holds loaded pricelist information indexed by PK.
type PricelistData struct {
	// ByPK maps pricelist PK (uuid) → Pricelist
	ByPK map[string]*model.Pricelist
	// ByID maps pricelist numeric ID → Pricelist
	ByID map[int32]*model.Pricelist
	// EmptyPKs is the set of pricelist PKs with zero prices (for filtering)
	EmptyPKs map[string]bool
}

// LoadPricelists loads all pricelists and their discount flags from production DB.
func LoadPricelists(prodDB *sql.DB, verbose bool) (*PricelistData, error) {
	if verbose {
		log.Println("Loading pricelists...")
	}

	rows, err := prodDB.Query(db.QueryPricelists)
	if err != nil {
		return nil, fmt.Errorf("query pricelists: %w", err)
	}
	defer rows.Close()

	data := &PricelistData{
		ByPK: make(map[string]*model.Pricelist),
		ByID: make(map[int32]*model.Pricelist),
	}

	for rows.Next() {
		pl := &model.Pricelist{}
		var hasDiscounts int
		var shopFK sql.NullString

		if err := rows.Scan(&pl.PK, &pl.ID, &pl.Priority, &pl.IsActive, &shopFK, &hasDiscounts); err != nil {
			return nil, fmt.Errorf("scan pricelist: %w", err)
		}

		pl.HasDiscounts = hasDiscounts == 1

		if shopFK.Valid {
			s := shopFK.String
			pl.ShopFK = &s
		}

		data.ByPK[pl.PK] = pl
		data.ByID[pl.ID] = pl
	}

	if verbose {
		log.Printf("Loaded %d pricelists", len(data.ByPK))
	}

	return data, nil
}

// LoadEmptyPricelists populates EmptyPKs in the PricelistData.
func LoadEmptyPricelists(prodDB *sql.DB, data *PricelistData, verbose bool) error {
	if verbose {
		log.Println("Loading empty pricelist PKs...")
	}

	rows, err := prodDB.Query(db.QueryPricelistPriceCount)
	if err != nil {
		return fmt.Errorf("query pricelist price counts: %w", err)
	}
	defer rows.Close()

	nonEmpty := make(map[string]bool)

	for rows.Next() {
		var pk string
		var cnt int

		if err := rows.Scan(&pk, &cnt); err != nil {
			return fmt.Errorf("scan price count: %w", err)
		}

		nonEmpty[pk] = true
	}

	data.EmptyPKs = make(map[string]bool)

	for pk := range data.ByPK {
		if !nonEmpty[pk] {
			data.EmptyPKs[pk] = true
		}
	}

	if verbose {
		log.Printf("Found %d empty pricelists", len(data.EmptyPKs))
	}

	return nil
}
