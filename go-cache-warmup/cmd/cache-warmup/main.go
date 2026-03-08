package main

import (
	"encoding/json"
	"fmt"
	"log"
	"os"

	"github.com/liquiddesign/eshop/go-cache-warmup/internal/config"
	"github.com/liquiddesign/eshop/go-cache-warmup/internal/orchestrator"
)

func main() {
	// Log to stderr (PHP captures this for Tracy logging)
	log.SetOutput(os.Stderr)
	log.SetFlags(log.Ltime | log.Lmicroseconds)

	cfg, err := config.Parse()
	if err != nil {
		log.Fatalf("Config error: %v", err)
	}

	if cfg.Verbose {
		log.Println("cache-warmup starting...")
		log.Printf("Config: dedup=%v workers=%d partial=%v",
			cfg.Dedup, cfg.Workers, cfg.IsPartialUpdate())
	}

	stats, err := orchestrator.Run(cfg)
	if err != nil {
		log.Fatalf("Fatal error: %v", err)
	}

	// Output JSON stats to stdout
	output, err := json.Marshal(stats)
	if err != nil {
		log.Fatalf("JSON marshal error: %v", err)
	}

	fmt.Println(string(output))

	if cfg.Verbose {
		log.Printf("Done in %dms", stats.TotalTimeMs)
	}
}
