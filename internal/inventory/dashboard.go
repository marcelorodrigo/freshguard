package inventory

import (
	"context"
	"fmt"
)

// recentlyExpiredLimit is the number of rows the dashboard shows, fixed by
// docs/api.md.
const recentlyExpiredLimit = 5

// ExpiredBatch is a batch that expired before today, with its item and
// location names for the dashboard table.
type ExpiredBatch struct {
	Batch
	ItemName string
}

// RecentlyExpired returns up to five batches whose expiry is before today,
// oldest first. A batch expiring today is not expired yet.
func (s *Service) RecentlyExpired(ctx context.Context) ([]ExpiredBatch, error) {
	rows, err := s.db.Reader().QueryContext(ctx, `SELECT
			b.id, b.item_id, l.id, l.name, b.expires_at, b.quantity,
			b.created_at, b.updated_at, i.name
		FROM batches b
		JOIN items i ON i.id = b.item_id
		JOIN locations l ON l.id = b.location_id
		WHERE b.expires_at < ?
		ORDER BY b.expires_at ASC, b.id ASC
		LIMIT ?`, s.now().Format(dateLayout), recentlyExpiredLimit)
	if err != nil {
		return nil, fmt.Errorf("list recently expired batches: %w", err)
	}
	defer func() { _ = rows.Close() }()

	batches := make([]ExpiredBatch, 0, recentlyExpiredLimit)
	for rows.Next() {
		var itemName string
		batch, err := scanBatch(rows, &itemName)
		if err != nil {
			return nil, fmt.Errorf("scan recently expired batch: %w", err)
		}
		batches = append(batches, ExpiredBatch{Batch: batch, ItemName: itemName})
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("read recently expired batches: %w", err)
	}

	return batches, nil
}
