package inventory

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"slices"
	"strings"
	"unicode/utf8"

	"github.com/marcelorodrigo/freshguard/internal/ids"
)

// locationSelect reads a location with its parent's name and batch count. The
// list adds a WHERE and GROUP BY; the single read adds an id predicate.
const locationSelect = `SELECT
	l.id, l.name, COALESCE(l.description, ''), l.expiration_notify_days,
	COALESCE(l.parent_id, ''), COALESCE(p.name, ''),
	COUNT(b.id) AS batch_count,
	l.created_at, l.updated_at
FROM locations l
LEFT JOIN locations p ON p.id = l.parent_id
LEFT JOIN batches b ON b.location_id = l.id`

// ListLocations returns one page of locations with their parent name and the
// number of batches each holds.
func (s *Service) ListLocations(ctx context.Context, params ListParams) (LocationPage, error) {
	params = params.normalized()
	where, filterArgs := locationFilter(params.Q)

	var total int
	if err := s.db.Reader().QueryRowContext(ctx,
		"SELECT COUNT(*) FROM locations l LEFT JOIN locations p ON p.id = l.parent_id "+where,
		filterArgs...).Scan(&total); err != nil {
		return LocationPage{}, fmt.Errorf("count locations: %w", err)
	}

	page := LocationPage{
		Locations: []Location{},
		Page:      params.Page,
		PerPage:   params.PerPage,
		Total:     total,
	}
	if total > 0 {
		page.Pages = (total + params.PerPage - 1) / params.PerPage
	}

	offset := (params.Page - 1) * params.PerPage
	query := locationSelect + "\n" + where + " GROUP BY l.id ORDER BY " +
		locationOrder(params.Sort, params.Dir) + " LIMIT ? OFFSET ?"

	args := append(append([]any{}, filterArgs...), params.PerPage, offset)
	rows, err := s.db.Reader().QueryContext(ctx, query, args...)
	if err != nil {
		return LocationPage{}, fmt.Errorf("list locations: %w", err)
	}
	defer func() { _ = rows.Close() }()

	for rows.Next() {
		location, err := scanLocation(rows)
		if err != nil {
			return LocationPage{}, fmt.Errorf("scan location: %w", err)
		}
		page.Locations = append(page.Locations, location)
	}
	if err := rows.Err(); err != nil {
		return LocationPage{}, fmt.Errorf("read locations: %w", err)
	}

	return page, nil
}

// CreateLocation inserts one location and links its parent, one transaction.
func (s *Service) CreateLocation(ctx context.Context, in LocationInput) (Location, error) {
	in.Name = strings.TrimSpace(in.Name)
	in.Description = strings.TrimSpace(in.Description)
	in.ParentID = strings.TrimSpace(in.ParentID)

	if err := validateLocation(in.Name, in.Description, in.ExpirationNotifyDays); err != nil {
		return Location{}, err
	}

	now := s.now().UTC()
	locationID := ids.New()

	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return Location{}, fmt.Errorf("begin create location: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	if err := checkLocationParent(ctx, tx, locationID, in.ParentID); err != nil {
		return Location{}, err
	}

	if _, err := tx.ExecContext(ctx, `INSERT INTO locations
		(id, name, description, expiration_notify_days, parent_id, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?)`,
		locationID, in.Name, nullable(in.Description), in.ExpirationNotifyDays,
		nullable(in.ParentID), formatTime(now), formatTime(now)); err != nil {
		return Location{}, fmt.Errorf("insert location: %w", err)
	}

	if err := tx.Commit(); err != nil {
		return Location{}, fmt.Errorf("commit create location: %w", err)
	}

	return s.location(ctx, locationID)
}

