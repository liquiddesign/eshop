package orchestrator

import (
	"fmt"
	"log"
	"runtime"
	"sync"
	"sync/atomic"
	"time"

	"github.com/liquiddesign/eshop/go-cache-warmup/internal/combinator"
	"github.com/liquiddesign/eshop/go-cache-warmup/internal/config"
	"github.com/liquiddesign/eshop/go-cache-warmup/internal/db"
	"github.com/liquiddesign/eshop/go-cache-warmup/internal/loader"
	"github.com/liquiddesign/eshop/go-cache-warmup/internal/model"
	"github.com/liquiddesign/eshop/go-cache-warmup/internal/resolver"
	"github.com/liquiddesign/eshop/go-cache-warmup/internal/writer"
)

// Run executes the full cache warmup pipeline.
func Run(cfg *config.Config) (*model.Stats, error) {
	totalStart := time.Now()
	stats := &model.Stats{}

	// 1. Connect to databases
	prodDB, err := db.OpenConnection(cfg.ProdDSN)
	if err != nil {
		return nil, fmt.Errorf("open production DB: %w", err)
	}
	defer prodDB.Close()

	cacheDB, err := db.OpenConnection(cfg.CacheDSN)
	if err != nil {
		return nil, fmt.Errorf("open cache DB: %w", err)
	}
	defer cacheDB.Close()

	w := writer.New(cacheDB, cfg.Verbose)

	// 2. LOAD DATA
	loadStart := time.Now()

	plData, err := loader.LoadPricelists(prodDB, cfg.Verbose)
	if err != nil {
		return nil, fmt.Errorf("load pricelists: %w", err)
	}

	if cfg.Dedup {
		if err := loader.LoadEmptyPricelists(prodDB, plData, cfg.Verbose); err != nil {
			return nil, fmt.Errorf("load empty pricelists: %w", err)
		}
	}

	// Load shops
	shops := cfg.Shops

	if len(shops) == 0 {
		shops, err = loader.LoadShops(prodDB)
		if err != nil {
			return nil, fmt.Errorf("load shops: %w", err)
		}
	}

	// Load default unregistered groups
	defaultGroups := cfg.DefaultUnregisteredGroups

	if len(defaultGroups) == 0 {
		defaultGroups, err = loader.LoadDefaultUnregisteredGroups(prodDB)
		if err != nil {
			return nil, fmt.Errorf("load default unregistered groups: %w", err)
		}
	}

	// Load combination sources per shop
	var allGroupSources []loader.CombinationSource
	var allCustomerIndexes []loader.RawIndex
	var allMerchantIndexes []loader.RawIndex

	shopPKs := make([]*string, 0)

	if len(shops) > 0 {
		for _, s := range shops {
			shopPKs = append(shopPKs, new(s))
		}
	} else {
		shopPKs = append(shopPKs, nil) // null shop
	}

	for _, shopPK := range shopPKs {
		groupSources, err := loader.LoadCustomerGroupSources(
			prodDB, plData, shopPK,
			cfg.CustomerGroups, defaultGroups, cfg.Customers, cfg.Merchants,
			cfg.Verbose,
		)
		if err != nil {
			return nil, fmt.Errorf("load group sources: %w", err)
		}

		allGroupSources = append(allGroupSources, groupSources...)

		customerIndexes, err := loader.LoadCustomerIndexes(
			prodDB, plData, shopPK,
			cfg.Customers, cfg.CustomerGroups, cfg.Merchants,
			cfg.Verbose,
		)
		if err != nil {
			return nil, fmt.Errorf("load customer indexes: %w", err)
		}

		allCustomerIndexes = append(allCustomerIndexes, customerIndexes...)

		merchantIndexes, err := loader.LoadMerchantIndexes(
			prodDB, plData, shopPK,
			cfg.Customers, cfg.CustomerGroups, cfg.Merchants,
			cfg.Verbose,
		)
		if err != nil {
			return nil, fmt.Errorf("load merchant indexes: %w", err)
		}

		allMerchantIndexes = append(allMerchantIndexes, merchantIndexes...)
	}

	stats.LoadTimeMs = time.Since(loadStart).Milliseconds()

	if cfg.Verbose {
		log.Printf("Load phase: %dms", stats.LoadTimeMs)
	}

	// 3. GENERATE COMBINATIONS
	combineStart := time.Now()

	combResult := combinator.Generate(
		allGroupSources,
		allCustomerIndexes,
		allMerchantIndexes,
		plData,
		cfg.Dedup, // filterEmptyPLs when dedup enabled
		cfg.Verbose,
	)

	stats.TotalIndexes = len(combResult.Options)
	stats.VLGroups = len(combResult.VLGroups)
	stats.CombineTimeMs = time.Since(combineStart).Milliseconds()

	if cfg.Verbose {
		log.Printf("Combine phase: %dms (%d indexes, %d VL groups)",
			stats.CombineTimeMs, stats.TotalIndexes, stats.VLGroups)
	}

	// 4. PREFETCH PRICES + VLI
	prefetchStart := time.Now()

	priceData, err := loader.LoadPrices(prodDB, combResult.AllPLIDs, cfg.Verbose)
	if err != nil {
		return nil, fmt.Errorf("load prices: %w", err)
	}

	vliData, err := loader.LoadVLI(prodDB, combResult.AllVLIDs, cfg.Verbose)
	if err != nil {
		return nil, fmt.Errorf("load VLI: %w", err)
	}

	stats.PrefetchTimeMs = time.Since(prefetchStart).Milliseconds()

	if cfg.Verbose {
		log.Printf("Prefetch phase: %dms", stats.PrefetchTimeMs)
	}

	// 5. PROCESS VL GROUPS
	processStart := time.Now()

	if err := processVLGroups(cfg, w, combResult, priceData, vliData, stats); err != nil {
		return nil, fmt.Errorf("process VL groups: %w", err)
	}

	stats.ProcessTimeMs = time.Since(processStart).Milliseconds()

	if cfg.Verbose {
		log.Printf("Process phase: %dms", stats.ProcessTimeMs)
	}

	// 6. Compute peak memory
	var m runtime.MemStats
	runtime.ReadMemStats(&m)
	stats.PeakMemoryMB = float64(m.Sys) / 1024 / 1024

	stats.TotalTimeMs = time.Since(totalStart).Milliseconds()

	return stats, nil
}

