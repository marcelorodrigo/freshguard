package ids

import (
	"testing"

	"github.com/google/uuid"
)

// TestNewIsUUIDv7 pins the format decision D6: text keys that sort by creation
// time and survive an import.
func TestNewIsUUIDv7(t *testing.T) {
	t.Parallel()

	id := New()

	parsed, err := uuid.Parse(id)
	if err != nil {
		t.Fatalf("Parse(%q): %v", id, err)
	}
	if got := parsed.Version(); got != 7 {
		t.Errorf("version = %d, want 7", got)
	}
	if got := parsed.Variant(); got != uuid.RFC4122 {
		t.Errorf("variant = %v, want RFC4122", got)
	}
	if other := New(); other == id {
		t.Error("two calls returned the same id")
	}
}
