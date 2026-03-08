package db

import (
	"database/sql"
	"fmt"

	"github.com/go-sql-driver/mysql"
)

// OpenConnection opens a MySQL connection pool with optimized settings.
func OpenConnection(dsn string) (*sql.DB, error) {
	cfg, err := mysql.ParseDSN(dsn)
	if err != nil {
		return nil, fmt.Errorf("parse DSN: %w", err)
	}

	// Enable LOAD DATA LOCAL INFILE
	cfg.AllowAllFiles = true
	cfg.AllowNativePasswords = true
	cfg.InterpolateParams = true

	db, err := sql.Open("mysql", cfg.FormatDSN())
	if err != nil {
		return nil, fmt.Errorf("open connection: %w", err)
	}

	db.SetMaxOpenConns(16)
	db.SetMaxIdleConns(4)

	if err := db.Ping(); err != nil {
		db.Close()
		return nil, fmt.Errorf("ping: %w", err)
	}

	// Set group_concat_max_len for large product lists
	_, err = db.Exec("SET SESSION group_concat_max_len=4294967295")
	if err != nil {
		db.Close()
		return nil, fmt.Errorf("set group_concat_max_len: %w", err)
	}

	return db, nil
}
