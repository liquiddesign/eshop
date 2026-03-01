package writer

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"sync"

	"github.com/liquiddesign/eshop/go-cache-warmup/internal/model"
)

const (
	// GroupHashPrefix is the prefix for group hash keys in price_table_map.
	GroupHashPrefix = "__group_"
	// GroupTableName is the physical_table value for group hash entries.
	GroupTableName = "__group"
	// PriceTablePrefix is the prefix for price cache tables.
	PriceTablePrefix = "prices_"
)

// GenerateTableName generates the cache table name for a price index.
// If the name exceeds 63 chars, it generates a hashed name.
func GenerateTableName(prefix, index string) string {
	name := prefix + index
	if len(name) <= 63 {
		return name
	}

	// Generate deterministic hash-based name matching PHP DIConnection::generateUuid7
	h := sha256.Sum256([]byte(name))
	hashStr := hex.EncodeToString(h[:8])

	return fmt.Sprintf("cache_prices_%s", hashStr)
}

// CleanupStaleMappings removes stale mappings and drops orphaned tables.
func (w *Writer) CleanupStaleMappings(
	existingMappings map[string]*TableMappingInfo,
	touchedIndexes *SyncSet,
	existingPricesTables *SyncSet,
) error {
	// Find stale mappings
	var staleIndexes []string

	for idx := range existingMappings {
		if !touchedIndexes.Has(idx) {
			staleIndexes = append(staleIndexes, idx)
		}
	}

	if len(staleIndexes) > 0 {
		if err := w.DeleteStaleMappings(staleIndexes); err != nil {
			return fmt.Errorf("delete stale mappings: %w", err)
		}
	}

	// Find referenced tables
	referenced, err := w.GetReferencedTables()
	if err != nil {
		return fmt.Errorf("get referenced tables: %w", err)
	}

	// Drop orphaned tables
	for _, tableName := range existingPricesTables.Keys() {
		if !referenced[tableName] {
			if err := w.DropTable(tableName); err != nil {
				return fmt.Errorf("drop orphaned table %s: %w", tableName, err)
			}
		}
	}

	return nil
}

// CleanupOrphanedTablesNonDedup drops price tables not referenced by any index (non-dedup mode).
func (w *Writer) CleanupOrphanedTablesNonDedup(existingPricesTables *SyncSet) error {
	for _, tableName := range existingPricesTables.Keys() {
		if err := w.DropTable(tableName); err != nil {
			return fmt.Errorf("drop table %s: %w", tableName, err)
		}
	}

	return nil
}

// TableMappingInfo is an alias for quick access.
type TableMappingInfo struct {
	PhysicalTable string
	ContentHash   string
}

// BuildMappingInfo converts model.TableMapping to lookup map.
func BuildMappingInfo(mappings map[string]*model.TableMapping) map[string]*TableMappingInfo {
	result := make(map[string]*TableMappingInfo, len(mappings))

	for k, m := range mappings {
		result[k] = &TableMappingInfo{
			PhysicalTable: m.PhysicalTable,
			ContentHash:   m.ContentHash,
		}
	}

	return result
}

// SyncSet is a concurrent-safe string set.
type SyncSet struct {
	mu sync.RWMutex
	m  map[string]bool
}

// NewSyncSet creates a new SyncSet from a regular map.
func NewSyncSet(m map[string]bool) *SyncSet {
	return &SyncSet{m: m}
}

// NewEmptySyncSet creates an empty SyncSet.
func NewEmptySyncSet() *SyncSet {
	return &SyncSet{m: make(map[string]bool)}
}

// Has checks if a key exists.
func (s *SyncSet) Has(key string) bool {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.m[key]
}

// Set adds a key to the set.
func (s *SyncSet) Set(key string) {
	s.mu.Lock()
	s.m[key] = true
	s.mu.Unlock()
}

// Delete removes a key from the set.
func (s *SyncSet) Delete(key string) {
	s.mu.Lock()
	delete(s.m, key)
	s.mu.Unlock()
}

// Keys returns all keys.
func (s *SyncSet) Keys() []string {
	s.mu.RLock()
	defer s.mu.RUnlock()
	keys := make([]string, 0, len(s.m))
	for k := range s.m {
		keys = append(keys, k)
	}
	return keys
}
