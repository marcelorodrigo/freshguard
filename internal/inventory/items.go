package inventory

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"

	"github.com/marcelorodrigo/freshguard/internal/ids"
)

const (
	itemTieBreak = ", i.name ASC, i.id ASC"

	itemSelect = `SELECT
	i.id, i.name, COALESCE(i.barcode, ''), COALESCE(i.description, ''),
	COALESCE(SUM(b.quantity), 0) AS quantity,
	MIN(b.expires_at)           AS earliest_expiration,
	COUNT(b.id)                 AS batch_count,
	i.created_at, i.updated_at
FROM items i
LEFT JOIN batches b ON b.item_id = i.id`
)

// ListItems returns one page with computed quantity and earliest expiration.
func (s *Service) ListItems(ctx context.Context, params ListParams) (Page, error) {
	params = params.normalized()
	where, filterArgs := itemFilter(params.Q)

	var total int
	if err := s.db.Reader().QueryRowContext(ctx,
		"SELECT COUNT(*) FROM items i "+where, filterArgs...).Scan(&total); err != nil {
		return Page{}, fmt.Errorf("count items: %w", err)
	}

	page := Page{
		Items:   []Item{},
		Page:    params.Page,
		PerPage: params.PerPage,
		Total:   total,
	}
	if total > 0 {
		page.Pages = (total + params.PerPage - 1) / params.PerPage
	}

	offset := (params.Page - 1) * params.PerPage
	query := itemSelect + " " + where +
		" GROUP BY i.id ORDER BY " + itemOrder(params.Sort, params.Dir) +
		" LIMIT ? OFFSET ?"

	args := append(append([]any{}, filterArgs...), params.PerPage, offset)
	rows, err := s.db.Reader().QueryContext(ctx, query, args...)
	if err != nil {
		return Page{}, fmt.Errorf("list items: %w", err)
	}
	defer func() { _ = rows.Close() }()

	for rows.Next() {
		item, err := scanItem(rows)
		if err != nil {
			return Page{}, fmt.Errorf("scan item: %w", err)
		}
		page.Items = append(page.Items, item)
	}
	if err := rows.Err(); err != nil {
		return Page{}, fmt.Errorf("read items: %w", err)
	}

	if len(page.Items) == 0 {
		return page, nil
	}

	itemIDs := make([]string, len(page.Items))
	for i := range page.Items {
		itemIDs[i] = page.Items[i].ID
	}

	tags, err := s.tagsForItems(ctx, itemIDs)
	if err != nil {
		return Page{}, err
	}
	for i := range page.Items {
		page.Items[i].Tags = tags[page.Items[i].ID]
		if page.Items[i].Tags == nil {
			page.Items[i].Tags = []string{}
		}
	}

	return page, nil
}

// Get returns one item with its tags and batches.
func (s *Service) Get(ctx context.Context, itemID string) (Item, error) {
	item, err := scanItem(s.db.Reader().QueryRowContext(ctx,
		itemSelect+" WHERE i.id = ? GROUP BY i.id", itemID))
	if errors.Is(err, sql.ErrNoRows) {
		return Item{}, ErrItemNotFound
	}
	if err != nil {
		return Item{}, fmt.Errorf("read item %s: %w", itemID, err)
	}

	tags, err := s.tagsForItems(ctx, []string{itemID})
	if err != nil {
		return Item{}, err
	}
	item.Tags = tags[itemID]
	if item.Tags == nil {
		item.Tags = []string{}
	}

	batches, err := s.batchesForItem(ctx, itemID)
	if err != nil {
		return Item{}, err
	}
	item.Batches = batches

	return item, nil
}

