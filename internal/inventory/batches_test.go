package inventory

import (
	"context"
	"errors"
	"strings"
	"testing"
)

func (e *testEnv) addNamedLocation(t *testing.T, locationID, name, parentID string) {
	t.Helper()

	var parent any
	if parentID != "" {
		parent = parentID
	}

	_, err := e.db.Writer().ExecContext(context.Background(), `INSERT INTO locations
		(id, name, description, expiration_notify_days, parent_id, created_at, updated_at)
		VALUES (?, ?, NULL, 0, ?, ?, ?)`,
		locationID, name, parent, formatTime(testNow), formatTime(testNow))
	if err != nil {
		t.Fatalf("insert location %s: %v", name, err)
	}
}

func TestBatchWritesRecomputeQuantityAndEarliestExpiration(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	ctx := context.Background()
	item := createItem(t, env, ItemInput{Name: "Milk"})
	env.addLocation(t, "loc-fridge")

	first, err := env.service.CreateBatch(ctx, item.ID, BatchInput{
		LocationID: "loc-fridge", ExpiresAt: "2026-02-10", Quantity: 2,
	})
	if err != nil {
		t.Fatalf("CreateBatch: %v", err)
	}
	if first.LocationName != "Fridge" || first.Quantity != 2 {
		t.Errorf("batch = %+v, want the Fridge batch of 2", first)
	}

	second, err := env.service.CreateBatch(ctx, item.ID, BatchInput{
		LocationID: "loc-fridge", ExpiresAt: "2026-02-01", Quantity: 3,
	})
	if err != nil {
		t.Fatalf("CreateBatch: %v", err)
	}

	got, err := env.service.Get(ctx, item.ID)
	if err != nil {
		t.Fatalf("Get: %v", err)
	}
	if got.Quantity != 5 {
		t.Errorf("quantity = %d, want 5 (2+3)", got.Quantity)
	}
	if got.EarliestExpiration != "2026-02-01" {
		t.Errorf("earliest expiration = %q, want 2026-02-01", got.EarliestExpiration)
	}
	if got.BatchCount != 2 || len(got.Batches) != 2 {
		t.Fatalf("batches = %d/%d, want 2", got.BatchCount, len(got.Batches))
	}
	if got.Batches[0].ID != second.ID || got.Batches[1].ID != first.ID {
		t.Errorf("batches are not sorted by expiry ascending: %+v", got.Batches)
	}

	more := 7
	updated, err := env.service.UpdateBatch(ctx, first.ID, BatchPatch{Quantity: &more})
	if err != nil {
		t.Fatalf("UpdateBatch quantity: %v", err)
	}
	if updated.Quantity != 7 {
		t.Errorf("updated quantity = %d, want 7", updated.Quantity)
	}

	got, err = env.service.Get(ctx, item.ID)
	if err != nil {
		t.Fatalf("Get after update: %v", err)
	}
	if got.Quantity != 10 {
		t.Errorf("quantity = %d, want 10 (7+3)", got.Quantity)
	}
	if got.EarliestExpiration != "2026-02-01" {
		t.Errorf("earliest expiration = %q, want 2026-02-01", got.EarliestExpiration)
	}

	later := "2026-03-01"
	if _, err := env.service.UpdateBatch(ctx, second.ID, BatchPatch{ExpiresAt: &later}); err != nil {
		t.Fatalf("UpdateBatch expiry: %v", err)
	}

	got, err = env.service.Get(ctx, item.ID)
	if err != nil {
		t.Fatalf("Get after expiry change: %v", err)
	}
	if got.EarliestExpiration != "2026-02-10" {
		t.Errorf("earliest expiration = %q, want the new minimum 2026-02-10", got.EarliestExpiration)
	}

	if err := env.service.DeleteBatch(ctx, first.ID); err != nil {
		t.Fatalf("DeleteBatch: %v", err)
	}

	got, err = env.service.Get(ctx, item.ID)
	if err != nil {
		t.Fatalf("Get after delete: %v", err)
	}
	if got.Quantity != 3 || got.BatchCount != 1 || got.EarliestExpiration != "2026-03-01" {
		t.Errorf("after delete = quantity %d, batches %d, earliest %q; want 3, 1, 2026-03-01",
			got.Quantity, got.BatchCount, got.EarliestExpiration)
	}

	if err := env.service.DeleteBatch(ctx, second.ID); err != nil {
		t.Fatalf("DeleteBatch last: %v", err)
	}

	got, err = env.service.Get(ctx, item.ID)
	if err != nil {
		t.Fatalf("Get after last delete: %v", err)
	}
	if got.Quantity != 0 || got.BatchCount != 0 || got.EarliestExpiration != "" {
		t.Errorf("after last delete = quantity %d, batches %d, earliest %q; want zero",
			got.Quantity, got.BatchCount, got.EarliestExpiration)
	}

	if err := env.service.DeleteBatch(ctx, second.ID); !errors.Is(err, ErrBatchNotFound) {
		t.Errorf("deleting a gone batch = %v, want ErrBatchNotFound", err)
	}
}

