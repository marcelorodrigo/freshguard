// Package store owns the SQLite file: the schema, the migrations that keep it
// current, and the two connection pools the rest of the application uses.
//
// Writes go through one connection so SQLite never has two writers; reads use
// a small read-only pool so a slow list does not block a write. The databases
// under internal/auth and internal/inventory only see *sql.DB, never a file
// path.
package store

import (
	"context"
	"database/sql"
	"embed"
	"errors"
	"fmt"
	"io/fs"
	"net/url"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"

	"modernc.org/sqlite" // registers the "sqlite" driver
	sqlite3 "modernc.org/sqlite/lib"
)

const (
	// FileName is the database file inside the data directory.
	FileName = "freshguard.db"

	// writerConns is one: SQLite allows a single writer, so queueing here is
	// cheaper than watching busy_timeout fire.
	writerConns = 1
	// readerConns is the read parallelism a household can use; WAL lets these
	// connections read while the writer holds the write lock.
	readerConns = 4
)

//go:embed migrations/*.sql
var migrationFiles embed.FS

// Store is an open database file with its writer and reader pools.
type Store struct {
	writer *sql.DB
	reader *sql.DB
}

// Open creates dataDir when missing, opens the database file, applies the
// pending migrations and returns the pools.
func Open(dataDir string) (*Store, error) {
	if err := os.MkdirAll(dataDir, 0o750); err != nil {
		return nil, fmt.Errorf("create data directory %s: %w", dataDir, err)
	}

	path := filepath.Join(dataDir, FileName)

	writer, err := sql.Open("sqlite", writerDSN(path))
	if err != nil {
		return nil, fmt.Errorf("open database %s: %w", path, err)
	}
	writer.SetMaxOpenConns(writerConns)
	writer.SetMaxIdleConns(writerConns)

	// Migrations run before the readers exist: journal_mode=WAL is a property
	// of the file, and a read-only connection cannot switch it on.
	if err := migrate(context.Background(), writer); err != nil {
		_ = writer.Close()
		return nil, err
	}

	reader, err := sql.Open("sqlite", readerDSN(path))
	if err != nil {
		_ = writer.Close()
		return nil, fmt.Errorf("open database %s read-only: %w", path, err)
	}
	reader.SetMaxOpenConns(readerConns)
	reader.SetMaxIdleConns(readerConns)

	s := &Store{writer: writer, reader: reader}
	if err := s.Optimize(context.Background()); err != nil {
		_ = s.Close()
		return nil, err
	}

	return s, nil
}

// Writer returns the pool for statements that change the database.
func (s *Store) Writer() *sql.DB { return s.writer }

// Reader returns the read-only pool.
func (s *Store) Reader() *sql.DB { return s.reader }

// Optimize refreshes the query planner statistics SQLite keeps in the file.
// It runs on start and after an import; SQLite decides what is worth doing.
func (s *Store) Optimize(ctx context.Context) error {
	if _, err := s.writer.ExecContext(ctx, "PRAGMA analysis_limit=1000"); err != nil {
		return fmt.Errorf("set analysis_limit: %w", err)
	}
	if _, err := s.writer.ExecContext(ctx, "PRAGMA optimize"); err != nil {
		return fmt.Errorf("optimize database: %w", err)
	}
	return nil
}

// Close closes both pools and reports the first failure.
func (s *Store) Close() error {
	return errors.Join(s.reader.Close(), s.writer.Close())
}

// IsUniqueViolation reports whether err is SQLite refusing a duplicate value
// in a UNIQUE or PRIMARY KEY column, so callers can turn it into a field rule
// instead of a server error.
func IsUniqueViolation(err error) bool {
	var sqliteErr *sqlite.Error
	if !errors.As(err, &sqliteErr) {
		return false
	}

	switch sqliteErr.Code() {
	case sqlite3.SQLITE_CONSTRAINT_UNIQUE, sqlite3.SQLITE_CONSTRAINT_PRIMARYKEY:
		return true
	default:
		return false
	}
}

