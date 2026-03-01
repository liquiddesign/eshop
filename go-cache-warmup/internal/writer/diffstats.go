package writer

import (
	"container/heap"
	"fmt"
	"log"
	"strings"
	"sync"
)

// DiffStats aggregates DiffUpdate timings across all workers.
type DiffStats struct {
	mu       sync.Mutex
	count    int
	sumMs    phaseSums
	slowest  slowHeap
	heapSize int
}

type phaseSums struct {
	Select  int64
	Compare int64
	Insert  int64
	Update  int64
	Delete  int64
	Total   int64
}

// NewDiffStats creates a DiffStats that tracks the top N slowest tables.
func NewDiffStats(topN int) *DiffStats {
	return &DiffStats{heapSize: topN}
}

// Record adds a DiffTiming to the aggregate stats. Safe for concurrent use.
func (s *DiffStats) Record(t *DiffTiming) {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.count++
	s.sumMs.Select += t.SelectMs
	s.sumMs.Compare += t.CompareMs
	s.sumMs.Insert += t.InsertMs
	s.sumMs.Update += t.UpdateMs
	s.sumMs.Delete += t.DeleteMs
	s.sumMs.Total += t.TotalMs

	// Min-heap of size N: keeps the N largest TotalMs values
	if len(s.slowest) < s.heapSize {
		heap.Push(&s.slowest, t)
	} else if len(s.slowest) > 0 && t.TotalMs > s.slowest[0].TotalMs {
		s.slowest[0] = t
		heap.Fix(&s.slowest, 0)
	}
}

// LogSummary prints the aggregate DiffUpdate statistics.
func (s *DiffStats) LogSummary() {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.count == 0 {
		return
	}

	n := int64(s.count)

	log.Printf("DiffUpdate summary: count=%d | avg: select=%dms compare=%dms insert=%dms update=%dms delete=%dms total=%dms",
		s.count,
		s.sumMs.Select/n, s.sumMs.Compare/n, s.sumMs.Insert/n, s.sumMs.Update/n, s.sumMs.Delete/n, s.sumMs.Total/n,
	)

	// Sort slowest descending by TotalMs
	sorted := make([]*DiffTiming, len(s.slowest))
	copy(sorted, s.slowest)

	for i := len(sorted) - 1; i > 0; i-- {
		for j := 0; j < i; j++ {
			if sorted[j].TotalMs < sorted[j+1].TotalMs {
				sorted[j], sorted[j+1] = sorted[j+1], sorted[j]
			}
		}
	}

	var sb strings.Builder

	sb.WriteString(fmt.Sprintf("DiffUpdate top %d slowest:", len(sorted)))

	for i, t := range sorted {
		sb.WriteString(fmt.Sprintf("\n  %d. %s: %dms (select=%d compare=%d insert=%d update=%d delete=%d) rows: existing=%d new=%d create=%d update=%d delete=%d",
			i+1, t.Table, t.TotalMs,
			t.SelectMs, t.CompareMs, t.InsertMs, t.UpdateMs, t.DeleteMs,
			t.Existing, t.New, t.Created, t.Updated, t.Deleted,
		))
	}

	log.Print(sb.String())
}

// slowHeap is a min-heap of DiffTiming by TotalMs (keeps N largest).
type slowHeap []*DiffTiming

func (h slowHeap) Len() int            { return len(h) }
func (h slowHeap) Less(i, j int) bool  { return h[i].TotalMs < h[j].TotalMs }
func (h slowHeap) Swap(i, j int)       { h[i], h[j] = h[j], h[i] }
func (h *slowHeap) Push(x any)         { *h = append(*h, x.(*DiffTiming)) }
func (h *slowHeap) Pop() any {
	old := *h
	n := len(old)
	x := old[n-1]
	old[n-1] = nil
	*h = old[:n-1]

	return x
}