func TestCreateBatchValidation(t *testing.T) {
	t.Parallel()

	cases := []struct {
		name  string
		input BatchInput
		field string
		rule  string
	}{
		{
			name:  "missing location",
			input: BatchInput{ExpiresAt: "2026-02-01", Quantity: 1},
			field: "location_id",
			rule:  "required",
		},
		{
			name:  "unknown location",
			input: BatchInput{LocationID: "loc-missing", ExpiresAt: "2026-02-01", Quantity: 1},
			field: "location_id",
			rule:  "exists",
		},
		{
			name:  "missing expiration",
			input: BatchInput{LocationID: "loc-fridge", Quantity: 1},
			field: "expires_at",
			rule:  "required",
		},
		{
			name:  "malformed expiration",
			input: BatchInput{LocationID: "loc-fridge", ExpiresAt: "2026-2-1", Quantity: 1},
			field: "expires_at",
			rule:  "date",
		},
		{
			name:  "zero quantity",
			input: BatchInput{LocationID: "loc-fridge", ExpiresAt: "2026-02-01"},
			field: "quantity",
			rule:  "min",
		},
		{
			name:  "negative quantity",
			input: BatchInput{LocationID: "loc-fridge", ExpiresAt: "2026-02-01", Quantity: -3},
			field: "quantity",
			rule:  "min",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			t.Parallel()

			env := newTestEnv(t)
			item := createItem(t, env, ItemInput{Name: "Milk"})
			env.addLocation(t, "loc-fridge")

			_, err := env.service.CreateBatch(context.Background(), item.ID, tc.input)

			var fieldErr *FieldError
			if !errors.As(err, &fieldErr) {
				t.Fatalf("error = %v, want a FieldError", err)
			}
			if fieldErr.Field != tc.field || fieldErr.Rule != tc.rule {
				t.Errorf("field error = %+v, want %s:%s", fieldErr, tc.field, tc.rule)
			}
		})
	}
}

func TestCreateBatchUnknownItem(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addLocation(t, "loc-fridge")

	_, err := env.service.CreateBatch(context.Background(), "item-missing", BatchInput{
		LocationID: "loc-fridge", ExpiresAt: "2026-02-01", Quantity: 1,
	})
	if !errors.Is(err, ErrItemNotFound) {
		t.Errorf("error = %v, want ErrItemNotFound", err)
	}
}

func TestUpdateBatchValidationLeavesTheBatchAlone(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	ctx := context.Background()
	item := createItem(t, env, ItemInput{Name: "Milk"})
	env.addLocation(t, "loc-fridge")

	batch, err := env.service.CreateBatch(ctx, item.ID, BatchInput{
		LocationID: "loc-fridge", ExpiresAt: "2026-02-01", Quantity: 2,
	})
	if err != nil {
		t.Fatalf("CreateBatch: %v", err)
	}

	zero := 0
	var fieldErr *FieldError
	if _, err := env.service.UpdateBatch(ctx, batch.ID, BatchPatch{Quantity: &zero}); !errors.As(err, &fieldErr) {
		t.Fatalf("zero quantity error = %v, want a FieldError", err)
	}
	if fieldErr.Rule != "min" || fieldErr.Field != "quantity" {
		t.Errorf("field error = %+v, want quantity:min", fieldErr)
	}

	missing := "loc-missing"
	fieldErr = nil
	if _, err := env.service.UpdateBatch(ctx, batch.ID, BatchPatch{LocationID: &missing}); !errors.As(err, &fieldErr) {
		t.Fatalf("unknown location error = %v, want a FieldError", err)
	}
	if fieldErr.Rule != "exists" || fieldErr.Field != "location_id" {
		t.Errorf("field error = %+v, want location_id:exists", fieldErr)
	}

	unchanged, err := env.service.batch(ctx, batch.ID)
	if err != nil {
		t.Fatalf("batch: %v", err)
	}
	if unchanged.Quantity != 2 || unchanged.LocationID != "loc-fridge" || unchanged.ExpiresAt != "2026-02-01" {
		t.Errorf("batch = %+v, want the accepted values after rejected patches", unchanged)
	}

	if _, err := env.service.UpdateBatch(ctx, "batch-missing", BatchPatch{}); !errors.Is(err, ErrBatchNotFound) {
		t.Errorf("unknown batch error = %v, want ErrBatchNotFound", err)
	}
}

func TestListLocations(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	ctx := context.Background()
	env.addNamedLocation(t, "loc-kitchen", "Kitchen", "")
	env.addNamedLocation(t, "loc-fridge", "Fridge", "loc-kitchen")

	item := createItem(t, env, ItemInput{Name: "Milk"})
	if _, err := env.service.CreateBatch(ctx, item.ID, BatchInput{
		LocationID: "loc-fridge", ExpiresAt: "2026-02-01", Quantity: 2,
	}); err != nil {
		t.Fatalf("CreateBatch: %v", err)
	}

	page, err := env.service.ListLocations(ctx, ListParams{})
	if err != nil {
		t.Fatalf("ListLocations: %v", err)
	}
	if got := strings.Join(locationNames(page.Locations), ","); got != "Fridge,Kitchen" {
		t.Fatalf("names = %q, want name ascending", got)
	}

	fridge := page.Locations[0]
	if fridge.BatchCount != 1 || fridge.ParentID != "loc-kitchen" || fridge.ParentName != "Kitchen" {
		t.Errorf("fridge = %+v, want one batch inside Kitchen", fridge)
	}
	if kitchen := page.Locations[1]; kitchen.BatchCount != 0 || kitchen.ParentID != "" {
		t.Errorf("kitchen = %+v, want a root location without batches", kitchen)
	}

	filtered, err := env.service.ListLocations(ctx, ListParams{Q: "kitchen"})
	if err != nil {
		t.Fatalf("ListLocations q: %v", err)
	}
	if filtered.Total != 2 {
		t.Errorf("total = %d, want 2: the parent name matches too", filtered.Total)
	}

	filtered, err = env.service.ListLocations(ctx, ListParams{Q: "shelf"})
	if err != nil {
		t.Fatalf("ListLocations q: %v", err)
	}
	if filtered.Total != 0 || len(filtered.Locations) != 0 {
		t.Errorf("locations = %v, want none", filtered.Locations)
	}
}

func locationNames(locations []Location) []string {
	names := make([]string, len(locations))
	for i, location := range locations {
		names[i] = location.Name
	}
	return names
}
