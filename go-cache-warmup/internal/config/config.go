package config

import (
	"flag"
	"fmt"
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

	flag.StringVar(&cfg.ProdDSN, "prod-dsn", "", "Production DB DSN (user:pass@tcp(host:3306)/dbname)")
	flag.StringVar(&cfg.CacheDSN, "cache-dsn", "", "Cache DB DSN (user:pass@tcp(host:3306)/cache)")
	flag.BoolVar(&cfg.Dedup, "dedup", false, "Enable cache deduplication")
	flag.StringVar(&shops, "shops", "", "Comma-separated shop UUIDs")
	flag.StringVar(&defaultGroups, "default-unregistered-groups", "", "Comma-separated default unregistered group UUIDs")
	flag.StringVar(&customers, "customers", "", "Comma-separated customer UUIDs")
	flag.StringVar(&customerGroups, "customer-groups", "", "Comma-separated customer group UUIDs")
	flag.StringVar(&merchants, "merchants", "", "Comma-separated merchant UUIDs")
	flag.IntVar(&cfg.Workers, "workers", 4, "Number of parallel workers")
	flag.BoolVar(&cfg.Verbose, "verbose", false, "Verbose logging to stderr")

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

	return cfg, nil
}

// IsPartialUpdate returns true if specific customers/groups/merchants were requested.
func (c *Config) IsPartialUpdate() bool {
	return len(c.Customers) > 0 || len(c.CustomerGroups) > 0 || len(c.Merchants) > 0
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
