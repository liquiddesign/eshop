package loader

import (
	"database/sql"
	"fmt"
	"log"
	"strings"
)

// CombinationSource holds VL+PL assignments for a single source (group/customer/merchant).
type CombinationSource struct {
	VLIDs []int32  // VL IDs ordered by priority
	PLPKs []string // PL PKs ordered by priority
	PLIDs []int32  // PL IDs ordered by priority
}

// LoadCustomerGroupSources loads VL and PL assignments for customer groups.
func LoadCustomerGroupSources(
	prodDB *sql.DB,
	plData *PricelistData,
	shopPK *string,
	customerGroups []string,
	defaultUnregisteredGroups []string,
	customers []string,
	merchants []string,
	verbose bool,
) ([]CombinationSource, error) {
	if verbose {
		log.Println("Loading customer group sources...")
	}

	// Determine which groups to query
	var groupFilter string
	var groupArgs []interface{}

	shopArg := interface{}(nil)
	if shopPK != nil {
		shopArg = *shopPK
	}

	if len(customerGroups) > 0 {
		placeholders := make([]string, len(customerGroups))
		for i, g := range customerGroups {
			placeholders[i] = "?"
			groupArgs = append(groupArgs, g)
		}
		groupFilter = "cg.uuid IN (" + strings.Join(placeholders, ",") + ")"
	} else {
		// Skip if specific customers or merchants requested (not groups)
		if len(customers) > 0 || len(merchants) > 0 {
			return nil, nil
		}

		if len(defaultUnregisteredGroups) > 0 {
			placeholders := make([]string, len(defaultUnregisteredGroups))
			for i, g := range defaultUnregisteredGroups {
				placeholders[i] = "?"
				groupArgs = append(groupArgs, g)
			}
			groupFilter = "cg.uuid IN (" + strings.Join(placeholders, ",") + ")"
		} else {
			groupFilter = "cg.uuid = 'unregistered'"
			// no extra args
		}
	}

	// Load groups
	groupQuery := fmt.Sprintf("SELECT cg.uuid FROM eshop_customergroup cg WHERE %s", groupFilter)

	groupRows, err := prodDB.Query(groupQuery, groupArgs...)
	if err != nil {
		return nil, fmt.Errorf("query customer groups: %w", err)
	}
	defer groupRows.Close()

	var groupPKs []string

	for groupRows.Next() {
		var pk string
		if err := groupRows.Scan(&pk); err != nil {
			return nil, fmt.Errorf("scan group pk: %w", err)
		}

		groupPKs = append(groupPKs, pk)
	}

	if len(groupPKs) == 0 {
		return nil, nil
	}

	// Load VLs for each group
	vlRows, err := prodDB.Query(`
		SELECT cg_vl.fk_customergroup, vl.id, vl.priority, vl.uuid
		FROM eshop_customergroup_nxn_eshop_visibilitylist cg_vl
		INNER JOIN eshop_visibilitylist vl ON cg_vl.fk_visibilitylist = vl.uuid
		WHERE vl.hidden = 0
		AND (vl.fk_shop = ? OR vl.fk_shop IS NULL)
		ORDER BY vl.priority, vl.uuid
	`, shopArg)
	if err != nil {
		return nil, fmt.Errorf("query group VLs: %w", err)
	}
	defer vlRows.Close()

	groupVLs := make(map[string][]int32)

	for vlRows.Next() {
		var groupPK string
		var vlID int32
		var priority int32
		var uuid string

		if err := vlRows.Scan(&groupPK, &vlID, &priority, &uuid); err != nil {
			return nil, fmt.Errorf("scan group VL: %w", err)
		}

		groupVLs[groupPK] = append(groupVLs[groupPK], vlID)
	}

	// Load PLs for each group
	plRows, err := prodDB.Query(`
		SELECT cg_pl.fk_customergroup, pl.id, pl.priority, pl.uuid
		FROM eshop_customergroup_nxn_eshop_pricelist cg_pl
		INNER JOIN eshop_pricelist pl ON cg_pl.fk_pricelist = pl.uuid
		WHERE pl.isActive = 1
		AND (pl.fk_shop = ? OR pl.fk_shop IS NULL)
		ORDER BY pl.priority, pl.uuid
	`, shopArg)
	if err != nil {
		return nil, fmt.Errorf("query group PLs: %w", err)
	}
	defer plRows.Close()

	type plEntry struct {
		id int32
		pk string
	}

	groupPLs := make(map[string][]plEntry)

	for plRows.Next() {
		var groupPK, plPK string
		var plID, priority int32

		if err := plRows.Scan(&groupPK, &plID, &priority, &plPK); err != nil {
			return nil, fmt.Errorf("scan group PL: %w", err)
		}

		groupPLs[groupPK] = append(groupPLs[groupPK], plEntry{id: plID, pk: plPK})
	}

	// Build sources
	var sources []CombinationSource

	for _, pk := range groupPKs {
		vls := groupVLs[pk]
		pls := groupPLs[pk]

		if len(vls) == 0 || len(pls) == 0 {
			continue
		}

		src := CombinationSource{
			VLIDs: vls,
		}

		for _, pl := range pls {
			src.PLPKs = append(src.PLPKs, pl.pk)
			src.PLIDs = append(src.PLIDs, pl.id)
		}

		sources = append(sources, src)
	}

	if verbose {
		log.Printf("Loaded %d customer group sources", len(sources))
	}

	return sources, nil
}