// CreateItem inserts an item and its tags in one transaction.
func (s *Service) CreateItem(ctx context.Context, in ItemInput) (Item, error) {
	in.Name = strings.TrimSpace(in.Name)
	in.Barcode = strings.TrimSpace(in.Barcode)
	in.Description = strings.TrimSpace(in.Description)

	if err := validateItem(in.Name, in.Barcode, in.Description); err != nil {
		return Item{}, err
	}

	tags := normalizeTags(in.Tags)
	now := s.now().UTC()
	itemID := ids.New()

	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return Item{}, fmt.Errorf("begin create item: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	if _, err := tx.ExecContext(ctx, `INSERT INTO items
		(id, name, barcode, description, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?)`,
		itemID, in.Name, nullable(in.Barcode), nullable(in.Description),
		formatTime(now), formatTime(now)); err != nil {
		return Item{}, fmt.Errorf("insert item: %w", err)
	}

	if err := replaceTags(ctx, tx, itemID, tags); err != nil {
		return Item{}, err
	}
	if err := sweepUnusedTags(ctx, tx); err != nil {
		return Item{}, err
	}

	if err := tx.Commit(); err != nil {
		return Item{}, fmt.Errorf("commit create item: %w", err)
	}

	return s.Get(ctx, itemID)
}

// UpdateItem changes the submitted fields, replaces tags when present and
// sweeps tags that no longer belong to an item.
func (s *Service) UpdateItem(ctx context.Context, itemID string, patch ItemPatch) (Item, error) {
	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return Item{}, fmt.Errorf("begin update item: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	var (
		name        string
		barcode     string
		description string
	)
	if err := tx.QueryRowContext(ctx, `SELECT
			name, COALESCE(barcode, ''), COALESCE(description, '')
		FROM items WHERE id = ?`, itemID).Scan(&name, &barcode, &description); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return Item{}, ErrItemNotFound
		}
		return Item{}, fmt.Errorf("read item %s for update: %w", itemID, err)
	}

	if patch.Name != nil {
		name = strings.TrimSpace(*patch.Name)
	}
	if patch.Barcode != nil {
		barcode = strings.TrimSpace(*patch.Barcode)
	}
	if patch.Description != nil {
		description = strings.TrimSpace(*patch.Description)
	}

	if err := validateItem(name, barcode, description); err != nil {
		return Item{}, err
	}

	now := s.now().UTC()
	if _, err := tx.ExecContext(ctx, `UPDATE items
		SET name = ?, barcode = ?, description = ?, updated_at = ?
		WHERE id = ?`,
		name, nullable(barcode), nullable(description), formatTime(now), itemID); err != nil {
		return Item{}, fmt.Errorf("update item %s: %w", itemID, err)
	}

	if patch.Tags != nil {
		if err := replaceTags(ctx, tx, itemID, normalizeTags(*patch.Tags)); err != nil {
			return Item{}, err
		}
		if err := sweepUnusedTags(ctx, tx); err != nil {
			return Item{}, err
		}
	}

	if err := tx.Commit(); err != nil {
		return Item{}, fmt.Errorf("commit update item: %w", err)
	}

	return s.Get(ctx, itemID)
}

// DeleteItem removes one item; batches and item_tags cascade in the schema.
func (s *Service) DeleteItem(ctx context.Context, itemID string) error {
	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin delete item: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	result, err := tx.ExecContext(ctx, "DELETE FROM items WHERE id = ?", itemID)
	if err != nil {
		return fmt.Errorf("delete item %s: %w", itemID, err)
	}
	deleted, err := result.RowsAffected()
	if err != nil {
		return fmt.Errorf("count deleted items: %w", err)
	}
	if deleted == 0 {
		return ErrItemNotFound
	}

	if err := sweepUnusedTags(ctx, tx); err != nil {
		return err
	}
	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit delete item: %w", err)
	}
	return nil
}

// BulkDelete removes every listed item in one transaction. Missing ids are
// ignored, so the response reports how many rows actually existed.
func (s *Service) BulkDelete(ctx context.Context, itemIDs []string) (int, error) {
	unique := make([]string, 0, len(itemIDs))
	seen := make(map[string]struct{}, len(itemIDs))
	for _, itemID := range itemIDs {
		itemID = strings.TrimSpace(itemID)
		if itemID == "" {
			continue
		}
		if _, exists := seen[itemID]; exists {
			continue
		}
		seen[itemID] = struct{}{}
		unique = append(unique, itemID)
	}
	if len(unique) == 0 {
		return 0, nil
	}

	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return 0, fmt.Errorf("begin bulk delete items: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	args := make([]any, len(unique))
	for i, itemID := range unique {
		args[i] = itemID
	}

	result, err := tx.ExecContext(ctx,
		"DELETE FROM items WHERE id IN ("+placeholders(len(unique))+")", args...)
	if err != nil {
		return 0, fmt.Errorf("bulk delete items: %w", err)
	}
	deleted, err := result.RowsAffected()
	if err != nil {
		return 0, fmt.Errorf("count bulk deleted items: %w", err)
	}

	if err := sweepUnusedTags(ctx, tx); err != nil {
		return 0, err
	}
	if err := tx.Commit(); err != nil {
		return 0, fmt.Errorf("commit bulk delete items: %w", err)
	}

	return int(deleted), nil
}

func (s *Service) tagsForItems(ctx context.Context, itemIDs []string) (map[string][]string, error) {
	tags := make(map[string][]string, len(itemIDs))
	if len(itemIDs) == 0 {
		return tags, nil
	}

	args := make([]any, len(itemIDs))
	for i, itemID := range itemIDs {
		args[i] = itemID
	}

	rows, err := s.db.Reader().QueryContext(ctx, `SELECT it.item_id, t.name
		FROM item_tags it
		JOIN tags t ON t.id = it.tag_id
		WHERE it.item_id IN (`+placeholders(len(itemIDs))+`)
		ORDER BY t.name`, args...)
	if err != nil {
		return nil, fmt.Errorf("read item tags: %w", err)
	}
	defer func() { _ = rows.Close() }()

	for rows.Next() {
		var (
			itemID string
			name   string
		)
		if err := rows.Scan(&itemID, &name); err != nil {
			return nil, fmt.Errorf("scan item tag: %w", err)
		}
		tags[itemID] = append(tags[itemID], name)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("read item tags: %w", err)
	}

	return tags, nil
}

func (s *Service) batchesForItem(ctx context.Context, itemID string) ([]Batch, error) {
	rows, err := s.db.Reader().QueryContext(ctx, `SELECT
			b.id, b.item_id, l.id, l.name, b.expires_at, b.quantity,
			b.created_at, b.updated_at
		FROM batches b
		JOIN locations l ON l.id = b.location_id
		WHERE b.item_id = ?
		ORDER BY b.expires_at, b.id`, itemID)
	if err != nil {
		return nil, fmt.Errorf("read item batches: %w", err)
	}
	defer func() { _ = rows.Close() }()

	batches := make([]Batch, 0)
	for rows.Next() {
		var (
			batch     Batch
			createdAt string
			updatedAt string
		)
		if err := rows.Scan(&batch.ID, &batch.ItemID, &batch.LocationID,
			&batch.LocationName, &batch.ExpiresAt, &batch.Quantity,
			&createdAt, &updatedAt); err != nil {
			return nil, fmt.Errorf("scan item batch: %w", err)
		}

		created, err := parseTime(createdAt)
		if err != nil {
			return nil, err
		}
		updated, err := parseTime(updatedAt)
		if err != nil {
			return nil, err
		}
		batch.CreatedAt, batch.UpdatedAt = created, updated
		batches = append(batches, batch)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("read item batches: %w", err)
	}

	return batches, nil
}

func scanItem(row rowScanner) (Item, error) {
	var (
		item      Item
		earliest  sql.NullString
		createdAt string
		updatedAt string
	)

	if err := row.Scan(&item.ID, &item.Name, &item.Barcode, &item.Description,
		&item.Quantity, &earliest, &item.BatchCount, &createdAt, &updatedAt); err != nil {
		return Item{}, err
	}
	item.EarliestExpiration = earliest.String

	created, err := parseTime(createdAt)
	if err != nil {
		return Item{}, err
	}
	updated, err := parseTime(updatedAt)
	if err != nil {
		return Item{}, err
	}
	item.CreatedAt, item.UpdatedAt = created, updated
	item.Tags = []string{}

	return item, nil
}

func itemFilter(term string) (string, []any) {
	term = strings.TrimSpace(term)
	if term == "" {
		return "", nil
	}

	pattern := "%" + escapeLike(strings.ToLower(term)) + "%"
	where := `WHERE (
		LOWER(i.name) LIKE ? ESCAPE '\'
		OR LOWER(COALESCE(i.description, '')) LIKE ? ESCAPE '\'
		OR LOWER(COALESCE(i.barcode, '')) LIKE ? ESCAPE '\'
		OR EXISTS (
			SELECT 1 FROM item_tags it
			JOIN tags t ON t.id = it.tag_id
			WHERE it.item_id = i.id AND LOWER(t.name) LIKE ? ESCAPE '\'
		)
	)`

	return where, []any{pattern, pattern, pattern, pattern}
}

func itemOrder(sort, dir string) string {
	switch sort {
	case "quantity":
		return "quantity " + dir + itemTieBreak
	case "earliest_expiration":
		return "earliest_expiration IS NULL ASC, earliest_expiration " + dir + itemTieBreak
	case "created_at":
		return "i.created_at " + dir + itemTieBreak
	case "updated_at":
		return "i.updated_at " + dir + itemTieBreak
	default:
		return "i.name " + dir + ", i.id " + dir
	}
}

func escapeLike(value string) string {
	replacer := strings.NewReplacer(
		`\`, `\\`,
		`%`, `\%`,
		`_`, `\_`,
	)
	return replacer.Replace(value)
}

func placeholders(count int) string {
	if count <= 0 {
		return ""
	}
	return strings.TrimSuffix(strings.Repeat("?,", count), ",")
}

func nullable(value string) any {
	if value == "" {
		return nil
	}
	return value
}
