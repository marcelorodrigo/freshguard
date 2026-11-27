package inventory

import (
	"context"
	"errors"
	"strings"
	"testing"
)

func createLocation(t *testing.T, env *testEnv, input LocationInput) Location {
	t.Helper()

	location, err := env.service.CreateLocation(context.Background(), input)
	if err != nil {
		t.Fatalf("CreateLocation(%q): %v", input.Name, err)
	}
	return location
}

func TestCreateLocation(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)

	root := createLocation(t, env, LocationInput{
		Name:                 "  Kitchen ",
		Description:          "  Main shelf ",
		ExpirationNotifyDays: 3,
	})
	if root.Name != "Kitchen" || root.Description != "Main shelf" {
		t.Errorf("root = %+v, want the trimmed values", root)
	}
	if root.ExpirationNotifyDays != 3 {
		t.Errorf("notify days = %d, want 3", root.ExpirationNotifyDays)
	}
	if root.ParentID != "" || root.ParentName != "" {
		t.Errorf("parent = %q/%q, want a root location", root.ParentID, root.ParentName)
	}
	if !root.CreatedAt.Equal(testNow) || !root.UpdatedAt.Equal(testNow) {
		t.Errorf("timestamps = %v/%v, want %v", root.CreatedAt, root.UpdatedAt, testNow)
	}

	child := createLocation(t, env, LocationInput{Name: "Fridge", ParentID: root.ID})
	if child.ParentID != root.ID || child.ParentName != "Kitchen" {
		t.Errorf("child parent = %+v, want the kitchen ref", child)
	}
}

func TestUpdateLocation(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	ctx := context.Background()
	root := createLocation(t, env, LocationInput{Name: "Kitchen"})
	child := createLocation(t, env, LocationInput{Name: "Fridge", ParentID: root.ID})

	name := "Fridge door"
	description := "Top shelf"
	days := 7
	updated, err := env.service.UpdateLocation(ctx, child.ID, LocationPatch{
		Name:                 &name,
		Description:          &description,
		ExpirationNotifyDays: &days,
	})
	if err != nil {
		t.Fatalf("UpdateLocation: %v", err)
	}
	if updated.Name != name || updated.Description != description || updated.ExpirationNotifyDays != days {
		t.Errorf("updated = %+v, want the patched fields", updated)
	}
	if updated.ParentID != root.ID {
		t.Errorf("parent = %q, want the unchanged kitchen", updated.ParentID)
	}

	none := ""
	promoted, err := env.service.UpdateLocation(ctx, child.ID, LocationPatch{ParentID: &none})
	if err != nil {
		t.Fatalf("UpdateLocation clear parent: %v", err)
	}
	if promoted.ParentID != "" || promoted.ParentName != "" {
		t.Errorf("parent = %q/%q, want cleared", promoted.ParentID, promoted.ParentName)
	}
}

func TestUnknownLocation(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	ctx := context.Background()
	name := "Missing"

	if _, err := env.service.UpdateLocation(ctx, "loc-missing", LocationPatch{Name: &name}); !errors.Is(err, ErrLocationNotFound) {
		t.Errorf("update unknown error = %v, want ErrLocationNotFound", err)
	}
	if err := env.service.DeleteLocation(ctx, "loc-missing"); !errors.Is(err, ErrLocationNotFound) {
		t.Errorf("delete unknown error = %v, want ErrLocationNotFound", err)
	}
}

