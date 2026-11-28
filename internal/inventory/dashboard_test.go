package inventory

import (
	"context"
	"testing"
)

func addBatchForItem(t *testing.T, env *testEnv, name, expiresAt string, quantity int) {
	t.Helper()

	item := createItem(t, env, ItemInput{Name: name})
	if _, err := env.service.CreateBatch(context.Background(), item.ID, BatchInput{
		LocationID: "loc-fridge", ExpiresAt: expiresAt, Quantity: quantity,
	}); err != nil {
		t.Fatalf("CreateBatch(%s): %v", name, err)
	}
}

func TestRecentlyExpiredOrdersAndCaps(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addNamedLocation(t, "loc-fridge", "Fridge", "")

	// Six expired batches, plus today and the future. The endpoint keeps the
	// five oldest and ignores everything from today onwards.
	addBatchForItem(t, env, "eighteen", "2026-01-18", 1)
	addBatchForItem(t, env, "nineteen", "2026-01-19", 2)
	addBatchForItem(t, env, "twenty", "2026-01-20", 3)
	addBatchForItem(t, env, "twenty-one", "2026-01-21", 4)
	addBatchForItem(t, env, "twenty-two", "2026-01-22", 5)
	addBatchForItem(t, env, "twenty-three", "2026-01-23", 6)
	addBatchForItem(t, env, "today", "2026-01-24", 7)
	addBatchForItem(t, env, "tomorrow", "2026-01-25", 8)

	got, err := env.service.RecentlyExpired(context.Background())
	if err != nil {
		t.Fatalf("RecentlyExpired: %v", err)
	}
	if len(got) != 5 {
		t.Fatalf("rows = %d, want the five oldest", len(got))
	}

	wantDates := []string{"2026-01-18", "2026-01-19", "2026-01-20", "2026-01-21", "2026-01-22"}
	for i, want := range wantDates {
		if got[i].ExpiresAt != want {
			t.Errorf("row %d expires %s, want %s (ascending)", i, got[i].ExpiresAt, want)
		}
	}
	if got[0].ItemName != "eighteen" || got[0].LocationName != "Fridge" {
		t.Errorf("first row = %+v, want its item and location names", got[0])
	}
	if got[4].Quantity != 5 {
		t.Errorf("quantity = %d, want the batch quantity", got[4].Quantity)
	}
	if got[0].ItemID == "" || got[0].ID == "" {
		t.Error("the row is missing its item or batch id")
	}
}

func TestRecentlyExpiredEmpty(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addNamedLocation(t, "loc-fridge", "Fridge", "")

	got, err := env.service.RecentlyExpired(context.Background())
	if err != nil {
		t.Fatalf("RecentlyExpired: %v", err)
	}
	if len(got) != 0 {
		t.Errorf("rows = %+v, want none", got)
	}

	addBatchForItem(t, env, "today", "2026-01-24", 1)
	got, err = env.service.RecentlyExpired(context.Background())
	if err != nil {
		t.Fatalf("RecentlyExpired with today's batch: %v", err)
	}
	if len(got) != 0 {
		t.Errorf("rows = %+v, want today excluded", got)
	}

	addBatchForItem(t, env, "yesterday", "2026-01-23", 1)
	got, err = env.service.RecentlyExpired(context.Background())
	if err != nil {
		t.Fatalf("RecentlyExpired with yesterday's batch: %v", err)
	}
	if len(got) != 1 || got[0].ItemName != "yesterday" {
		t.Errorf("rows = %+v, want only yesterday", got)
	}
}