// LoadCustomerIndexes loads raw VL-PL index strings from customer NxN tables.
func LoadCustomerIndexes(
	prodDB *sql.DB,
	plData *PricelistData,
	shopPK *string,
	customers []string,
	customerGroups []string,
	merchants []string,
	verbose bool,
) ([]RawIndex, error) {
	if verbose {
		log.Println("Loading customer indexes...")
	}

	shopArg := interface{}(nil)
	if shopPK != nil {
		shopArg = *shopPK
	}

	tables := []string{
		"eshop_customer_nxn_eshop_pricelist",
		"eshop_customer_nxn_eshop_pricelist_favourite",
	}

	var allIndexes []RawIndex

	for _, table := range tables {
		customerFilter := ""
		var args []interface{}
		args = append(args, shopArg, shopArg)

		if len(customers) > 0 {
			placeholders := make([]string, len(customers))
			for i, c := range customers {
				placeholders[i] = "?"
				args = append(args, c)
			}
			customerFilter = "AND c.uuid IN (" + strings.Join(placeholders, ",") + ")"
		} else if len(customerGroups) > 0 || len(merchants) > 0 {
			customerFilter = "AND 1=0"
		}

		query := fmt.Sprintf(`
			SELECT DISTINCT CONCAT(
				GROUP_CONCAT(DISTINCT vl.id ORDER BY vl.priority, vl.uuid),
				'-',
				GROUP_CONCAT(DISTINCT pl.uuid ORDER BY pl.priority, pl.uuid)
			) AS idx
			FROM eshop_customer c
			INNER JOIN %s cxpl ON c.uuid = cxpl.fk_customer
			INNER JOIN eshop_pricelist pl ON cxpl.fk_pricelist = pl.uuid
			INNER JOIN eshop_customer_nxn_eshop_visibilitylist cxvl ON c.uuid = cxvl.fk_customer
			INNER JOIN eshop_visibilitylist vl ON cxvl.fk_visibilitylist = vl.uuid
			WHERE pl.isActive = 1
			AND vl.hidden = 0
			AND (pl.fk_shop = ? OR pl.fk_shop IS NULL)
			AND (vl.fk_shop = ? OR vl.fk_shop IS NULL)
			%s
			GROUP BY c.uuid
		`, table, customerFilter)

		rows, err := prodDB.Query(query, args...)
		if err != nil {
			return nil, fmt.Errorf("query customer indexes from %s: %w", table, err)
		}

		for rows.Next() {
			var idx sql.NullString
			if err := rows.Scan(&idx); err != nil {
				rows.Close()
				return nil, fmt.Errorf("scan customer index: %w", err)
			}

			if idx.Valid && idx.String != "" {
				allIndexes = append(allIndexes, RawIndex{
					Index:      idx.String,
					IsMerchant: false,
				})
			}
		}

		rows.Close()
	}

	if verbose {
		log.Printf("Loaded %d customer raw indexes", len(allIndexes))
	}

	return allIndexes, nil
}

