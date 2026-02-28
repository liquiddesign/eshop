package combinator

import (
	"fmt"
	"log"
	"sort"
	"strconv"
	"strings"

	"github.com/liquiddesign/eshop/go-cache-warmup/internal/loader"
	"github.com/liquiddesign/eshop/go-cache-warmup/internal/model"
)

// Result holds the output of combination generation.
type Result struct {
	// Options maps index key → true (all unique VL-PL combinations)
	Options map[string]bool
	// MerchantIndexes maps index key → true for merchant-originated indexes
	MerchantIndexes map[string]bool
	// AllVLIDs is the unique set of all VL IDs used
	AllVLIDs []int32
	// AllPLIDs is the unique set of all PL IDs used
	AllPLIDs []int32
	// VLGroups groups indexes by their VL prefix
	VLGroups []model.VLGroup
}

// Generate produces all VL×PL combinations from the given sources and raw indexes.
// This replicates the PHP getAllPossibleVisibilityAndPriceListOptionsHelper logic.
func Generate(
	groupSources []loader.CombinationSource,
	customerRawIndexes []loader.RawIndex,
	merchantRawIndexes []loader.RawIndex,
	plData *loader.PricelistData,
	filterEmptyPLs bool,
	verbose bool,
) *Result {
	if verbose {
		log.Println("Generating VL×PL combinations...")
	}

	result := &Result{
		Options:         make(map[string]bool),
		MerchantIndexes: make(map[string]bool),
	}

	allVLs := make(map[int32]bool)
	allPLs := make(map[int32]bool)

	// Process customer group sources
	for _, src := range groupSources {
		processSource(src, plData, filterEmptyPLs, result, allVLs, allPLs, false)
	}

	// Process customer raw indexes (VL-PL strings from DB GROUP_CONCAT)
	for _, raw := range customerRawIndexes {
		processRawIndex(raw, plData, filterEmptyPLs, result, allVLs, allPLs)
	}

	// Process merchant raw indexes
	for _, raw := range merchantRawIndexes {
		processRawIndex(raw, plData, filterEmptyPLs, result, allVLs, allPLs)
	}

	// Collect unique VL and PL IDs
	result.AllVLIDs = mapKeysInt32(allVLs)
	result.AllPLIDs = mapKeysInt32(allPLs)
	sort.Slice(result.AllVLIDs, func(i, j int) bool { return result.AllVLIDs[i] < result.AllVLIDs[j] })
	sort.Slice(result.AllPLIDs, func(i, j int) bool { return result.AllPLIDs[i] < result.AllPLIDs[j] })

	// Build VL groups
	result.VLGroups = buildVLGroups(result.Options, result.MerchantIndexes)

	if verbose {
		log.Printf("Generated %d combinations in %d VL groups (%d VLs, %d PLs)",
			len(result.Options), len(result.VLGroups), len(result.AllVLIDs), len(result.AllPLIDs))
	}

	return result
}

// processSource generates combinations from a CombinationSource (customer group).
func processSource(
	src loader.CombinationSource,
	plData *loader.PricelistData,
	filterEmptyPLs bool,
	result *Result,
	allVLs, allPLs map[int32]bool,
	isMerchant bool,
) {
	for _, vlID := range src.VLIDs {
		allVLs[vlID] = true
	}

	for _, plID := range src.PLIDs {
		allPLs[plID] = true
	}

	// Split PLs into fixed and dynamic
	var fixedPLPKs []string
	var fixedPLIDs []int32
	var dynamicPLPKs []string
	var dynamicPLIDs []int32

	for i, pk := range src.PLPKs {
		pl := plData.ByPK[pk]
		if pl == nil {
			continue
		}

		if pl.HasDiscounts {
			if filterEmptyPLs && plData.EmptyPKs[pk] {
				continue
			}

			dynamicPLPKs = append(dynamicPLPKs, pk)
			dynamicPLIDs = append(dynamicPLIDs, src.PLIDs[i])
		} else {
			fixedPLPKs = append(fixedPLPKs, pk)
			fixedPLIDs = append(fixedPLIDs, src.PLIDs[i])
		}
	}

	// Power-set of dynamic PLs
	combos := generatePowerSet(dynamicPLIDs)

	// Build index for each combination
	vlStr := int32SliceToStr(src.VLIDs)

	for _, combo := range combos {
		// Rebuild final PL list preserving original order
		comboSet := make(map[int32]bool, len(combo))
		for _, id := range combo {
			comboSet[id] = true
		}

		var finalPLIDs []int32

		for _, id := range src.PLIDs {
			isFixed := false
			for _, fid := range fixedPLIDs {
				if id == fid {
					isFixed = true
					break
				}
			}

			if isFixed || comboSet[id] {
				finalPLIDs = append(finalPLIDs, id)
			}
		}

		index := vlStr + "-" + int32SliceToStr(finalPLIDs)
		result.Options[index] = true

		if isMerchant {
			result.MerchantIndexes[index] = true
		}
	}
}