// UpdateLocation changes the submitted fields. A nil patch field keeps the
// stored value; an empty parent_id makes the location a root location.
func (s *Service) UpdateLocation(ctx context.Context, locationID string, patch LocationPatch) (Location, error) {
	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return Location{}, fmt.Errorf("begin update location: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	var (
		name        string
		description string
		days        int
		parent      sql.NullString
	)
	if err := tx.QueryRowContext(ctx, `SELECT
			name, COALESCE(description, ''), expiration_notify_days, parent_id
		FROM locations WHERE id = ?`, locationID).Scan(&name, &description, &days, &parent); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return Location{}, ErrLocationNotFound
		}
		return Location{}, fmt.Errorf("read location %s for update: %w", locationID, err)
	}

	parentID := parent.String
	if patch.Name != nil {
		name = strings.TrimSpace(*patch.Name)
	}
	if patch.Description != nil {
		description = strings.TrimSpace(*patch.Description)
	}
	if patch.ExpirationNotifyDays != nil {
		days = *patch.ExpirationNotifyDays
	}
	if patch.ParentID != nil {
		parentID = strings.TrimSpace(*patch.ParentID)
	}

	if err := validateLocation(name, description, days); err != nil {
		return Location{}, err
	}
	if err := checkLocationParent(ctx, tx, locationID, parentID); err != nil {
		return Location{}, err
	}

	if _, err := tx.ExecContext(ctx, `UPDATE locations
		SET name = ?, description = ?, expiration_notify_days = ?, parent_id = ?, updated_at = ?
		WHERE id = ?`,
		name, nullable(description), days, nullable(parentID),
		formatTime(s.now().UTC()), locationID); err != nil {
		return Location{}, fmt.Errorf("update location %s: %w", locationID, err)
	}

	if err := tx.Commit(); err != nil {
		return Location{}, fmt.Errorf("commit update location: %w", err)
	}

	return s.location(ctx, locationID)
}