// preparedGroup holds pre-computed data for a VL group.
type preparedGroup struct {
	group           model.VLGroup
	productVLI      map[int64]*model.VLI
	productHashData map[int64]map[int32]*resolver.ProductHashData
	groupHash       string
}

// indexWork represents a single index to process in the worker pool.
type indexWork struct {
	index           model.PriceIndex
	productVLI      map[int64]*model.VLI
	productHashData map[int64]map[int32]*resolver.ProductHashData
	priceData       loader.PriceData
}

func processVLGroups(
	cfg *config.Config,
	w *writer.Writer,
	combResult *combinator.Result,
	priceData loader.PriceData,
	vliData loader.VLIData,
	stats *model.Stats,
) error {
	dedup := cfg.Dedup && !cfg.IsPartialUpdate()

	// Load existing state
	existingPricesTablesMap, err := w.LoadExistingPricesTables()
	if err != nil {
		return fmt.Errorf("load existing tables: %w", err)
	}

	existingPricesTables := writer.NewSyncSet(existingPricesTablesMap)
	touchedIndexes := writer.NewEmptySyncSet()

	var existingMappings map[string]*writer.TableMappingInfo

	if dedup {
		if err := w.EnsureMappingTable(); err != nil {
			return fmt.Errorf("ensure mapping table: %w", err)
		}

		migrated, err := w.MigrateOldTableNames()
		if err != nil {
			return fmt.Errorf("migrate old table names: %w", err)
		}

		if migrated && cfg.Verbose {
			log.Printf("Migrated old-style table names: truncated price_table_map to force full rebuild")
		}

		rawMappings, err := w.LoadExistingMappings()
		if err != nil {
			return fmt.Errorf("load mappings: %w", err)
		}

		existingMappings = writer.BuildMappingInfo(rawMappings)
	} else if cfg.IsPartialUpdate() && cfg.Dedup {
		if err := w.EnsureMappingTable(); err != nil {
			return fmt.Errorf("ensure mapping table: %w", err)
		}

		keys := make([]string, 0, len(combResult.Options))
		for index := range combResult.Options {
			keys = append(keys, index)
		}

		if err := w.DeleteStaleMappings(keys); err != nil {
			return fmt.Errorf("delete partial mappings: %w", err)
		}
	}

	if cfg.Verbose {
		log.Printf("Starting prices loop: %d indexes in %d VL groups (dedup=%v)",
			stats.TotalIndexes, stats.VLGroups, dedup)
	}

	var tablesCreated, tablesDeduped, tablesUnchanged, tablesUpdated int64

	// Phase 1: Prepare groups sequentially (resolve VLI + compute hash data + group hash check)
	var prepared []preparedGroup
	indexWorkItems := make([]indexWork, 0, stats.TotalIndexes)

	for _, group := range combResult.VLGroups {
		productVLI := resolver.ResolveVLI(vliData, group.VLIDs)
		productHashData := resolver.PreComputeHashData(productVLI, priceData)

		if dedup {
			groupHash := resolver.ComputeGroupHash(productHashData, group.Indexes)
			groupHashKey := writer.GroupHashPrefix + group.VLKey

			storedMapping := existingMappings[groupHashKey]

			if storedMapping != nil && storedMapping.ContentHash == groupHash {
				// Group unchanged — skip all indexes
				for _, idx := range group.Indexes {
					if mapping, ok := existingMappings[idx.Key]; ok {
						existingPricesTables.Delete(mapping.PhysicalTable)
						touchedIndexes.Set(idx.Key)
						atomic.AddInt64(&tablesUnchanged, 1)
					}
				}

				touchedIndexes.Set(groupHashKey)

				if cfg.Verbose {
					log.Printf("VL group %s: group hash MATCH, skipped %d indexes", group.VLKey, len(group.Indexes))
				}

				continue
			}

			if cfg.Verbose {
				log.Printf("VL group %s: group hash CHANGED, processing %d indexes", group.VLKey, len(group.Indexes))
			}

			prepared = append(prepared, preparedGroup{
				group:           group,
				productVLI:      productVLI,
				productHashData: productHashData,
				groupHash:       groupHash,
			})
		} else {
			prepared = append(prepared, preparedGroup{
				group:           group,
				productVLI:      productVLI,
				productHashData: productHashData,
			})
		}

		// Collect all indexes for the worker pool
		for _, idx := range group.Indexes {
			indexWorkItems = append(indexWorkItems, indexWork{
				index:           idx,
				productVLI:      productVLI,
				productHashData: productHashData,
				priceData:       priceData,
			})
		}
	}

	// Phase 2: Process indexes in parallel via worker pool
	workers := cfg.Workers
	if workers <= 0 {
		workers = runtime.NumCPU()
	}

	swapStats := writer.NewSwapStats(10)

	if len(indexWorkItems) > 0 {
		indexCh := make(chan indexWork, len(indexWorkItems))
		for _, item := range indexWorkItems {
			indexCh <- item
		}
		close(indexCh)

		var processedCount int64
		totalCount := int64(len(indexWorkItems))

		errCh := make(chan error, workers)
		var wg sync.WaitGroup

		for range workers {
			wg.Go(func() {
				for work := range indexCh {
					if err := processOneIndex(
						w, dedup, cfg.Dedup,
						work,
						existingMappings, existingPricesTables, touchedIndexes,
						&tablesCreated, &tablesDeduped, &tablesUnchanged, &tablesUpdated,
						&processedCount, totalCount, swapStats,
					); err != nil {
						errCh <- fmt.Errorf("index %s: %w", work.index.Key, err)
						return
					}
				}
			})
		}

		wg.Wait()
		close(errCh)

		for err := range errCh {
			return err
		}
	}

	// Phase 3: Store group hashes sequentially
	if dedup {
		for _, pg := range prepared {
			groupHashKey := writer.GroupHashPrefix + pg.group.VLKey

			if err := w.RegisterMapping(groupHashKey, writer.GroupTableName, pg.groupHash); err != nil {
				return fmt.Errorf("register group hash: %w", err)
			}

			touchedIndexes.Set(groupHashKey)
		}
	}

	stats.TablesCreated = int(tablesCreated)
	stats.TablesDeduped = int(tablesDeduped)
	stats.TablesUnchanged = int(tablesUnchanged)
	stats.TablesUpdated = int(tablesUpdated)

	// Cleanup
	cleanupStart := time.Now()

	if dedup && !cfg.IsPartialUpdate() {
		if err := w.CleanupStaleMappings(existingMappings, touchedIndexes, existingPricesTables); err != nil {
			return fmt.Errorf("cleanup stale mappings: %w", err)
		}
	} else if !dedup && !cfg.IsPartialUpdate() {
		if err := w.CleanupOrphanedTablesNonDedup(existingPricesTables); err != nil {
			return fmt.Errorf("cleanup orphaned tables: %w", err)
		}
	}

	stats.CleanupTimeMs = time.Since(cleanupStart).Milliseconds()

	if cfg.Verbose {
		log.Printf("Cleanup: %dms", stats.CleanupTimeMs)
		log.Printf("Summary: created=%d deduped=%d unchanged=%d updated=%d",
			tablesCreated, tablesDeduped, tablesUnchanged, tablesUpdated)
		swapStats.LogSummary()
	}

	return nil
}

