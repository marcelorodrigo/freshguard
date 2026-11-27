// Package inventory owns items, tags, batches and locations.
//
// It runs on the database and returns rule errors; it never imports net/http.
// The HTTP layer maps a FieldError to a 422 with the rule token and an error
// sentinel to its status, as described in docs/api.md.
package inventory

import (
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/marcelorodrigo/freshguard/internal/store"
)

const (
	// DefaultPerPage is the Filament table page size.
	DefaultPerPage = 10
	// MaxPerPage caps a caller that asks for everything.
	MaxPerPage = 100

	// MaxNameLength, MaxBarcodeLength and MaxDescriptionLength bound item input.
	MaxNameLength        = 255
	MaxBarcodeLength     = 255
	MaxDescriptionLength = 1000
	// MaxLocationDescriptionLength bounds a location's description, matching
	// the old Filament form's maxLength(255).
	MaxLocationDescriptionLength = 255
	// TagSuggestionLimit is the number of tags the form offers.
	TagSuggestionLimit = 50

	timeLayout = time.RFC3339
	dateLayout = "2006-01-02"
)

// ErrItemNotFound, ErrBatchNotFound and ErrLocationNotFound name an id that
// does not exist. ErrBatchUnavailable means a consume raced another writer:
// the batch was gone or empty before the unit could be taken.
var (
	ErrItemNotFound     = errors.New("inventory: item not found")
	ErrBatchNotFound    = errors.New("inventory: batch not found")
	ErrLocationNotFound = errors.New("inventory: location not found")
	ErrBatchUnavailable = errors.New("inventory: batch unavailable")
)

// LocationHasBatchesError reports locations that still hold inventory. Deleting
// them is refused and IDs names the offenders so a bulk caller can say which.
type LocationHasBatchesError struct {
	IDs []string
}

func (e *LocationHasBatchesError) Error() string {
	return "inventory: location still holds batches"
}

// FieldError is a validation failure expressed as the rule token the API
// returns, for example Field: "name", Rule: "required".
type FieldError struct {
	Field string
	Rule  string
}

func (e *FieldError) Error() string { return "inventory: " + e.Field + ": " + e.Rule }

func fieldError(field, rule string) error { return &FieldError{Field: field, Rule: rule} }

// Item is one row of the item list. Quantity and earliest expiration are
// computed from batches; there is no stored quantity column.
type Item struct {
	ID                 string
	Name               string
	Barcode            string
	Description        string
	Tags               []string
	Quantity           int
	EarliestExpiration string
	BatchCount         int
	CreatedAt          time.Time
	UpdatedAt          time.Time
	// Batches is populated by Get, not by ListItems.
	Batches []Batch
}

// Batch is a batch inside an item detail response.
type Batch struct {
	ID           string
	ItemID       string
	LocationID   string
	LocationName string
	ExpiresAt    string
	Quantity     int
	CreatedAt    time.Time
	UpdatedAt    time.Time
}

// BatchPatch is a subset of the fields PATCH /batches/{id} may change. A nil
// field is left alone.
type BatchPatch struct {
	LocationID *string
	ExpiresAt  *string
	Quantity   *int
}

// BatchInput is the payload of batch creation.
type BatchInput struct {
	LocationID string
	ExpiresAt  string
	Quantity   int
}

// Tag is a suggestion with the number of items carrying it.
type Tag struct {
	ID        string
	Name      string
	ItemCount int
}

// Page is one page of items and the metadata the API returns beside it.
type Page struct {
	Items   []Item
	Page    int
	PerPage int
	Total   int
	Pages   int
}

// Location is one row of the location list.
type Location struct {
	ID                   string
	Name                 string
	Description          string
	ExpirationNotifyDays int
	ParentID             string
	ParentName           string
	BatchCount           int
	CreatedAt            time.Time
	UpdatedAt            time.Time
}

// LocationPage is one page of locations and its metadata.
type LocationPage struct {
	Locations []Location
	Page      int
	PerPage   int
	Total     int
	Pages     int
}

// LocationInput is the payload of location creation.
type LocationInput struct {
	Name                 string
	Description          string
	ExpirationNotifyDays int
	ParentID             string
}

// LocationPatch is a subset of the fields PATCH /locations/{id} may change. A
// nil field is left alone; a non-nil ParentID of "" makes it a root location.
type LocationPatch struct {
	Name                 *string
	Description          *string
	ExpirationNotifyDays *int
	ParentID             *string
}

