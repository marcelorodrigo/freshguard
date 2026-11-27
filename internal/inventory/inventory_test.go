package inventory

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/marcelorodrigo/freshguard/internal/store"
)

var testNow = time.Date(2026, 1, 24, 8, 27, 26, 0, time.UTC)

type testEnv struct {
	service *Service
	db      *store.Store
}

func newTestEnv(t *testing.T) *testEnv {
	t.Helper()

	db, err := store.Open(t.TempDir())
	if err != nil {
		t.Fatalf("store.Open: %v", err)
	}
	t.Cleanup(func() {
		if err := db.Close(); err != nil {
			t.Errorf("store.Close: %v", err)
		}
	})

	service := New(Options{
		Store: db,
		Now:   func() time.Time { return testNow },
	})

	return &testEnv{service: service, db: db}
}

func (e *testEnv) addLocation(t *testing.T, locationID string) {
	t.Helper()

	_, err := e.db.Writer().ExecContext(context.Background(), `INSERT INTO locations
		(id, name, description, expiration_notify_days, parent_id, created_at, updated_at)
		VALUES (?, 'Fridge', NULL, 0, NULL, ?, ?)`,
		locationID, formatTime(testNow), formatTime(testNow))
	if err != nil {
		t.Fatalf("insert location: %v", err)
	}
}

func (e *testEnv) addBatch(t *testing.T, batchID, itemID, locationID, expiresAt string, quantity int) {
	t.Helper()

	_, err := e.db.Writer().ExecContext(context.Background(), `INSERT INTO batches
		(id, item_id, location_id, expires_at, quantity, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?)`,
		batchID, itemID, locationID, expiresAt, quantity,
		formatTime(testNow), formatTime(testNow))
	if err != nil {
		t.Fatalf("insert batch: %v", err)
	}
}

func createItem(t *testing.T, env *testEnv, input ItemInput) Item {
	t.Helper()

	item, err := env.service.CreateItem(context.Background(), input)
	if err != nil {
		t.Fatalf("CreateItem(%q): %v", input.Name, err)
	}
	return item
}

func findItem(t *testing.T, items []Item, name string) Item {
	t.Helper()

	for _, item := range items {
		if item.Name == name {
			return item
		}
	}
	t.Fatalf("item %q not found in %v", name, itemNames(items))
	return Item{}
}

func itemNames(items []Item) []string {
	names := make([]string, len(items))
	for i, item := range items {
		names[i] = item.Name
	}
	return names
}

func TestCreateAndGetItem(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)

	created := createItem(t, env, ItemInput{
		Name:        "  Whole milk ",
		Barcode:     " 7891000100103 ",
		Description: " 1 L ",
		Tags:        []string{"Dairy", " dairy ", "", "Fridge", "DAIRY"},
	})

	if created.Name != "Whole milk" {
		t.Errorf("name = %q, want trimmed", created.Name)
	}
	if created.Barcode != "7891000100103" {
		t.Errorf("barcode = %q, want trimmed", created.Barcode)
	}
	if created.Description != "1 L" {
		t.Errorf("description = %q, want trimmed", created.Description)
	}
	if got := strings.Join(created.Tags, ","); got != "Dairy,Fridge" {
		t.Errorf("tags = %q, want Dairy,Fridge", got)
	}
	if created.Quantity != 0 || created.BatchCount != 0 {
		t.Errorf("quantity/batches = %d/%d, want 0/0", created.Quantity, created.BatchCount)
	}
	if created.EarliestExpiration != "" {
		t.Errorf("earliest expiration = %q, want empty", created.EarliestExpiration)
	}
	if !created.CreatedAt.Equal(testNow) || !created.UpdatedAt.Equal(testNow) {
		t.Errorf("timestamps = %v/%v, want %v", created.CreatedAt, created.UpdatedAt, testNow)
	}
	if created.Batches == nil || len(created.Batches) != 0 {
		t.Errorf("batches = %v, want an empty slice", created.Batches)
	}

	got, err := env.service.Get(context.Background(), created.ID)
	if err != nil {
		t.Fatalf("Get: %v", err)
	}
	if got.Name != created.Name || strings.Join(got.Tags, ",") != "Dairy,Fridge" {
		t.Errorf("Get returned %+v, want the created item", got)
	}
}

