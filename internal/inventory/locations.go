package inventory

import (
	"context"
	"fmt"
	"strings"
)

// ListLocations returns one page of locations with their parent name and the
// number of batches each holds. Batches need the picker this list feeds; the
// full location screen arrives with its own ticket.
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
	query := `SELECT
	l.id, l.name, COALESCE(l.description, ''), l.expiration_notify_days,
	COALESCE(l.parent_id, ''), COALESCE(p.name, ''),
	COUNT(b.id) AS batch_count,
	l.created_at, l.updated_at
FROM locations l
LEFT JOIN locations p ON p.id = l.parent_id
LEFT JOIN batches b ON b.location_id = l.id
` + where + " GROUP BY l.id ORDER BY " + locationOrder(params.Sort, params.Dir) +
		" LIMIT ? OFFSET ?"

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