// ListParams is the validated shape of an item list query.
type ListParams struct {
	Q       string
	Sort    string
	Dir     string
	Page    int
	PerPage int
}

// ItemInput is the payload of item creation.
type ItemInput struct {
	Name        string
	Barcode     string
	Description string
	Tags        []string
}

// ItemPatch is a subset of the fields PATCH /items/{id} may change. A nil
// field is left alone; a non-nil Tags replaces the whole set.
type ItemPatch struct {
	Name        *string
	Barcode     *string
	Description *string
	Tags        *[]string
}

// Options configures a Service.
type Options struct {
	// Store is the open database. Required.
	Store *store.Store
	// Now overrides the clock in tests; nil uses time.Now.
	Now func() time.Time
}

// Service is the inventory logic.
type Service struct {
	db  *store.Store
	now func() time.Time
}

// New builds the service. It panics without a Store: that is a wiring bug.
func New(opts Options) *Service {
	if opts.Store == nil {
		panic("inventory: New requires Options.Store")
	}
	if opts.Now == nil {
		opts.Now = time.Now
	}

	return &Service{db: opts.Store, now: opts.Now}
}

type rowScanner interface {
	Scan(dest ...any) error
}

func validateItem(name, barcode, description string) error {
	switch {
	case strings.TrimSpace(name) == "":
		return fieldError("name", "required")
	case utf8.RuneCountInString(name) > MaxNameLength:
		return fieldError("name", "max")
	}

	if utf8.RuneCountInString(barcode) > MaxBarcodeLength {
		return fieldError("barcode", "max")
	}
	if utf8.RuneCountInString(description) > MaxDescriptionLength {
		return fieldError("description", "max")
	}
	return nil
}

// normalizeTags trims, drops empty values and merges case-insensitive
// duplicates, keeping the first spelling the caller wrote.
func normalizeTags(tags []string) []string {
	normalized := make([]string, 0, len(tags))
	seen := make(map[string]struct{}, len(tags))

	for _, tag := range tags {
		tag = strings.TrimSpace(tag)
		if tag == "" {
			continue
		}

		key := strings.ToLower(tag)
		if _, exists := seen[key]; exists {
			continue
		}
		seen[key] = struct{}{}
		normalized = append(normalized, tag)
	}

	return normalized
}

// jsonIDs encodes an id list as the JSON array json_each reads, keeping every
// SQL statement constant instead of building a placeholder list.
func jsonIDs(ids []string) (string, error) {
	if ids == nil {
		ids = []string{}
	}

	encoded, err := json.Marshal(ids)
	if err != nil {
		return "", fmt.Errorf("encode id list: %w", err)
	}
	return string(encoded), nil
}

// uniqueNonEmpty trims, drops blanks and removes duplicate ids while keeping
// the caller's order.
func uniqueNonEmpty(values []string) []string {
	unique := make([]string, 0, len(values))
	seen := make(map[string]struct{}, len(values))

	for _, value := range values {
		value = strings.TrimSpace(value)
		if value == "" {
			continue
		}
		if _, exists := seen[value]; exists {
			continue
		}
		seen[value] = struct{}{}
		unique = append(unique, value)
	}

	return unique
}

func (p ListParams) normalized() ListParams {
	if p.Page < 1 {
		p.Page = 1
	}
	if p.PerPage <= 0 {
		p.PerPage = DefaultPerPage
	}
	if p.PerPage > MaxPerPage {
		p.PerPage = MaxPerPage
	}

	switch strings.TrimSpace(p.Sort) {
	case "quantity", "earliest_expiration", "created_at", "updated_at":
		p.Sort = strings.TrimSpace(p.Sort)
	default:
		p.Sort = "name"
	}

	if strings.EqualFold(strings.TrimSpace(p.Dir), "desc") {
		p.Dir = "desc"
	} else {
		p.Dir = "asc"
	}

	return p
}

func formatTime(t time.Time) string {
	return t.UTC().Format(timeLayout)
}

func parseTime(value string) (time.Time, error) {
	parsed, err := time.Parse(timeLayout, value)
	if err != nil {
		return time.Time{}, fmt.Errorf("parse timestamp %q: %w", value, err)
	}
	return parsed.UTC(), nil
}