// processRawIndex processes a raw VL-PL index from customer/merchant NxN queries.
// The PL part contains UUIDs (PKs), which need to be resolved to IDs.
func processRawIndex(
	raw loader.RawIndex,
	plData *loader.PricelistData,
	filterEmptyPLs bool,
	result *Result,
	allVLs, allPLs map[int32]bool,
) {
	parts := strings.SplitN(raw.Index, "-", 2)
	if len(parts) != 2 {
		return
	}

	// Parse VL IDs (these are numeric IDs from GROUP_CONCAT)
	vlStrs := strings.Split(parts[0], ",")
	vlIDs := make([]int32, 0, len(vlStrs))

	for _, s := range vlStrs {
		id, err := strconv.ParseInt(s, 10, 32)
		if err != nil {
			continue
		}

		vlIDs = append(vlIDs, int32(id))
		allVLs[int32(id)] = true
	}

	// Parse PL PKs (UUIDs from GROUP_CONCAT) → resolve to IDs
	plPKs := strings.Split(parts[1], ",")
	var plIDs []int32
	var srcPLPKs []string
	var srcPLIDs []int32

	for _, pk := range plPKs {
		pl := plData.ByPK[pk]
		if pl == nil {
			continue
		}

		allPLs[pl.ID] = true
		srcPLPKs = append(srcPLPKs, pk)
		srcPLIDs = append(srcPLIDs, pl.ID)
		plIDs = append(plIDs, pl.ID)
	}

	// Split into fixed and dynamic, generate combinations
	src := loader.CombinationSource{
		VLIDs: vlIDs,
		PLPKs: srcPLPKs,
		PLIDs: srcPLIDs,
	}

	processSource(src, plData, filterEmptyPLs, result, allVLs, allPLs, raw.IsMerchant)
}

// generatePowerSet generates all subsets of the input slice (including empty set).
func generatePowerSet(elements []int32) [][]int32 {
	result := [][]int32{{}} // Start with empty combination

	for _, elem := range elements {
		newCombos := make([][]int32, len(result))
		for i, combo := range result {
			newCombo := make([]int32, len(combo)+1)
			copy(newCombo, combo)
			newCombo[len(combo)] = elem
			newCombos[i] = newCombo
		}

		result = append(result, newCombos...)
	}

	return result
}

// buildVLGroups groups indexes by their VL prefix.
func buildVLGroups(options map[string]bool, merchantIndexes map[string]bool) []model.VLGroup {
	indexesByVL := make(map[string][]model.PriceIndex)

	for index := range options {
		parts := strings.SplitN(index, "-", 2)
		if len(parts) != 2 {
			continue
		}

		vlKey := parts[0]
		vlIDs := parseIntList(parts[0])
		plIDs := parseIntList(parts[1])

		pi := model.PriceIndex{
			Key:        index,
			VLIDs:      vlIDs,
			PLIDs:      plIDs,
			IsMerchant: merchantIndexes[index],
		}

		indexesByVL[vlKey] = append(indexesByVL[vlKey], pi)
	}

	groups := make([]model.VLGroup, 0, len(indexesByVL))

	for vlKey, indexes := range indexesByVL {
		// Sort indexes by key for deterministic order (Go maps iterate randomly)
		sort.Slice(indexes, func(i, j int) bool {
			return indexes[i].Key < indexes[j].Key
		})

		groups = append(groups, model.VLGroup{
			VLKey:   vlKey,
			VLIDs:   parseIntList(vlKey),
			Indexes: indexes,
		})
	}

	// Sort groups for deterministic processing
	sort.Slice(groups, func(i, j int) bool {
		return groups[i].VLKey < groups[j].VLKey
	})

	return groups
}

func parseIntList(s string) []int32 {
	if s == "" {
		return nil
	}

	parts := strings.Split(s, ",")
	result := make([]int32, 0, len(parts))

	for _, p := range parts {
		id, err := strconv.ParseInt(p, 10, 32)
		if err != nil {
			continue
		}

		result = append(result, int32(id))
	}

	return result
}

func int32SliceToStr(ids []int32) string {
	strs := make([]string, len(ids))
	for i, id := range ids {
		strs[i] = fmt.Sprintf("%d", id)
	}

	return strings.Join(strs, ",")
}

func mapKeysInt32(m map[int32]bool) []int32 {
	keys := make([]int32, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}

	return keys
}
