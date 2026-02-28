package db

// SQL queries used by loaders and writers.
const (
	// QueryPricelists loads all pricelists with discount flag.
	QueryPricelists = `
		SELECT
			pl.uuid AS pk,
			pl.id,
			pl.priority,
			pl.isActive,
			pl.fk_shop,
			CASE WHEN EXISTS (
				SELECT 1 FROM eshop_discount_nxn_eshop_pricelist dnp
				WHERE dnp.fk_pricelist = pl.uuid
			) THEN 1 ELSE 0 END AS hasDiscounts
		FROM eshop_pricelist pl
	`

	// QueryPricelistPriceCount counts prices per pricelist (for empty PL filtering).
	QueryPricelistPriceCount = `
		SELECT priceList.uuid AS pk, COUNT(*) AS cnt
		FROM eshop_price p
		INNER JOIN eshop_pricelist priceList ON p.fk_pricelist = priceList.uuid
		WHERE priceList.isActive = 1
		GROUP BY priceList.uuid
	`

	// QueryCustomerGroups loads customer groups with VL and PL associations.
	QueryCustomerGroupVL = `
		SELECT cg_vl.fk_customergroup, vl.id, vl.priority, vl.uuid
		FROM eshop_customergroup_nxn_eshop_visibilitylist cg_vl
		INNER JOIN eshop_visibilitylist vl ON cg_vl.fk_visibilitylist = vl.uuid
		WHERE vl.hidden = 0
		AND (vl.fk_shop = ? OR vl.fk_shop IS NULL)
		ORDER BY vl.priority, vl.uuid
	`

	QueryCustomerGroupPL = `
		SELECT cg_pl.fk_customergroup, pl.id, pl.priority, pl.uuid
		FROM eshop_customergroup_nxn_eshop_pricelist cg_pl
		INNER JOIN eshop_pricelist pl ON cg_pl.fk_pricelist = pl.uuid
		WHERE pl.isActive = 1
		AND (pl.fk_shop = ? OR pl.fk_shop IS NULL)
		ORDER BY pl.priority, pl.uuid
	`

	// QueryCustomerIndexes generates VL-PL index strings for customers.
	QueryCustomerIndexes = `
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
	`

	// QueryMerchantIndexes generates VL-PL index strings for merchants.
	QueryMerchantIndexes = `
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
	`

	// QueryPrices loads all prices for given pricelists.
	QueryPrices = `
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
	`

	// QueryVLI loads all visibility list items for given VL IDs.
	QueryVLI = `
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
	`

	// QueryShops loads all shop UUIDs.
	QueryShops = `SELECT uuid FROM base_shop`

	// QueryDefaultUnregisteredGroups loads default unregistered groups from settings.
	QueryDefaultUnregisteredGroups = `
		SELECT s.value
		FROM web_setting s
		WHERE s.name LIKE 'defaultUnregisteredGroup%'
	`

	// Cache DB queries
	QueryShowPricesTables = `SHOW TABLES LIKE 'prices\_%'`

	QueryLoadMappings = `SELECT price_index, physical_table, content_hash FROM price_table_map`

	QueryFindTableByHash = `SELECT physical_table FROM price_table_map WHERE content_hash = ? AND physical_table != '__group' LIMIT 1`

	QueryRegisterMapping = `
		INSERT INTO price_table_map (price_index, physical_table, content_hash) VALUES (?, ?, ?)
		ON DUPLICATE KEY UPDATE physical_table = VALUES(physical_table), content_hash = VALUES(content_hash)
	`

	QueryDeleteMapping = `DELETE FROM price_table_map WHERE price_index = ?`

	QueryDeleteStaleMappings = `DELETE FROM price_table_map WHERE price_index IN (%s)`

	QueryReferencedTables = `SELECT DISTINCT physical_table FROM price_table_map`

	QueryEnsureMappingTable = `
		CREATE TABLE IF NOT EXISTS price_table_map (
			price_index VARCHAR(255) NOT NULL PRIMARY KEY,
			physical_table VARCHAR(64) NOT NULL,
			content_hash VARCHAR(64) NOT NULL,
			INDEX idx_content_hash (content_hash)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
	`

	QueryCreatePriceTable = `
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
	`

	QuerySelectCachePrices = `
		SELECT product, price, priceVat, priceBefore, priceVatBefore, priceList,
		       hidden, hiddenInMenu, priority, unavailable, recommended
		FROM %s
	`

	QueryDropTable = `DROP TABLE IF EXISTS %s`
)
