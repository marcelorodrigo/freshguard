package inventory

import (
	"context"
	"database/sql"
	"fmt"
	"strings"
	"unicode/utf8"
)

const (
	// SearchLimit is the fixed number of Quick Consume results.
	SearchLimit = 10
	// minSearchTerm is the shortest trimmed term the search runs for.
	minSearchTerm = 2
)

// The five tiers of docs/schema.md, in rank order. The frame keeps only items
// with stock, excludes the ids collected by the earlier tiers (bound as a JSON
// array, so the statement itself never changes) and takes the remaining
// budget. Terms are escaped before they reach LIKE.
const (
	searchTierPrefix = `SELECT i.id FROM items i
WHERE EXISTS (SELECT 1 FROM batches s WHERE s.item_id = i.id AND s.quantity > 0)
  AND `

	searchTierSuffix = `
  AND i.id NOT IN (SELECT value FROM json_each(?))
ORDER BY i.name, i.id
LIMIT ?`

	searchExactBarcodeSQL = searchTierPrefix + `i.barcode = ?` + searchTierSuffix

	searchBarcodePrefixSQL = searchTierPrefix + `i.barcode LIKE ? ESCAPE '\'` + searchTierSuffix

	searchNamePrefixSQL = searchTierPrefix + `i.name LIKE ? ESCAPE '\'` + searchTierSuffix

	searchDescriptionPrefixSQL = searchTierPrefix +
		`i.description LIKE ? ESCAPE '\'` + searchTierSuffix

	searchSubstringSQL = searchTierPrefix +
		`(i.name LIKE ? ESCAPE '\' OR i.description LIKE ? ESCAPE '\')` + searchTierSuffix

	searchItemsSQL = `SELECT i.id, i.name, COALESCE(i.description, '')
FROM items i
WHERE i.id IN (SELECT value FROM json_each(?))`

	searchBatchesSQL = `SELECT b.id, b.item_id, l.id, l.name, b.expires_at, b.quantity,
	b.created_at, b.updated_at
FROM batches b
JOIN locations l ON l.id = b.location_id
WHERE b.quantity > 0
  AND b.item_id IN (SELECT value FROM json_each(?))
ORDER BY b.expires_at, b.id`
)

// SearchResult is one item in the Quick Consume list: the fields the screen
// renders, with the batches that still have stock.
type SearchResult struct {
	ID          string
	Name        string
	Description string
	Batches     []Batch
}

// searchTier is one ranked query and the LIKE arguments it binds.
type searchTier struct {
	query string
	args  []any
}

// Search returns the items with stock that match the term, ranked by the five
// tiers in docs/schema.md. A term shorter than two characters returns nothing.
func (s *Service) Search(ctx context.Context, term string) ([]SearchResult, error) {
	term = strings.TrimSpace(term)
	if utf8.RuneCountInString(term) < minSearchTerm {
		return []SearchResult{}, nil
	}

	tx, err := s.db.Reader().BeginTx(ctx, &sql.TxOptions{ReadOnly: true})
	if err != nil {
		return nil, fmt.Errorf("begin search: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	ids, err := searchIDs(ctx, tx, term)
	if err != nil {
		return nil, err
	}

	results, err := searchResults(ctx, tx, ids)
	if err != nil {
		return nil, err
	}

	if err := tx.Commit(); err != nil {
		return nil, fmt.Errorf("commit search: %w", err)
	}

	return results, nil
}

// searchIDs collects at most SearchLimit ids, one tier at a time. It stops as
// soon as the prefix tiers fill the limit, so the substring scan is a fallback.
func searchIDs(ctx context.Context, q queryer, term string) ([]string, error) {
	ids := make([]string, 0, SearchLimit)

	for _, tier := range rankedTiers(term) {
		remaining := SearchLimit - len(ids)
		if remaining <= 0 {
			break
		}

		excluded, err := jsonIDs(ids)
		if err != nil {
			return nil, err
		}

		args := append(append([]any{}, tier.args...), excluded, remaining)
		found, err := queryIDs(ctx, q, tier.query, args...)
		if err != nil {
			return nil, err
		}
		ids = append(ids, found...)
	}

	return ids, nil
}

func rankedTiers(term string) []searchTier {
	escaped := escapeLike(term)
	prefix := escaped + "%"
	substring := "%" + escaped + "%"

	return []searchTier{
		{query: searchExactBarcodeSQL, args: []any{term}},
		{query: searchBarcodePrefixSQL, args: []any{prefix}},
		{query: searchNamePrefixSQL, args: []any{prefix}},
		{query: searchDescriptionPrefixSQL, args: []any{prefix}},
		{query: searchSubstringSQL, args: []any{substring, substring}},
	}
}

// searchResults hydrates the ids on one read snapshot and returns them in the
// order the tiers collected them.
func searchResults(ctx context.Context, q queryer, ids []string) ([]SearchResult, error) {
	results := make([]SearchResult, 0, len(ids))
	if len(ids) == 0 {
		return results, nil
	}

	payload, err := jsonIDs(ids)
	if err != nil {
		return nil, err
	}

	byID, err := searchItemsByID(ctx, q, payload)
	if err != nil {
		return nil, err
	}
	if err := attachSearchBatches(ctx, q, payload, byID); err != nil {
		return nil, err
	}

	for _, id := range ids {
		if result, ok := byID[id]; ok {
			results = append(results, *result)
		}
	}

	return results, nil
}

func searchItemsByID(ctx context.Context, q queryer, payload string) (map[string]*SearchResult, error) {
	byID := make(map[string]*SearchResult)

	rows, err := q.QueryContext(ctx, searchItemsSQL, payload)
	if err != nil {
		return nil, fmt.Errorf("read search items: %w", err)
	}
	defer func() { _ = rows.Close() }()

	for rows.Next() {
		var result SearchResult
		if err := rows.Scan(&result.ID, &result.Name, &result.Description); err != nil {
			return nil, fmt.Errorf("scan search item: %w", err)
		}
		result.Batches = []Batch{}
		byID[result.ID] = &result
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("read search items: %w", err)
	}

	return byID, nil
}

func attachSearchBatches(
	ctx context.Context,
	q queryer,
	payload string,
	byID map[string]*SearchResult,
) error {
	rows, err := q.QueryContext(ctx, searchBatchesSQL, payload)
	if err != nil {
		return fmt.Errorf("read search batches: %w", err)
	}
	defer func() { _ = rows.Close() }()

	for rows.Next() {
		batch, err := scanBatch(rows)
		if err != nil {
			return fmt.Errorf("scan search batch: %w", err)
		}
		if result, ok := byID[batch.ItemID]; ok {
			result.Batches = append(result.Batches, batch)
		}
	}
	if err := rows.Err(); err != nil {
		return fmt.Errorf("read search batches: %w", err)
	}

	return nil
}

func queryIDs(ctx context.Context, q queryer, query string, args ...any) ([]string, error) {
	rows, err := q.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, fmt.Errorf("read search tier: %w", err)
	}
	defer func() { _ = rows.Close() }()

	ids := make([]string, 0, SearchLimit)
	for rows.Next() {
		var id string
		if err := rows.Scan(&id); err != nil {
			return nil, fmt.Errorf("scan search tier: %w", err)
		}
		ids = append(ids, id)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("read search tier: %w", err)
	}

	return ids, nil
}