func processOneIndex(
	w *writer.Writer,
	dedup bool,
	globalDedup bool,
	work indexWork,
	existingMappings map[string]*writer.TableMappingInfo,
	existingPricesTables *writer.SyncSet,
	touchedIndexes *writer.SyncSet,
	tablesCreated, tablesDeduped, tablesUnchanged, tablesUpdated *int64,
	processedCount *int64, totalCount int64,
	swapStats *writer.SwapStats,
) error {
	index := work.index
	tableName := writer.GenerateTableName(writer.PriceTablePrefix, index.Key)

	defer func() {
		current := atomic.AddInt64(processedCount, 1)
		if current%100 == 0 || current == totalCount {
			log.Printf("Progress: %d/%d indexes processed", current, totalCount)
		}
	}()

	if dedup {
		hash := resolver.ComputeIndexHash(work.productHashData, index.PLIDs, index.IsMerchant)

		// existingMappings is read-only, safe for concurrent access
		storedMapping := existingMappings[index.Key]

		// Check unchanged
		if storedMapping != nil && storedMapping.ContentHash == hash {
			existingPricesTables.Delete(storedMapping.PhysicalTable)
			touchedIndexes.Set(index.Key)

			atomic.AddInt64(tablesUnchanged, 1)

			return nil
		}

		// Check dedup match
		existingTable, err := w.FindExistingTableByHash(hash)
		if err != nil {
			return fmt.Errorf("find table by hash: %w", err)
		}

		if existingTable != "" {
			if err := w.RegisterMapping(index.Key, existingTable, hash); err != nil {
				return fmt.Errorf("register dedup mapping: %w", err)
			}

			existingPricesTables.Delete(tableName)
			touchedIndexes.Set(index.Key)

			atomic.AddInt64(tablesDeduped, 1)

			return nil
		}

		// Phase 2: Build rows and write
		priceRows := resolver.BuildPriceRows(work.productVLI, work.priceData, index.PLIDs, index.IsMerchant)

		isNewTable := !existingPricesTables.Has(tableName)

		if isNewTable {
			created, err := createAndLoadTable(w, tableName, priceRows)
			if !created {
				return nil
			}

			if err != nil {
				return err
			}

			if err := w.RegisterMapping(index.Key, tableName, hash); err != nil {
				return fmt.Errorf("register mapping: %w", err)
			}

			touchedIndexes.Set(index.Key)

			atomic.AddInt64(tablesCreated, 1)

			return nil
		}

		existingPricesTables.Delete(tableName)
		touchedIndexes.Set(index.Key)

		// Atomic swap existing table
		timing, err := w.AtomicSwap(tableName, priceRows)
		if err != nil {
			return fmt.Errorf("atomic-swap %s: %w", tableName, err)
		}

		swapStats.Record(timing)

		if err := w.RegisterMapping(index.Key, tableName, hash); err != nil {
			return fmt.Errorf("register mapping: %w", err)
		}

		atomic.AddInt64(tablesUpdated, 1)

		return nil
	}

	// Non-dedup path (partial updates): always compute rows and write.
	// When globalDedup is enabled, register mapping so PHP getter can resolve the table.
	priceRows := resolver.BuildPriceRows(work.productVLI, work.priceData, index.PLIDs, index.IsMerchant)

	isNewTable := !existingPricesTables.Has(tableName)

	if isNewTable {
		created, err := createAndLoadTable(w, tableName, priceRows)
		if !created {
			return nil
		}

		if err != nil {
			return err
		}

		existingPricesTables.Delete(tableName)

		if globalDedup {
			hash := resolver.ComputeIndexHash(work.productHashData, index.PLIDs, index.IsMerchant)

			if err := w.RegisterMapping(index.Key, tableName, hash); err != nil {
				return fmt.Errorf("register mapping: %w", err)
			}
		}

		atomic.AddInt64(tablesCreated, 1)

		return nil
	}

	existingPricesTables.Delete(tableName)

	timing, err := w.AtomicSwap(tableName, priceRows)
	if err != nil {
		return fmt.Errorf("atomic-swap %s: %w", tableName, err)
	}

	swapStats.Record(timing)

	if globalDedup {
		hash := resolver.ComputeIndexHash(work.productHashData, index.PLIDs, index.IsMerchant)

		if err := w.RegisterMapping(index.Key, tableName, hash); err != nil {
			return fmt.Errorf("register mapping: %w", err)
		}
	}

	atomic.AddInt64(tablesUpdated, 1)

	return nil
}

// createAndLoadTable creates a price table and bulk-loads rows into it.
// Returns (created bool, err error). When CreatePriceTable fails, it logs the error and returns (false, nil)
// to match the existing skip-on-error behavior.
func createAndLoadTable(w *writer.Writer, tableName string, priceRows map[int64]*model.PriceRow) (bool, error) {
	if err := w.CreatePriceTable(tableName); err != nil {
		log.Printf("ERROR: create table %s: %v", tableName, err)
		return false, nil
	}

	if err := w.BulkLoadPriceRows(tableName, priceRows); err != nil {
		return true, fmt.Errorf("bulk load %s: %w", tableName, err)
	}

	return true, nil
}
