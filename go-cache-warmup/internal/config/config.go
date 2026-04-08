package config

import (
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"os"
	"strings"
)

// Config holds all CLI configuration for the cache-warmup binary.
type Config struct {
	ProdDSN                    string
	CacheDSN                   string
	Dedup                      bool
	Shops                      []string
	DefaultUnregisteredGroups  []string
	Customers                  []string
	CustomerGroups             []string
	Merchants                  []string
	Workers                    int
	Verbose                    bool
}

// Parse reads CLI flags and returns a Config.
func Parse() (*Config, error) {
	cfg := &Config{}

	var shops, defaultGroups, customers, customerGroups, merchants string
	var stdinLists bool

	flag.StringVar(&cfg.ProdDSN, "prod-dsn", "", "Production DB DSN (user:pass@tcp(host:3306)/dbname)")
	flag.StringVar(&cfg.CacheDSN, "cache-dsn", "", "Cache DB DSN (user:pass@tcp(host:3306)/cache)")
	flag.BoolVar(&cfg.Dedup, "dedup", false, "Enable cache deduplication")
	flag.StringVar(&shops, "shops", "", "Comma-separated shop UUIDs")
	flag.StringVar(&defaultGroups, "default-unregistered-groups", "", "Comma-separated default unregistered group UUIDs")
	flag.StringVar(&customers, "customers", "", "Comma-separated customer UUIDs")
	flag.StringVar(&customerGroups, "customer-groups", "", "Comma-separated customer group UUIDs")
	flag.StringVar(&merchants, "merchants", "", "Comma-separated merchant UUIDs")
	flag.IntVar(&cfg.Workers, "workers", 4, "Number of parallel workers")
	flag.BoolVar(&cfg.Verbose, "verbose", true, "Verbose logging to stderr")
	flag.BoolVar(&stdinLists, "stdin-lists", false, "Read filter lists from stdin as JSON")

	flag.Parse()

	if cfg.ProdDSN == "" {
		return nil, fmt.Errorf("--prod-dsn is required")
	}

	if cfg.CacheDSN == "" {
		return nil, fmt.Errorf("--cache-dsn is required")
	}

	cfg.Shops = splitCSV(shops)
	cfg.DefaultUnregisteredGroups = splitCSV(defaultGroups)
	cfg.Customers = splitCSV(customers)
	cfg.CustomerGroups = splitCSV(customerGroups)
	cfg.Merchants = splitCSV(merchants)

	if stdinLists {
		if err := readStdinLists(cfg); err != nil {
			return nil, fmt.Errorf("reading stdin lists: %w", err)
		}
	}

	return cfg, nil
}

// IsPartialUpdate returns true if specific customers/groups/merchants were requested.
func (c *Config) IsPartialUpdate() bool {
	return len(c.Customers) > 0 || len(c.CustomerGroups) > 0 || len(c.Merchants) > 0
}

// readStdinLists reads JSON from stdin and populates filter lists in the config.
func readStdinLists(cfg *Config) error {
	data, err := io.ReadAll(os.Stdin)
	if err != nil {
		return fmt.Errorf("reading stdin: %w", err)
	}

	if len(data) == 0 {
		return nil
	}

	var lists struct {
		Customers      []string `json:"customers"`
		CustomerGroups []string `json:"customer_groups"`
		Merchants      []string `json:"merchants"`
	}

	if err := json.Unmarshal(data, &lists); err != nil {
		return fmt.Errorf("parsing stdin JSON: %w", err)
	}

	cfg.Customers = lists.Customers
	cfg.CustomerGroups = lists.CustomerGroups
	cfg.Merchants = lists.Merchants

	return nil
}

func splitCSV(s string) []string {
	if s == "" {
		return nil
	}

	var result []string

	for p := range strings.SplitSeq(s, ",") {
		p = strings.TrimSpace(p)
		if p != "" {
			result = append(result, p)
		}
	}

	return result
}
