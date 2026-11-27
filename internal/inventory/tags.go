package inventory

import (
	"context"
	"database/sql"
	"errors"
	"fmt"

	"github.com/marcelorodrigo/freshguard/internal/ids"
)

// Tags returns the 50 most used tags, then by name, with their item counts.
func (s *Service) Tags(ctx context.Context) ([]Tag, error) {
	rows, err := s.db.Reader().QueryContext(ctx, `SELECT
			t.id, t.name, COUNT(it.item_id) AS item_count
		FROM tags t
		LEFT JOIN item_tags it ON it.tag_id = t.id
		GROUP BY t.id
		ORDER BY item_count DESC, t.name
		LIMIT ?`, TagSuggestionLimit)
	if err != nil {
		return nil, fmt.Errorf("list tags: %w", err)
	}
	defer func() { _ = rows.Close() }()

	tags := make([]Tag, 0)
	for rows.Next() {
		var tag Tag
		if err := rows.Scan(&tag.ID, &tag.Name, &tag.ItemCount); err != nil {
			return nil, fmt.Errorf("scan tag: %w", err)
		}
		tags = append(tags, tag)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("read tags: %w", err)
	}

	return tags, nil
}

// replaceTags makes names the complete tag set of one item. Existing tag rows
// are reused, so the first spelling of a tag stays canonical.
func replaceTags(ctx context.Context, tx *sql.Tx, itemID string, names []string) error {
	names = normalizeTags(names)

	if _, err := tx.ExecContext(ctx, "DELETE FROM item_tags WHERE item_id = ?", itemID); err != nil {
		return fmt.Errorf("clear item tags: %w", err)
	}

	for _, name := range names {
		var tagID string
		err := tx.QueryRowContext(ctx, "SELECT id FROM tags WHERE name = ?", name).Scan(&tagID)
		if errors.Is(err, sql.ErrNoRows) {
			tagID = ids.New()
			if _, err := tx.ExecContext(ctx,
				"INSERT INTO tags (id, name) VALUES (?, ?)", tagID, name); err != nil {
				return fmt.Errorf("insert tag %q: %w", name, err)
			}
		} else if err != nil {
			return fmt.Errorf("read tag %q: %w", name, err)
		}

		if _, err := tx.ExecContext(ctx,
			"INSERT INTO item_tags (item_id, tag_id) VALUES (?, ?)", itemID, tagID); err != nil {
			return fmt.Errorf("insert item tag %q: %w", name, err)
		}
	}

	return nil
}

// sweepUnusedTags deletes tags that no item carries any more.
func sweepUnusedTags(ctx context.Context, tx *sql.Tx) error {
	if _, err := tx.ExecContext(ctx, `DELETE FROM tags
		WHERE NOT EXISTS (SELECT 1 FROM item_tags WHERE item_tags.tag_id = tags.id)`); err != nil {
		return fmt.Errorf("sweep unused tags: %w", err)
	}
	return nil
}