func TestLocationValidation(t *testing.T) {
	t.Parallel()

	cases := []struct {
		name  string
		input LocationInput
		field string
		rule  string
	}{
		{
			name:  "missing name",
			input: LocationInput{Name: "   "},
			field: "name",
			rule:  "required",
		},
		{
			name:  "long name",
			input: LocationInput{Name: strings.Repeat("a", MaxNameLength+1)},
			field: "name",
			rule:  "max",
		},
		{
			name:  "long description",
			input: LocationInput{Name: "Fridge", Description: strings.Repeat("a", MaxLocationDescriptionLength+1)},
			field: "description",
			rule:  "max",
		},
		{
			name:  "negative notify days",
			input: LocationInput{Name: "Fridge", ExpirationNotifyDays: -1},
			field: "expiration_notify_days",
			rule:  "min",
		},
		{
			name:  "unknown parent",
			input: LocationInput{Name: "Fridge", ParentID: "loc-missing"},
			field: "parent_id",
			rule:  "exists",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			t.Parallel()

			env := newTestEnv(t)
			_, err := env.service.CreateLocation(context.Background(), tc.input)

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

func TestLocationParentRejectsCycles(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	ctx := context.Background()

	kitchen := createLocation(t, env, LocationInput{Name: "Kitchen"})
	fridge := createLocation(t, env, LocationInput{Name: "Fridge", ParentID: kitchen.ID})
	shelf := createLocation(t, env, LocationInput{Name: "Shelf", ParentID: fridge.ID})

	cases := []struct {
		name     string
		parentID string
	}{
		{name: "itself", parentID: kitchen.ID},
		{name: "a child", parentID: fridge.ID},
		{name: "a deeper descendant", parentID: shelf.ID},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			parentID := tc.parentID
			_, err := env.service.UpdateLocation(ctx, kitchen.ID, LocationPatch{ParentID: &parentID})

			var fieldErr *FieldError
			if !errors.As(err, &fieldErr) {
				t.Fatalf("error = %v, want a FieldError", err)
			}
			if fieldErr.Field != "parent_id" || fieldErr.Rule != "cycle" {
				t.Errorf("field error = %+v, want parent_id:cycle", fieldErr)
			}
		})
	}

	// An ancestor is a valid parent; only moving a location under itself is a
	// cycle.
	if _, err := env.service.UpdateLocation(ctx, shelf.ID, LocationPatch{ParentID: &kitchen.ID}); err != nil {
		t.Fatalf("move shelf under kitchen: %v", err)
	}

	unchanged, err := env.service.location(ctx, kitchen.ID)
	if err != nil {
		t.Fatalf("read kitchen: %v", err)
	}
	if unchanged.ParentID != "" {
		t.Errorf("kitchen parent = %q, want the rejected patches to leave it alone", unchanged.ParentID)
	}
}

func TestDeleteLocationWithBatchesIsRefused(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	ctx := context.Background()

	location := createLocation(t, env, LocationInput{Name: "Fridge"})
	item := createItem(t, env, ItemInput{Name: "Milk"})
	env.addBatch(t, "batch-1", item.ID, location.ID, "2026-02-01", 2)

	err := env.service.DeleteLocation(ctx, location.ID)
	var inUse *LocationHasBatchesError
	if !errors.As(err, &inUse) {
		t.Fatalf("error = %v, want LocationHasBatchesError", err)
	}
	if len(inUse.IDs) != 1 || inUse.IDs[0] != location.ID {
		t.Errorf("offenders = %v, want [%s]", inUse.IDs, location.ID)
	}

	page, err := env.service.ListLocations(ctx, ListParams{})
	if err != nil {
		t.Fatalf("ListLocations: %v", err)
	}
	if len(page.Locations) != 1 || page.Locations[0].BatchCount != 1 {
		t.Errorf("locations = %+v, want the fridge with its batch", page.Locations)
	}
}

func TestDeleteLocationPromotesChildren(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	ctx := context.Background()

	parent := createLocation(t, env, LocationInput{Name: "Kitchen"})
	child := createLocation(t, env, LocationInput{Name: "Fridge", ParentID: parent.ID})

	if err := env.service.DeleteLocation(ctx, parent.ID); err != nil {
		t.Fatalf("DeleteLocation: %v", err)
	}

	page, err := env.service.ListLocations(ctx, ListParams{})
	if err != nil {
		t.Fatalf("ListLocations: %v", err)
	}
	if len(page.Locations) != 1 || page.Locations[0].ID != child.ID {
		t.Fatalf("locations = %+v, want only the child", page.Locations)
	}
	if page.Locations[0].ParentID != "" || page.Locations[0].ParentName != "" {
		t.Errorf("child parent = %q/%q, want a root location",
			page.Locations[0].ParentID, page.Locations[0].ParentName)
	}
}

func TestBulkDeleteLocationsIsAtomic(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	ctx := context.Background()

	empty := createLocation(t, env, LocationInput{Name: "Empty"})
	populated := createLocation(t, env, LocationInput{Name: "Fridge"})
	item := createItem(t, env, ItemInput{Name: "Milk"})
	env.addBatch(t, "batch-1", item.ID, populated.ID, "2026-02-01", 2)

	_, err := env.service.BulkDeleteLocations(ctx, []string{empty.ID, populated.ID})
	var inUse *LocationHasBatchesError
	if !errors.As(err, &inUse) {
		t.Fatalf("error = %v, want LocationHasBatchesError", err)
	}
	if len(inUse.IDs) != 1 || inUse.IDs[0] != populated.ID {
		t.Errorf("offenders = %v, want [%s]", inUse.IDs, populated.ID)
	}

	page, err := env.service.ListLocations(ctx, ListParams{})
	if err != nil {
		t.Fatalf("ListLocations after rejected bulk delete: %v", err)
	}
	if page.Total != 2 {
		t.Errorf("total = %d, want both locations kept", page.Total)
	}

	deleted, err := env.service.BulkDeleteLocations(ctx, []string{empty.ID, empty.ID, "loc-missing"})
	if err != nil {
		t.Fatalf("BulkDeleteLocations: %v", err)
	}
	if deleted != 1 {
		t.Errorf("deleted = %d, want 1: duplicates and missing ids are skipped", deleted)
	}

	page, err = env.service.ListLocations(ctx, ListParams{})
	if err != nil {
		t.Fatalf("ListLocations after successful bulk delete: %v", err)
	}
	if page.Total != 1 || page.Locations[0].ID != populated.ID {
		t.Errorf("locations = %+v, want only the populated one", page.Locations)
	}
}