// writerDSN builds a file: URI with the pragmas every write connection needs.
func writerDSN(path string) string {
	return dsn(path, false, []string{
		"journal_mode(WAL)",
		"busy_timeout(5000)",
		"foreign_keys(ON)",
		"synchronous(NORMAL)",
		"temp_store(MEMORY)",
	})
}

// readerDSN builds the same URI opened read-only, so a handler that forgets
// its transaction cannot write.
func readerDSN(path string) string {
	return dsn(path, true, []string{
		"busy_timeout(5000)",
		"foreign_keys(ON)",
		"temp_store(MEMORY)",
	})
}

func dsn(path string, readonly bool, pragmas []string) string {
	query := url.Values{}
	if readonly {
		query.Set("mode", "ro")
	}
	for _, pragma := range pragmas {
		query.Add("_pragma", pragma)
	}

	u := url.URL{Scheme: "file", Path: path, RawQuery: query.Encode()}
	return u.String()
}

// migration is one embedded SQL file, numbered by its name.
type migration struct {
	number int
	name   string
	body   string
}

// migrate applies every file numbered above the current user_version, in
// order, each in its own transaction that also bumps user_version.
func migrate(ctx context.Context, db *sql.DB) error {
	migrations, err := loadMigrations()
	if err != nil {
		return err
	}

	version, err := userVersion(ctx, db)
	if err != nil {
		return err
	}

	for _, m := range migrations {
		if m.number <= version {
			continue
		}
		if err := applyMigration(ctx, db, m); err != nil {
			return err
		}
	}

	return nil
}

// applyMigration runs one file and records its number in the same transaction,
// so a failure leaves neither the schema nor user_version half-updated.
func applyMigration(ctx context.Context, db *sql.DB, m migration) error {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin migration %s: %w", m.name, err)
	}
	defer func() { _ = tx.Rollback() }()

	if _, err := tx.ExecContext(ctx, m.body); err != nil {
		return fmt.Errorf("apply migration %s: %w", m.name, err)
	}

	// The number comes from a parsed file name, so it is safe to format.
	if _, err := tx.ExecContext(ctx, fmt.Sprintf("PRAGMA user_version = %d", m.number)); err != nil {
		return fmt.Errorf("record migration %s: %w", m.name, err)
	}

	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit migration %s: %w", m.name, err)
	}
	return nil
}

func userVersion(ctx context.Context, db *sql.DB) (int, error) {
	var version int
	if err := db.QueryRowContext(ctx, "PRAGMA user_version").Scan(&version); err != nil {
		return 0, fmt.Errorf("read user_version: %w", err)
	}
	return version, nil
}

// loadMigrations reads and orders the embedded files. Two files with the same
// number would apply in an order nobody wrote down, so that is an error.
func loadMigrations() ([]migration, error) {
	entries, err := fs.ReadDir(migrationFiles, "migrations")
	if err != nil {
		return nil, fmt.Errorf("read embedded migrations: %w", err)
	}

	migrations := make([]migration, 0, len(entries))
	seen := make(map[int]string, len(entries))

	for _, entry := range entries {
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".sql") {
			continue
		}

		number, err := migrationNumber(entry.Name())
		if err != nil {
			return nil, err
		}
		if other, ok := seen[number]; ok {
			return nil, fmt.Errorf("migrations %s and %s share number %04d", other, entry.Name(), number)
		}
		seen[number] = entry.Name()

		body, err := fs.ReadFile(migrationFiles, "migrations/"+entry.Name())
		if err != nil {
			return nil, fmt.Errorf("read migration %s: %w", entry.Name(), err)
		}
		migrations = append(migrations, migration{number: number, name: entry.Name(), body: string(body)})
	}

	sort.Slice(migrations, func(i, j int) bool { return migrations[i].number < migrations[j].number })
	return migrations, nil
}

// migrationNumber parses the NNNN prefix of NNNN_name.sql.
func migrationNumber(name string) (int, error) {
	prefix, _, ok := strings.Cut(name, "_")
	if !ok {
		return 0, fmt.Errorf("migration %s: expected NNNN_name.sql", name)
	}

	number, err := strconv.Atoi(prefix)
	if err != nil || number <= 0 {
		return 0, fmt.Errorf("migration %s: expected a positive numeric prefix", name)
	}
	return number, nil
}
