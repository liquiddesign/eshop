package resolver

import (
	"crypto/sha256"
	"fmt"
	"sort"

	"github.com/liquiddesign/eshop/go-cache-warmup/internal/loader"
	"github.com/liquiddesign/eshop/go-cache-warmup/internal/model"
)

// ProductHashData holds pre-computed hash string and hidden flag per product per PL.
type ProductHashData struct {
	HashStr           string
	PriceHidden       bool
	PriceListPriority int32
}

// ResolveVLI resolves VLI for each product using first-match VL logic.
// Returns map[productID] → VLI (first matching VL from the ordered list).
func ResolveVLI(vliData loader.VLIData, vlIDs []int32) map[int64]*model.VLI {
	productVLI := make(map[int64]*model.VLI, len(vliData))

	for product, vliItems := range vliData {
		for _, vlID := range vlIDs {
			if vli, ok := vliItems[vlID]; ok {
				productVLI[product] = vli
				break
			}
		}
	}

	return productVLI
}

// PreComputeHashData pre-computes hash strings per product per PL.
// This avoids string formatting in the per-index loop.
func PreComputeHashData(
	productVLI map[int64]*model.VLI,
	priceData loader.PriceData,
) map[int64]map[int32]*ProductHashData {
	result := make(map[int64]map[int32]*ProductHashData, len(productVLI))

	for product, vli := range productVLI {
		priceItems, ok := priceData[product]
		if !ok {
			continue
		}

		plData := make(map[int32]*ProductHashData, len(priceItems))

		for plID, price := range priceItems {
			pb := formatNullable(price.PriceBefore)
			pvb := formatNullable(price.PriceVatBefore)

			hashStr := fmt.Sprintf("%d:%d,%s,%s,%s,%s,%d,%v,%v,%d,%v,%v\n",
				product, product,
				formatFloat(price.Price),
				formatFloat(price.PriceVat),
				pb, pvb,
				plID,
				boolToPhp(vli.Hidden),
				boolToPhp(vli.HiddenInMenu),
				vli.Priority,
				boolToPhp(vli.Unavailable),
				boolToPhp(vli.Recommended),
			)

			plData[plID] = &ProductHashData{
				HashStr:           hashStr,
				PriceHidden:       price.Hidden,
				PriceListPriority: price.PriceListPriority,
			}
		}

		result[product] = plData
	}

	return result
}

// ComputeIndexHash computes the SHA-256 hash for a single price index.
// Uses first-match PL logic: for each product, use the first PL that has a price.
// Products are sorted by ID for deterministic hash across runs.
func ComputeIndexHash(
	productHashData map[int64]map[int32]*ProductHashData,
	plIDs []int32,
	isMerchant bool,
) string {
	h := sha256.New()

	// Sort product IDs for deterministic iteration (Go maps have random order)
	productIDs := make([]int64, 0, len(productHashData))
	for pid := range productHashData {
		productIDs = append(productIDs, pid)
	}

	sort.Slice(productIDs, func(i, j int) bool { return productIDs[i] < productIDs[j] })

	for _, pid := range productIDs {
		plData := productHashData[pid]

		for _, plID := range plIDs {
			data, ok := plData[plID]
			if !ok {
				continue
			}

			if !isMerchant && data.PriceHidden {
				continue
			}

			h.Write([]byte(data.HashStr))
			break // first-match PL
		}
	}

	return fmt.Sprintf("%x", h.Sum(nil))
}

// ComputeGroupHash computes the group-level hash for all indexes in a VL group.
// Product and PL iteration order must match PHP (sorted by key) for hash compatibility.
func ComputeGroupHash(
	productHashData map[int64]map[int32]*ProductHashData,
	indexes []model.PriceIndex,
) string {
	h := sha256.New()

	// Sort product IDs to match PHP iteration order (int-keyed arrays iterate in insertion order,
	// which for productHashData is the order of productVLI iteration — also int-keyed).
	productIDs := make([]int64, 0, len(productHashData))
	for pid := range productHashData {
		productIDs = append(productIDs, pid)
	}

	sort.Slice(productIDs, func(i, j int) bool { return productIDs[i] < productIDs[j] })

	for _, pid := range productIDs {
		plData := productHashData[pid]

		// Sort PL IDs by pricelist priority (matching PHP's uasort by priceListPriority)
		plIDs := make([]int32, 0, len(plData))
		for plID := range plData {
			plIDs = append(plIDs, plID)
		}

		sort.Slice(plIDs, func(i, j int) bool {
			pi := plData[plIDs[i]].PriceListPriority
			pj := plData[plIDs[j]].PriceListPriority

			if pi != pj {
				return pi < pj
			}

			return plIDs[i] < plIDs[j]
		})

		for _, plID := range plIDs {
			h.Write([]byte(plData[plID].HashStr))
		}
	}

	// Hash index configuration
	for _, idx := range indexes {
		suffix := "N"
		if idx.IsMerchant {
			suffix = "M"
		}

		h.Write([]byte(idx.Key + suffix))
	}

	return fmt.Sprintf("%x", h.Sum(nil))
}

// BuildPriceRows builds the actual price rows for a single index.
// Uses first-match PL logic.
func BuildPriceRows(
	productVLI map[int64]*model.VLI,
	priceData loader.PriceData,
	plIDs []int32,
	isMerchant bool,
) map[int64]*model.PriceRow {
	rows := make(map[int64]*model.PriceRow)

	for product, vli := range productVLI {
		priceItems, ok := priceData[product]
		if !ok {
			continue
		}

		for _, plID := range plIDs {
			price, ok := priceItems[plID]
			if !ok {
				continue
			}

			if !isMerchant && price.Hidden {
				continue
			}

			rows[product] = &model.PriceRow{
				Product:        product,
				Price:          price.Price,
				PriceVat:       price.PriceVat,
				PriceBefore:    price.PriceBefore,
				PriceVatBefore: price.PriceVatBefore,
				PriceList:      plID,
				Hidden:         vli.Hidden,
				HiddenInMenu:   vli.HiddenInMenu,
				Priority:       vli.Priority,
				Unavailable:    vli.Unavailable,
				Recommended:    vli.Recommended,
			}

			break // first-match PL
		}
	}

	return rows
}

// formatFloat formats a float64 to string matching PHP's default float formatting.
func formatFloat(f float64) string {
	// PHP's default float-to-string uses %G-like formatting
	return fmt.Sprintf("%g", f)
}

// formatNullable formats a nullable float (0 = null → empty string).
func formatNullable(f float64) string {
	if f == 0 {
		return ""
	}

	return fmt.Sprintf("%g", f)
}

// boolToPhp converts a Go bool to PHP's string interpolation of DB int values (0/1).
func boolToPhp(b bool) string {
	if b {
		return "1"
	}

	return "0"
}