// LoadMerchantIndexes loads raw VL-PL index strings from merchant NxN tables.
func LoadMerchantIndexes(
	prodDB *sql.DB,
	plData *PricelistData,
	shopPK *string,
	customers []string,
	customerGroups []string,
	merchants []string,
	verbose bool,
) ([]RawIndex, error) {
	if verbose {
		log.Println("Loading merchant indexes...")
	}

	shopArg := interface{}(nil)
	if shopPK != nil {
		shopArg = *shopPK
	}

	merchantFilter := ""
	var args []interface{}
	args = append(args, shopArg, shopArg)

	if len(merchants) > 0 {
		placeholders := make([]string, len(merchants))
		for i, m := range merchants {
			placeholders[i] = "?"
			args = append(args, m)
		}
		merchantFilter = "AND m.uuid IN (" + strings.Join(placeholders, ",") + ")"
	} else if len(customerGroups) > 0 || len(customers) > 0 {
		merchantFilter = "AND 1=0"
	}

	query := fmt.Sprintf(`
		SELECT DISTINCT CONCAT(
			GROUP_CONCAT(DISTINCT vl.id ORDER BY vl.priority, vl.uuid),
			'-',
			GROUP_CONCAT(DISTINCT pl.uuid ORDER BY pl.priority, pl.uuid)
		) AS idx
		FROM eshop_merchant m
		INNER JOIN eshop_merchant_nxn_eshop_pricelist mxpl ON m.uuid = mxpl.fk_merchant
		INNER JOIN eshop_pricelist pl ON mxpl.fk_pricelist = pl.uuid
		INNER JOIN eshop_merchant_nxn_eshop_visibilitylist mxvl ON m.uuid = mxvl.fk_merchant
		INNER JOIN eshop_visibilitylist vl ON mxvl.fk_visibilitylist = vl.uuid
		WHERE pl.isActive = 1
		AND vl.hidden = 0
		AND (pl.fk_shop = ? OR pl.fk_shop IS NULL)
		AND (vl.fk_shop = ? OR vl.fk_shop IS NULL)
		%s
		GROUP BY m.uuid
	`, merchantFilter)

	rows, err := prodDB.Query(query, args...)
	if err != nil {
		return nil, fmt.Errorf("query merchant indexes: %w", err)
	}
	defer rows.Close()

	var indexes []RawIndex

	for rows.Next() {
		var idx sql.NullString
		if err := rows.Scan(&idx); err != nil {
			return nil, fmt.Errorf("scan merchant index: %w", err)
		}

		if idx.Valid && idx.String != "" {
			indexes = append(indexes, RawIndex{
				Index:      idx.String,
				IsMerchant: true,
			})
		}
	}

	if verbose {
		log.Printf("Loaded %d merchant raw indexes", len(indexes))
	}

	return indexes, nil
}

// RawIndex holds a raw VL-PL index string with merchant flag.
type RawIndex struct {
	Index      string
	IsMerchant bool
}

// LoadShops loads all shop UUIDs from base_shop.
func LoadShops(prodDB *sql.DB) ([]string, error) {
	rows, err := prodDB.Query("SELECT uuid FROM base_shop")
	if err != nil {
		return nil, fmt.Errorf("query shops: %w", err)
	}
	defer rows.Close()

	var shops []string

	for rows.Next() {
		var uuid string
		if err := rows.Scan(&uuid); err != nil {
			return nil, fmt.Errorf("scan shop: %w", err)
		}

		shops = append(shops, uuid)
	}

	return shops, nil
}

// LoadDefaultUnregisteredGroups loads default unregistered group UUIDs from settings.
func LoadDefaultUnregisteredGroups(prodDB *sql.DB) ([]string, error) {
	rows, err := prodDB.Query(`
		SELECT s.value
		FROM web_setting s
		WHERE s.name LIKE 'defaultUnregisteredGroup%'
	`)
	if err != nil {
		return nil, fmt.Errorf("query default unregistered groups: %w", err)
	}
	defer rows.Close()

	var groups []string

	for rows.Next() {
		var value sql.NullString
		if err := rows.Scan(&value); err != nil {
			return nil, fmt.Errorf("scan setting: %w", err)
		}

		if value.Valid && value.String != "" {
			groups = append(groups, value.String)
		}
	}

	return groups, nil
}
