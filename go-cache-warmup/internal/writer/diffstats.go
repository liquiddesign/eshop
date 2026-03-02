package writer

import (
	"container/heap"
	"fmt"
	"log"
	"strings"
	"sync"
)

// SwapStats aggregates AtomicSwap timings across all workers.
type SwapStats struct {
	mu       sync.Mutex
	count    int
	sumMs    swapPhaseSums
	slowest  swapSlowHeap
	heapSize int
}

type swapPhaseSums struct {
	Load  int64
	Swap  int64
	Total int64
}

// NewSwapStats creates a SwapStats that tracks the top N slowest tables.
func NewSwapStats(topN int) *SwapStats {
	return &SwapStats{heapSize: topN}
}

// Record adds a SwapTiming to the aggregate stats. Safe for concurrent use.
func (s *SwapStats) Record(t *SwapTiming) {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.count++
	s.sumMs.Load += t.LoadMs
	s.sumMs.Swap += t.SwapMs
	s.sumMs.Total += t.TotalMs

	// Min-heap of size N: keeps the N largest TotalMs values
	if len(s.slowest) < s.heapSize {
		heap.Push(&s.slowest, t)
	} else if len(s.slowest) > 0 && t.TotalMs > s.slowest[0].TotalMs {
		s.slowest[0] = t
		heap.Fix(&s.slowest, 0)
	}
}

// LogSummary prints the aggregate AtomicSwap statistics.
func (s *SwapStats) LogSummary() {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.count == 0 {
		return
	}

	n := int64(s.count)

	log.Printf("AtomicSwap summary: count=%d | avg: load=%dms swap=%dms total=%dms",
		s.count,
		s.sumMs.Load/n, s.sumMs.Swap/n, s.sumMs.Total/n,
	)

	// Sort slowest descending by TotalMs
	sorted := make([]*SwapTiming, len(s.slowest))
	copy(sorted, s.slowest)

	for i := len(sorted) - 1; i > 0; i-- {
		for j := range i {
			if sorted[j].TotalMs < sorted[j+1].TotalMs {
				sorted[j], sorted[j+1] = sorted[j+1], sorted[j]
			}
		}
	}

	var sb strings.Builder

	sb.WriteString(fmt.Sprintf("AtomicSwap top %d slowest:", len(sorted)))

	for i, t := range sorted {
		sb.WriteString(fmt.Sprintf("\n  %d. %s: %dms (load=%d swap=%d) rows=%d",
			i+1, t.Table, t.TotalMs,
			t.LoadMs, t.SwapMs, t.RowCount,
		))
	}

	log.Print(sb.String())
}

// swapSlowHeap is a min-heap of SwapTiming by TotalMs (keeps N largest).
type swapSlowHeap []*SwapTiming

func (h swapSlowHeap) Len() int            { return len(h) }
func (h swapSlowHeap) Less(i, j int) bool  { return h[i].TotalMs < h[j].TotalMs }
func (h swapSlowHeap) Swap(i, j int)       { h[i], h[j] = h[j], h[i] }
func (h *swapSlowHeap) Push(x any)         { *h = append(*h, x.(*SwapTiming)) }
func (h *swapSlowHeap) Pop() any {
	old := *h
	n := len(old)
	x := old[n-1]
	old[n-1] = nil
	*h = old[:n-1]

	return x
}