// DeleteLocation removes one location. Children become root locations through
// parent_id's ON DELETE SET NULL; a location that still holds batches is
// refused, matching the foreign key that protects the row too.
func (s *Service) DeleteLocation(ctx context.Context, locationID string) error {
	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("begin delete location: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	found, err := rowExists(ctx, tx, "locations", locationID)
	if err != nil {
		return fmt.Errorf("read location %s: %w", locationID, err)
	}
	if !found {
		return ErrLocationNotFound
	}

	inUse, err := locationsWithBatches(ctx, tx, []string{locationID})
	if err != nil {
		return err
	}
	if len(inUse) > 0 {
		return &LocationHasBatchesError{IDs: inUse}
	}

	if _, err := tx.ExecContext(ctx, "DELETE FROM locations WHERE id = ?", locationID); err != nil {
		return fmt.Errorf("delete location %s: %w", locationID, err)
	}

	if err := tx.Commit(); err != nil {
		return fmt.Errorf("commit delete location: %w", err)
	}
	return nil
}

// BulkDeleteLocations deletes every listed location in one transaction. One
// location that still holds batches refuses the whole selection, matching the
// old bulk action; missing and duplicate ids are ignored.
func (s *Service) BulkDeleteLocations(ctx context.Context, locationIDs []string) (int, error) {
	unique := uniqueNonEmpty(locationIDs)
	if len(unique) == 0 {
		return 0, nil
	}

	tx, err := s.db.Writer().BeginTx(ctx, nil)
	if err != nil {
		return 0, fmt.Errorf("begin bulk delete locations: %w", err)
	}
	defer func() { _ = tx.Rollback() }()

	inUse, err := locationsWithBatches(ctx, tx, unique)
	if err != nil {
		return 0, err
	}
	if len(inUse) > 0 {
		return 0, &LocationHasBatchesError{IDs: inUse}
	}

	args := make([]any, len(unique))
	for i, locationID := range unique {
		args[i] = locationID
	}

	result, err := tx.ExecContext(ctx,
		"DELETE FROM locations WHERE id IN ("+placeholders(len(unique))+")", args...)
	if err != nil {
		return 0, fmt.Errorf("bulk delete locations: %w", err)
	}
	deleted, err := result.RowsAffected()
	if err != nil {
		return 0, fmt.Errorf("count bulk deleted locations: %w", err)
	}

	if err := tx.Commit(); err != nil {
		return 0, fmt.Errorf("commit bulk delete locations: %w", err)
	}

	return int(deleted), nil
}

// location reads one location with its parent name and batch count.
func (s *Service) location(ctx context.Context, locationID string) (Location, error) {
	location, err := scanLocation(s.db.Reader().QueryRowContext(ctx,
		locationSelect+" WHERE l.id = ? GROUP BY l.id", locationID))
	if errors.Is(err, sql.ErrNoRows) {
		return Location{}, ErrLocationNotFound
	}
	if err != nil {
		return Location{}, fmt.Errorf("read location %s: %w", locationID, err)
	}
	return location, nil
}

func validateLocation(name, description string, days int) error {
	switch {
	case name == "":
		return fieldError("name", "required")
	case utf8.RuneCountInString(name) > MaxNameLength:
		return fieldError("name", "max")
	}

	if utf8.RuneCountInString(description) > MaxLocationDescriptionLength {
		return fieldError("description", "max")
	}
	if days < 0 {
		return fieldError("expiration_notify_days", "min")
	}
	return nil
}

// checkLocationParent rejects a parent that does not exist and one that would
// make the location its own ancestor. The walk is bounded by a seen set so a
// corrupt pre-existing loop cannot hang the request.
func checkLocationParent(ctx context.Context, tx *sql.Tx, locationID, parentID string) error {
	if parentID == "" {
		return nil
	}

	found, err := rowExists(ctx, tx, "locations", parentID)
	if err != nil {
		return fmt.Errorf("read parent location %s: %w", parentID, err)
	}
	if !found {
		return fieldError("parent_id", "exists")
	}

	seen := make(map[string]struct{})
	for current := parentID; current != ""; {
		if current == locationID {
			return fieldError("parent_id", "cycle")
		}
		if _, exists := seen[current]; exists {
			return fieldError("parent_id", "cycle")
		}
		seen[current] = struct{}{}

		var next sql.NullString
		if err := tx.QueryRowContext(ctx,
			"SELECT parent_id FROM locations WHERE id = ?", current).Scan(&next); err != nil {
			if errors.Is(err, sql.ErrNoRows) {
				return nil
			}
			return fmt.Errorf("read parent of location %s: %w", current, err)
		}
		current = next.String
	}

	return nil
}

// locationsWithBatches returns the sorted ids in the selection that still hold
// at least one batch.
func locationsWithBatches(ctx context.Context, tx *sql.Tx, locationIDs []string) ([]string, error) {
	if len(locationIDs) == 0 {
		return nil, nil
	}

	selected, err := jsonIDs(locationIDs)
	if err != nil {
		return nil, err
	}

	rows, err := tx.QueryContext(ctx, `SELECT DISTINCT b.location_id
		FROM batches b
		WHERE b.location_id IN (SELECT value FROM json_each(?))`, selected)
	if err != nil {
		return nil, fmt.Errorf("read locations with batches: %w", err)
	}
	defer func() { _ = rows.Close() }()

	inUse := make([]string, 0)
	for rows.Next() {
		var locationID string
		if err := rows.Scan(&locationID); err != nil {
			return nil, fmt.Errorf("scan location with batches: %w", err)
		}
		inUse = append(inUse, locationID)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("read locations with batches: %w", err)
	}

	slices.Sort(inUse)
	return inUse, nil
}

func scanLocation(row rowScanner) (Location, error) {
	var (
		location  Location
		createdAt string
		updatedAt string
	)
	if err := row.Scan(&location.ID, &location.Name, &location.Description,
		&location.ExpirationNotifyDays, &location.ParentID, &location.ParentName,
		&location.BatchCount, &createdAt, &updatedAt); err != nil {
		return Location{}, err
	}

	created, err := parseTime(createdAt)
	if err != nil {
		return Location{}, err
	}
	updated, err := parseTime(updatedAt)
	if err != nil {
		return Location{}, err
	}
	location.CreatedAt, location.UpdatedAt = created, updated

	return location, nil
}

func locationFilter(term string) (string, []any) {
	term = strings.TrimSpace(term)
	if term == "" {
		return "", nil
	}

	pattern := "%" + escapeLike(strings.ToLower(term)) + "%"
	where := `WHERE (
		LOWER(l.name) LIKE ? ESCAPE '\'
		OR LOWER(COALESCE(l.description, '')) LIKE ? ESCAPE '\'
		OR LOWER(COALESCE(p.name, '')) LIKE ? ESCAPE '\'
	)`

	return where, []any{pattern, pattern, pattern}
}

func locationOrder(sort, dir string) string {
	const tieBreak = ", l.name ASC, l.id ASC"

	switch sort {
	case "created_at":
		return "l.created_at " + dir + tieBreak
	case "updated_at":
		return "l.updated_at " + dir + tieBreak
	default:
		return "l.name " + dir + ", l.id " + dir
	}
}
