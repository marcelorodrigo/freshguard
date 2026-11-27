package inventory

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
)

// ConsumeResult reports what one consume left behind. BatchQuantity is zero
// and Deleted is true when the last unit was taken and the batch row is gone.
type ConsumeResult struct {
	ItemID        string
	ItemName      string
	BatchID       string
	BatchQuantity int
	Deleted       bool
}

// ConsumeBatch removes one unit from a batch, deleting the row when the last
// unit goes. The item's quantity is computed from its batches, so there is no
// aggregate to rewrite; only updated_at moves.
//
// A batch that is already gone answers ErrBatchUnavailable: the screen shows
// the race toast instead of a 500.
func (s *Service) ConsumeBatch(ctx context.Context, batchID string) (ConsumeResult, error) {
	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return ConsumeResult{}, fmt.Errorf("begin consume batch: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	var (
		itemID   string
		itemName string
		quantity int
	)
	err = tx.QueryRowContext(ctx, `SELECT b.item_id, i.name, b.quantity
		FROM batches b
		JOIN items i ON i.id = b.item_id
		WHERE b.id = ?`, batchID).Scan(&itemID, &itemName, &quantity)
	if errors.Is(err, sql.ErrNoRows) {
		return ConsumeResult{}, ErrBatchUnavailable
	}
	if err != nil {
		return ConsumeResult{}, fmt.Errorf("read batch %s to consume: %w", batchID, err)
	}

	if quantity <= 0 {
		return ConsumeResult{}, ErrBatchUnavailable
	}

	result := ConsumeResult{ItemID: itemID, ItemName: itemName, BatchID: batchID}
	now := s.now().UTC()

	if quantity == 1 {
		if _, err := tx.ExecContext(ctx, "DELETE FROM batches WHERE id = ?", batchID); err != nil {
			return ConsumeResult{}, fmt.Errorf("delete consumed batch %s: %w", batchID, err)
		}
		result.Deleted = true
	} else {
		if _, err := tx.ExecContext(ctx, `UPDATE batches
			SET quantity = quantity - 1, updated_at = ?
			WHERE id = ? AND quantity > 1`,
			formatTime(now), batchID); err != nil {
			return ConsumeResult{}, fmt.Errorf("decrement batch %s: %w", batchID, err)
		}
		result.BatchQuantity = quantity - 1
	}

	if err := touchItem(ctx, tx, itemID, now); err != nil {
		return ConsumeResult{}, err
	}

	if err := tx.Commit(); err != nil {
		return ConsumeResult{}, fmt.Errorf("commit consume batch: %w", err)
	}

	return result, nil
}
