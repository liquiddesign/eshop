package model

// Price represents a product price from eshop_price table.
type Price struct {
	Price             float64
	PriceVat          float64
	PriceBefore       float64 // 0 means NULL
	PriceVatBefore    float64 // 0 means NULL
	PriceListID       int32
	PriceListPriority int32
	Hidden            bool
}

// VLI represents a visibility list item from eshop_visibilitylistitem table.
type VLI struct {
	Hidden       bool
	HiddenInMenu bool
	Unavailable  bool
	Recommended  bool
	Priority     int16
}

// Pricelist represents an eshop_pricelist row.
type Pricelist struct {
	PK           string
	ID           int32
	Priority     int32
	IsActive     bool
	HasDiscounts bool
	ShopFK       *string
}

// PriceIndex represents a single VL×PL combination to process.
type PriceIndex struct {
	Key        string  // "1,2-5,6,7"
	VLIDs      []int32 // visibility list IDs
	PLIDs      []int32 // pricelist IDs
	IsMerchant bool
}

// VLGroup represents a group of indexes sharing the same VL prefix.
type VLGroup struct {
	VLKey   string       // "1,2"
	VLIDs   []int32      // parsed VL IDs
	Indexes []PriceIndex // all indexes in this group
}

// PriceRow represents a row in a prices_* cache table.
type PriceRow struct {
	Product        int64
	Price          float64
	PriceVat       float64
	PriceBefore    float64 // 0 means NULL
	PriceVatBefore float64 // 0 means NULL
	PriceList      int32
	Hidden         bool
	HiddenInMenu   bool
	Priority       int16
	Unavailable    bool
	Recommended    bool
}

// TableMapping represents a row in the price_table_map table.
type TableMapping struct {
	PriceIndex    string
	PhysicalTable string
	ContentHash   string
}

// Stats holds warmup execution statistics for JSON output.
type Stats struct {
	TotalIndexes    int     `json:"totalIndexes"`
	VLGroups        int     `json:"vlGroups"`
	TablesCreated   int     `json:"tablesCreated"`
	TablesDeduped   int     `json:"tablesDeduped"`
	TablesUnchanged int     `json:"tablesUnchanged"`
	TablesUpdated   int     `json:"tablesUpdated"`
	LoadTimeMs      int64   `json:"loadTimeMs"`
	CombineTimeMs   int64   `json:"combineTimeMs"`
	PrefetchTimeMs  int64   `json:"prefetchTimeMs"`
	ProcessTimeMs   int64   `json:"processTimeMs"`
	CleanupTimeMs   int64   `json:"cleanupTimeMs"`
	TotalTimeMs     int64   `json:"totalTimeMs"`
	PeakMemoryMB    float64 `json:"peakMemoryMB"`
}