func TestCreateItemValidation(t *testing.T) {
	t.Parallel()

	cases := []struct {
		name  string
		input ItemInput
		field string
		rule  string
	}{
		{
			name:  "missing name",
			input: ItemInput{Name: "   "},
			field: "name",
			rule:  "required",
		},
		{
			name:  "long name",
			input: ItemInput{Name: strings.Repeat("a", MaxNameLength+1)},
			field: "name",
			rule:  "max",
		},
		{
			name:  "long barcode",
			input: ItemInput{Name: "Milk", Barcode: strings.Repeat("1", MaxBarcodeLength+1)},
			field: "barcode",
			rule:  "max",
		},
		{
			name:  "long description",
			input: ItemInput{Name: "Milk", Description: strings.Repeat("a", MaxDescriptionLength+1)},
			field: "description",
			rule:  "max",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			t.Parallel()

			env := newTestEnv(t)
			_, err := env.service.CreateItem(context.Background(), tc.input)

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

func TestListComputesQuantityAndEarliestExpiration(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	milk := createItem(t, env, ItemInput{Name: "Milk"})
	createItem(t, env, ItemInput{Name: "Bread"})

	const locationID = "0190a1b2-0000-7000-8000-000000000010"
	env.addLocation(t, locationID)
	env.addBatch(t, "batch-2", milk.ID, locationID, "2026-02-01", 2)
	env.addBatch(t, "batch-1", milk.ID, locationID, "2026-01-15", 3)

	page, err := env.service.ListItems(context.Background(), ListParams{})
	if err != nil {
		t.Fatalf("ListItems: %v", err)
	}
	if got := strings.Join(itemNames(page.Items), ","); got != "Bread,Milk" {
		t.Fatalf("names = %q, want name ascending", got)
	}

	gotMilk := findItem(t, page.Items, "Milk")
	if gotMilk.Quantity != 5 {
		t.Errorf("quantity = %d, want 5", gotMilk.Quantity)
	}
	if gotMilk.EarliestExpiration != "2026-01-15" {
		t.Errorf("earliest expiration = %q, want 2026-01-15", gotMilk.EarliestExpiration)
	}
	if gotMilk.BatchCount != 2 {
		t.Errorf("batch count = %d, want 2", gotMilk.BatchCount)
	}

	bread := findItem(t, page.Items, "Bread")
	if bread.Quantity != 0 || bread.EarliestExpiration != "" || bread.BatchCount != 0 {
		t.Errorf("bread = %+v, want zero quantity and no expiration", bread)
	}

	if _, err := env.db.Writer().ExecContext(context.Background(),
		"DELETE FROM batches WHERE id = ?", "batch-1"); err != nil {
		t.Fatalf("delete batch: %v", err)
	}

	page, err = env.service.ListItems(context.Background(), ListParams{})
	if err != nil {
		t.Fatalf("ListItems after delete: %v", err)
	}
	gotMilk = findItem(t, page.Items, "Milk")
	if gotMilk.Quantity != 2 || gotMilk.EarliestExpiration != "2026-02-01" {
		t.Errorf("after delete = quantity %d, earliest %q; want 2, 2026-02-01",
			gotMilk.Quantity, gotMilk.EarliestExpiration)
	}
}

func TestListSearch(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	createItem(t, env, ItemInput{
		Name: "Milk", Description: "Dairy drink", Barcode: "789100", Tags: []string{"fridge"},
	})
	createItem(t, env, ItemInput{
		Name: "Bread", Description: "Bakery", Barcode: "123456", Tags: []string{"pantry"},
	})
	createItem(t, env, ItemInput{
		Name: "Butter", Description: "Milk based", Barcode: "555", Tags: []string{"dairy"},
	})

	cases := []struct {
		term string
		want string
	}{
		{term: "milk", want: "Butter,Milk"},
		{term: "BAKERY", want: "Bread"},
		{term: "789", want: "Milk"},
		{term: "fridge", want: "Milk"},
		{term: "dairy", want: "Butter,Milk"},
		{term: "  ", want: "Bread,Butter,Milk"},
	}

	for _, tc := range cases {
		t.Run(tc.term, func(t *testing.T) {
			page, err := env.service.ListItems(context.Background(), ListParams{Q: tc.term})
			if err != nil {
				t.Fatalf("ListItems(%q): %v", tc.term, err)
			}
			if got := strings.Join(itemNames(page.Items), ","); got != tc.want {
				t.Errorf("ListItems(%q) = %q, want %q", tc.term, got, tc.want)
			}
		})
	}
}

func TestListSortAndPagination(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	const locationID = "0190a1b2-0000-7000-8000-000000000010"
	env.addLocation(t, locationID)

	for i := 1; i <= 12; i++ {
		item := createItem(t, env, ItemInput{Name: "Item " + twoDigits(i)})
		if i <= 3 {
			env.addBatch(t, "batch-"+twoDigits(i), item.ID, locationID, "2026-02-01", i)
		}
	}

	first, err := env.service.ListItems(context.Background(), ListParams{})
	if err != nil {
		t.Fatalf("ListItems page 1: %v", err)
	}
	if len(first.Items) != 10 || first.Total != 12 || first.Pages != 2 {
		t.Fatalf("page 1 = %d items, total %d, pages %d; want 10, 12, 2",
			len(first.Items), first.Total, first.Pages)
	}

	second, err := env.service.ListItems(context.Background(), ListParams{Page: 2})
	if err != nil {
		t.Fatalf("ListItems page 2: %v", err)
	}
	if got := strings.Join(itemNames(second.Items), ","); got != "Item 11,Item 12" {
		t.Errorf("page 2 = %q, want Item 11,Item 12", got)
	}

	descending, err := env.service.ListItems(context.Background(), ListParams{
		Sort: "name", Dir: "desc", PerPage: 3,
	})
	if err != nil {
		t.Fatalf("ListItems desc: %v", err)
	}
	if got := strings.Join(itemNames(descending.Items), ","); got != "Item 12,Item 11,Item 10" {
		t.Errorf("name desc = %q, want Item 12,Item 11,Item 10", got)
	}

	byQuantity, err := env.service.ListItems(context.Background(), ListParams{
		Sort: "quantity", Dir: "desc", PerPage: 3,
	})
	if err != nil {
		t.Fatalf("ListItems quantity: %v", err)
	}
	if got := strings.Join(itemNames(byQuantity.Items), ","); got != "Item 03,Item 02,Item 01" {
		t.Errorf("quantity desc = %q, want Item 03,Item 02,Item 01", got)
	}

	byExpiration, err := env.service.ListItems(context.Background(), ListParams{
		Sort: "earliest_expiration", Dir: "asc", PerPage: 3,
	})
	if err != nil {
		t.Fatalf("ListItems expiration: %v", err)
	}
	if got := strings.Join(itemNames(byExpiration.Items), ","); got != "Item 01,Item 02,Item 03" {
		t.Errorf("expiration asc = %q, want Item 01,Item 02,Item 03", got)
	}
}

func twoDigits(value int) string {
	if value < 10 {
		return "0" + string(rune('0'+value))
	}
	return string(rune('0'+value/10)) + string(rune('0'+value%10))
}

func TestTagMergeOnWrite(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	first := createItem(t, env, ItemInput{
		Name: "Milk", Tags: []string{"Dairy", "Fridge"},
	})

	var dairyID string
	if err := env.db.Reader().QueryRowContext(context.Background(),
		"SELECT id FROM tags WHERE name = ?", "dairy").Scan(&dairyID); err != nil {
		t.Fatalf("read dairy tag: %v", err)
	}

	updated, err := env.service.UpdateItem(context.Background(), first.ID, ItemPatch{
		Tags: &[]string{"dairy", "Snacks"},
	})
	if err != nil {
		t.Fatalf("UpdateItem: %v", err)
	}
	if got := strings.Join(updated.Tags, ","); got != "Dairy,Snacks" {
		t.Errorf("tags = %q, want the existing Dairy spelling and Snacks", got)
	}

	var dairyCount int
	if err := env.db.Reader().QueryRowContext(context.Background(),
		"SELECT COUNT(*) FROM tags WHERE LOWER(name) = 'dairy'").Scan(&dairyCount); err != nil {
		t.Fatalf("count dairy tags: %v", err)
	}
	if dairyCount != 1 {
		t.Errorf("dairy tag rows = %d, want 1", dairyCount)
	}

	second := createItem(t, env, ItemInput{Name: "Butter", Tags: []string{"DAIRY"}})
	var secondDairyID string
	if err := env.db.Reader().QueryRowContext(context.Background(),
		"SELECT tag_id FROM item_tags WHERE item_id = ?", second.ID).Scan(&secondDairyID); err != nil {
		t.Fatalf("read second item tag: %v", err)
	}
	if secondDairyID != dairyID {
		t.Errorf("second item reused tag %q, want %q", secondDairyID, dairyID)
	}

	tags, err := env.service.Tags(context.Background())
	if err != nil {
		t.Fatalf("Tags: %v", err)
	}
	if len(tags) != 2 {
		t.Fatalf("tags = %+v, want Dairy and Snacks", tags)
	}
	if tags[0].Name != "Dairy" || tags[0].ItemCount != 2 {
		t.Errorf("first tag = %+v, want Dairy with two items", tags[0])
	}

	if _, err := env.service.UpdateItem(context.Background(), first.ID, ItemPatch{
		Tags: &[]string{},
	}); err != nil {
		t.Fatalf("clear first tags: %v", err)
	}
	if _, err := env.service.UpdateItem(context.Background(), second.ID, ItemPatch{
		Tags: &[]string{},
	}); err != nil {
		t.Fatalf("clear second tags: %v", err)
	}

	tags, err = env.service.Tags(context.Background())
	if err != nil {
		t.Fatalf("Tags after clearing: %v", err)
	}
	if len(tags) != 0 {
		t.Errorf("tags after clearing = %+v, want none", tags)
	}
}

func TestBulkDeleteIsAtomicAndIgnoresMissingIDs(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	first := createItem(t, env, ItemInput{Name: "Milk"})
	second := createItem(t, env, ItemInput{Name: "Bread"})
	createItem(t, env, ItemInput{Name: "Butter"})

	deleted, err := env.service.BulkDelete(context.Background(),
		[]string{first.ID, second.ID, "missing", first.ID})
	if err != nil {
		t.Fatalf("BulkDelete: %v", err)
	}
	if deleted != 2 {
		t.Errorf("deleted = %d, want 2", deleted)
	}

	page, err := env.service.ListItems(context.Background(), ListParams{})
	if err != nil {
		t.Fatalf("ListItems: %v", err)
	}
	if got := strings.Join(itemNames(page.Items), ","); got != "Butter" {
		t.Errorf("remaining = %q, want Butter", got)
	}

	deleted, err = env.service.BulkDelete(context.Background(), nil)
	if err != nil || deleted != 0 {
		t.Errorf("empty bulk delete = %d, %v; want 0, nil", deleted, err)
	}
}

func TestDeleteItemCascadesBatchesAndTags(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	item := createItem(t, env, ItemInput{Name: "Milk", Tags: []string{"dairy"}})

	const locationID = "0190a1b2-0000-7000-8000-000000000010"
	env.addLocation(t, locationID)
	env.addBatch(t, "batch-1", item.ID, locationID, "2026-02-01", 2)

	if err := env.service.DeleteItem(context.Background(), item.ID); err != nil {
		t.Fatalf("DeleteItem: %v", err)
	}
	if _, err := env.service.Get(context.Background(), item.ID); !errors.Is(err, ErrItemNotFound) {
		t.Errorf("Get after delete error = %v, want ErrItemNotFound", err)
	}

	for _, table := range []string{"batches", "item_tags", "tags"} {
		var count int
		if err := env.db.Reader().QueryRowContext(context.Background(),
			"SELECT COUNT(*) FROM "+table).Scan(&count); err != nil {
			t.Fatalf("count %s: %v", table, err)
		}
		if count != 0 {
			t.Errorf("%s rows = %d, want 0 after delete", table, count)
		}
	}
}

func TestUpdateValidationLeavesTheItemAlone(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	item := createItem(t, env, ItemInput{Name: "Milk"})

	empty := " "
	_, err := env.service.UpdateItem(context.Background(), item.ID, ItemPatch{Name: &empty})
	var fieldErr *FieldError
	if !errors.As(err, &fieldErr) || fieldErr.Field != "name" || fieldErr.Rule != "required" {
		t.Fatalf("error = %v, want name:required", err)
	}

	got, err := env.service.Get(context.Background(), item.ID)
	if err != nil {
		t.Fatalf("Get: %v", err)
	}
	if got.Name != "Milk" {
		t.Errorf("name = %q, want Milk after a rejected update", got.Name)
	}
}

func TestUpdateUnknownItem(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	if _, err := env.service.UpdateItem(context.Background(), "missing", ItemPatch{}); !errors.Is(err, ErrItemNotFound) {
		t.Errorf("UpdateItem error = %v, want ErrItemNotFound", err)
	}
	if err := env.service.DeleteItem(context.Background(), "missing"); !errors.Is(err, ErrItemNotFound) {
		t.Errorf("DeleteItem error = %v, want ErrItemNotFound", err)
	}
}
