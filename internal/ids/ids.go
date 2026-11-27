// Package ids generates the identifiers the database uses.
//
// Every table keys on a UUIDv7 text value: sortable by creation time, stable
// through an import, and usable in a URL without a lookup.
package ids

import "github.com/google/uuid"

// New returns a fresh UUIDv7 in its canonical text form.
//
// It panics when the system random source fails, which is the condition
// crypto/rand reports as unrecoverable; the server has no sensible fallback.
func New() string {
	return uuid.Must(uuid.NewV7()).String()
}
