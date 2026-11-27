package inventory

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/marcelorodrigo/freshguard/internal/ids"
)

// CreateBatch adds a batch to an item. The item's quantity and earliest
// expiration are computed from its batches, so there is nothing to recompute
// here: writing the row is the whole update.
func (s *Service) CreateBatch(ctx context.Context, itemID string, in BatchInput) (Batch, error) {
	in.LocationID = strings.TrimSpace(in.LocationID)
	in.ExpiresAt = strings.TrimSpace(in.ExpiresAt)

	if err := validateBatch(in.LocationID, in.ExpiresAt, in.Quantity); err != nil {
		return Batch{}, err
	}

	now := s.now().UTC()
	batchID := ids.New()

	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return Batch{}, fmt.Errorf("begin create batch: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	found, err := rowExists(ctx, tx, "items", itemID)
	if err != nil {
		return Batch{}, fmt.Errorf("read item %s: %w", itemID, err)
	}
	if !found {
		return Batch{}, ErrItemNotFound
	}

	found, err = rowExists(ctx, tx, "locations", in.LocationID)
	if err != nil {
		return Batch{}, fmt.Errorf("read location %s: %w", in.LocationID, err)
	}
	if !found {
		return Batch{}, fieldError("location_id", "exists")
	}

	if _, err := tx.ExecContext(ctx, `INSERT INTO batches
		(id, item_id, location_id, expires_at, quantity, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?)`,
		batchID, itemID, in.LocationID, in.ExpiresAt, in.Quantity,
		formatTime(now), formatTime(now)); err != nil {
		return Batch{}, fmt.Errorf("insert batch: %w", err)
	}

	if err := touchItem(ctx, tx, itemID, now); err != nil {
		return Batch{}, err
	}

	if err := tx.Commit(); err != nil {
		return Batch{}, fmt.Errorf("commit create batch: %w", err)
	}

	return s.batch(ctx, batchID)
}

// UpdateBatch changes the submitted fields of one batch.
func (s *Service) UpdateBatch(ctx context.Context, batchID string, patch BatchPatch) (Batch, error) {
	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return Batch{}, fmt.Errorf("begin update batch: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	var (
		itemID     string
		locationID string
		expiresAt  string
		quantity   int
	)
	if err := tx.QueryRowContext(ctx, `SELECT item_id, location_id, expires_at, quantity
		FROM batches WHERE id = ?`, batchID).Scan(&itemID, &locationID, &expiresAt, &quantity); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return Batch{}, ErrBatchNotFound
		}
		return Batch{}, fmt.Errorf("read batch %s for update: %w", batchID, err)
	}

	if patch.LocationID != nil {
		locationID = strings.TrimSpace(*patch.LocationID)
	}
	if patch.ExpiresAt != nil {
		expiresAt = strings.TrimSpace(*patch.ExpiresAt)
	}
	if patch.Quantity != nil {
		quantity = *patch.Quantity
	}

	if err := validateBatch(locationID, expiresAt, quantity); err != nil {
		return Batch{}, err
	}

	found, err := rowExists(ctx, tx, "locations", locationID)
	if err != nil {
		return Batch{}, fmt.Errorf("read location %s: %w", locationID, err)
	}
	if !found {
		return Batch{}, fieldError("location_id", "exists")
	}

	now := s.now().UTC()
	if _, err := tx.ExecContext(ctx, `UPDATE batches
		SET location_id = ?, expires_at = ?, quantity = ?, updated_at = ?
		WHERE id = ?`,
		locationID, expiresAt, quantity, formatTime(now), batchID); err != nil {
		return Batch{}, fmt.Errorf("update batch %s: %w", batchID, err)
	}

	if err := touchItem(ctx, tx, itemID, now); err != nil {
		return Batch{}, err
	}

	if err := tx.Commit(); err != nil {
		return Batch{}, fmt.Errorf("commit update batch: %w", err)
	}

	return s.batch(ctx, batchID)
}

// DeleteBatch removes one batch. Consuming the last unit of stock and deleting
// a batch are the same write, so there is no zero-quantity row to clean up.
func (s *Service) DeleteBatch(ctx context.Context, batchID string) error {
	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin delete batch: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	var itemID string
	if err := tx.QueryRowContext(ctx,
		"SELECT item_id FROM batches WHERE id = ?", batchID).Scan(&itemID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return ErrBatchNotFound
		}
		return fmt.Errorf("read batch %s for delete: %w", batchID, err)
	}

	if _, err := tx.ExecContext(ctx, "DELETE FROM batches WHERE id = ?", batchID); err != nil {
		return fmt.Errorf("delete batch %s: %w", batchID, err)
	}

	if err := touchItem(ctx, tx, itemID, s.now().UTC()); err != nil {
		return err
	}

	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit delete batch: %w", err)
	}
	return nil
}

// batch reads one batch with its location name.
func (s *Service) batch(ctx context.Context, batchID string) (Batch, error) {
	batch, err := scanBatch(s.db.Reader().QueryRowContext(ctx, `SELECT
			b.id, b.item_id, l.id, l.name, b.expires_at, b.quantity,
			b.created_at, b.updated_at
		FROM batches b
		JOIN locations l ON l.id = b.location_id
		WHERE b.id = ?`, batchID))
	if errors.Is(err, sql.ErrNoRows) {
		return Batch{}, ErrBatchNotFound
	}
	if err != nil {
		return Batch{}, fmt.Errorf("read batch %s: %w", batchID, err)
	}
	return batch, nil
}

func validateBatch(locationID, expiresAt string, quantity int) error {
	switch {
	case locationID == "":
		return fieldError("location_id", "required")
	case expiresAt == "":
		return fieldError("expires_at", "required")
	}

	if _, err := time.Parse(dateLayout, expiresAt); err != nil {
		return fieldError("expires_at", "date")
	}

	if quantity < 1 {
		return fieldError("quantity", "min")
	}
	return nil
}

// rowExists reports whether a row with this id exists. The table name is a
// call-site constant, never user input.
func rowExists(ctx context.Context, tx *sql.Tx, table, id string) (bool, error) {
	var exists int
	err := tx.QueryRowContext(ctx,
		"SELECT EXISTS(SELECT 1 FROM "+table+" WHERE id = ?)", id).Scan(&exists)
	if err != nil {
		return false, err
	}
	return exists == 1, nil
}

// touchItem bumps the item's updated_at, matching the old observer that
// rewrote the item whenever a batch changed.
func touchItem(ctx context.Context, tx *sql.Tx, itemID string, now time.Time) error {
	if _, err := tx.ExecContext(ctx,
		"UPDATE items SET updated_at = ? WHERE id = ?", formatTime(now), itemID); err != nil {
		return fmt.Errorf("touch item %s: %w", itemID, err)
	}
	return nil
}
