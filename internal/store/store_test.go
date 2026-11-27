package store

import (
	"context"
	"database/sql"
	"os"
	"path/filepath"
	"slices"
	"testing"
)

func testStore(t *testing.T) *Store {
	t.Helper()

	st, err := Open(t.TempDir())
	if err != nil {
		t.Fatalf("Open: %v", err)
	}
	t.Cleanup(func() {
		if err := st.Close(); err != nil {
			t.Errorf("Close: %v", err)
		}
	})

	return st
}

func TestOpenCreatesTheSchema(t *testing.T) {
	t.Parallel()

	st := testStore(t)

	ctx := context.Background()
	rows, err := st.Reader().QueryContext(ctx,
		"SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
	if err != nil {
		t.Fatalf("list tables: %v", err)
	}
	defer func() { _ = rows.Close() }()

	var tables []string
	for rows.Next() {
		var name string
		if err := rows.Scan(&name); err != nil {
			t.Fatalf("scan table name: %v", err)
		}
		tables = append(tables, name)
	}
	if err := rows.Err(); err != nil {
		t.Fatalf("iterate tables: %v", err)
	}

	want := []string{
		"batches", "item_tags", "items", "locations",
		"password_resets", "sessions", "tags", "users",
	}
	if !slices.Equal(tables, want) {
		t.Errorf("tables = %v, want %v", tables, want)
	}
}

func TestOpenRejectsADatabaseThatIsADirectory(t *testing.T) {
	t.Parallel()

	dataDir := t.TempDir()
	if err := os.Mkdir(filepath.Join(dataDir, FileName), 0o750); err != nil {
		t.Fatalf("create database directory: %v", err)
	}

	if _, err := Open(dataDir); err == nil {
		t.Fatal("Open with a directory in place of the database file = nil error, want failure")
	}
}

func TestOpenIsIdempotent(t *testing.T) {
	t.Parallel()

	dataDir := t.TempDir()

	first, err := Open(dataDir)
	if err != nil {
		t.Fatalf("first Open: %v", err)
	}
	if _, err := first.Writer().Exec(`INSERT INTO users
		(id, name, email, password_hash, is_admin, must_reset, created_at, updated_at)
		VALUES ('0190a1b2-0000-7000-8000-000000000001', 'Marcelo', 'm@example.com', 'x', 1, 0,
		        '2026-01-24T08:27:26Z', '2026-01-24T08:27:26Z')`); err != nil {
		t.Fatalf("seed user: %v", err)
	}
	if err := first.Close(); err != nil {
		t.Fatalf("close first store: %v", err)
	}

	second, err := Open(dataDir)
	if err != nil {
		t.Fatalf("second Open: %v", err)
	}
	t.Cleanup(func() { _ = second.Close() })

	version, err := userVersion(context.Background(), second.Writer())
	if err != nil {
		t.Fatalf("user_version: %v", err)
	}

	migrations, err := loadMigrations()
	if err != nil {
		t.Fatalf("loadMigrations: %v", err)
	}
	if want := migrations[len(migrations)-1].number; version != want {
		t.Errorf("user_version = %d, want %d (the last migration)", version, want)
	}

	var name string
	if err := second.Reader().QueryRow(`SELECT name FROM users WHERE email = 'm@example.com'`).Scan(&name); err != nil {
		t.Fatalf("read seeded user after reopen: %v", err)
	}
	if name != "Marcelo" {
		t.Errorf("name = %q, want Marcelo", name)
	}
}

func TestOpenConfiguresThePools(t *testing.T) {
	t.Parallel()

	st := testStore(t)
	ctx := context.Background()

	var journalMode string
	if err := st.Writer().QueryRowContext(ctx, "PRAGMA journal_mode").Scan(&journalMode); err != nil {
		t.Fatalf("journal_mode: %v", err)
	}
	if journalMode != "wal" {
		t.Errorf("journal_mode = %q, want wal", journalMode)
	}

	var foreignKeys int
	if err := st.Writer().QueryRowContext(ctx, "PRAGMA foreign_keys").Scan(&foreignKeys); err != nil {
		t.Fatalf("foreign_keys: %v", err)
	}
	if foreignKeys != 1 {
		t.Errorf("foreign_keys = %d, want 1", foreignKeys)
	}
}

func TestReaderCannotWrite(t *testing.T) {
	t.Parallel()

	st := testStore(t)

	_, err := st.Reader().Exec(`INSERT INTO tags (id, name) VALUES ('0190a1b2-0000-7000-8000-000000000002', 'dairy')`)
	if err == nil {
		t.Fatal("insert through the reader pool succeeded, want a read-only error")
	}
}

func TestOpenRejectsAFileAsDataDir(t *testing.T) {
	t.Parallel()

	path := filepath.Join(t.TempDir(), "not-a-directory")
	if err := os.WriteFile(path, []byte("x"), 0o600); err != nil {
		t.Fatalf("write file: %v", err)
	}

	if _, err := Open(path); err == nil {
		t.Fatal("Open(a file) = nil error, want the directory failure")
	}
}

func TestOptimizeAfterCloseFails(t *testing.T) {
	t.Parallel()

	st, err := Open(t.TempDir())
	if err != nil {
		t.Fatalf("Open: %v", err)
	}
	if err := st.Close(); err != nil {
		t.Fatalf("Close: %v", err)
	}

	if err := st.Optimize(context.Background()); err == nil {
		t.Fatal("Optimize on a closed store = nil error, want failure")
	}
}

func TestLoadMigrations(t *testing.T) {
	t.Parallel()

	migrations, err := loadMigrations()
	if err != nil {
		t.Fatalf("loadMigrations: %v", err)
	}
	if len(migrations) == 0 {
		t.Fatal("no embedded migrations")
	}

	for i, m := range migrations {
		if want := i + 1; m.number != want {
			t.Errorf("migration %d = %04d, want %04d: files must be numbered from 1 without gaps", i, m.number, want)
		}
		if m.body == "" {
			t.Errorf("migration %s is empty", m.name)
		}
	}
}

func TestMigrationNumber(t *testing.T) {
	t.Parallel()

	tests := map[string]struct {
		number int
		ok     bool
	}{
		"0001_init.sql":     {number: 1, ok: true},
		"0042_add_tags.sql": {number: 42, ok: true},
		"init.sql":          {ok: false},
		"0000_init.sql":     {ok: false},
		"-001_init.sql":     {ok: false},
	}

	for name, tc := range tests {
		number, err := migrationNumber(name)
		if tc.ok && err != nil {
			t.Errorf("migrationNumber(%q) = %v, want %d", name, err, tc.number)
		}
		if !tc.ok && err == nil {
			t.Errorf("migrationNumber(%q) = %d, want an error", name, number)
		}
		if tc.ok && number != tc.number {
			t.Errorf("migrationNumber(%q) = %d, want %d", name, number, tc.number)
		}
	}
}

// TestIsUniqueViolation pins the mapping the auth layer relies on: a duplicate
// email must be a field rule, not a server error.
func TestIsUniqueViolation(t *testing.T) {
	t.Parallel()

	st := testStore(t)

	_, err := st.Writer().Exec(`INSERT INTO tags (id, name) VALUES ('0190a1b2-0000-7000-8000-000000000003', 'Dairy')`)
	if err != nil {
		t.Fatalf("first insert: %v", err)
	}
	_, err = st.Writer().Exec(`INSERT INTO tags (id, name) VALUES ('0190a1b2-0000-7000-8000-000000000004', 'dairy')`)
	if err == nil {
		t.Fatal("duplicate tag insert succeeded, want a unique violation")
	}
	if !IsUniqueViolation(err) {
		t.Errorf("IsUniqueViolation(%v) = false, want true", err)
	}

	if IsUniqueViolation(sql.ErrNoRows) {
		t.Error("IsUniqueViolation(sql.ErrNoRows) = true, want false")
	}

	// A CHECK failure is a constraint error too, but not a duplicate: the
	// auth layer must answer 500, not a field rule.
	_, err = st.Writer().Exec(`INSERT INTO users
		(id, name, email, password_hash, is_admin, must_reset, created_at, updated_at)
		VALUES ('0190a1b2-0000-7000-8000-000000000005', 'Marcelo', 'm@example.com', 'x', 2, 0,
		        '2026-01-24T08:27:26Z', '2026-01-24T08:27:26Z')`)
	if err == nil {
		t.Fatal("insert with is_admin = 2 succeeded, want a check violation")
	}
	if IsUniqueViolation(err) {
		t.Errorf("IsUniqueViolation(check violation) = true, want false")
	}
}
