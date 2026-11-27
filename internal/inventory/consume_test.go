package inventory

import (
	"context"
	"errors"
	"testing"
)

// milkWithBatch creates a milk item and a batch with the given quantity,
// expiring on 2026-02-01, in the Fridge.
func milkWithBatch(t *testing.T, env *testEnv, batchID string, quantity int) (Item, Batch) {
	t.Helper()

	item := createItem(t, env, ItemInput{Name: "Whole milk"})
	env.addLocation(t, "loc-fridge")
	env.addBatch(t, batchID, item.ID, "loc-fridge", "2026-02-01", quantity)

	return item, Batch{ID: batchID, ItemID: item.ID, LocationID: "loc-fridge", Quantity: quantity}
}

func TestConsumeDecrementsTheBatch(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	item, batch := milkWithBatch(t, env, "batch-1", 2)

	result, err := env.service.ConsumeBatch(context.Background(), batch.ID)
	if err != nil {
		t.Fatalf("ConsumeBatch: %v", err)
	}

	if result.ItemID != item.ID || result.ItemName != "Whole milk" {
		t.Errorf("result item = %q/%q, want the milk", result.ItemID, result.ItemName)
	}
	if result.Deleted || result.BatchQuantity != 1 {
		t.Errorf("result = %+v, want one unit left and the batch kept", result)
	}

	got, err := env.service.Get(context.Background(), item.ID)
	if err != nil {
		t.Fatalf("Get: %v", err)
	}
	if got.Quantity != 1 || got.BatchCount != 1 {
		t.Errorf("item quantity/batches = %d/%d, want 1/1", got.Quantity, got.BatchCount)
	}
	if len(got.Batches) != 1 || got.Batches[0].Quantity != 1 {
		t.Errorf("batches = %+v, want the batch at 1", got.Batches)
	}
}

func TestConsumeDeletesTheLastUnit(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	item, batch := milkWithBatch(t, env, "batch-1", 1)

	result, err := env.service.ConsumeBatch(context.Background(), batch.ID)
	if err != nil {
		t.Fatalf("ConsumeBatch: %v", err)
	}
	if !result.Deleted || result.BatchQuantity != 0 {
		t.Errorf("result = %+v, want the batch deleted", result)
	}

	if _, err := env.service.batch(context.Background(), batch.ID); !errors.Is(err, ErrBatchNotFound) {
		t.Errorf("batch error = %v, want ErrBatchNotFound", err)
	}

	got, err := env.service.Get(context.Background(), item.ID)
	if err != nil {
		t.Fatalf("Get: %v", err)
	}
	if got.Quantity != 0 || got.BatchCount != 0 || got.EarliestExpiration != "" {
		t.Errorf("item = quantity %d, batches %d, earliest %q; want 0/0/empty",
			got.Quantity, got.BatchCount, got.EarliestExpiration)
	}
}

func TestConsumeUnknownBatchIsUnavailable(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)

	if _, err := env.service.ConsumeBatch(context.Background(), "missing"); !errors.Is(err, ErrBatchUnavailable) {
		t.Errorf("error = %v, want ErrBatchUnavailable", err)
	}
}

func TestConsumeTwiceOnTheLastUnitIsUnavailable(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	_, batch := milkWithBatch(t, env, "batch-1", 1)

	if _, err := env.service.ConsumeBatch(context.Background(), batch.ID); err != nil {
		t.Fatalf("first ConsumeBatch: %v", err)
	}
	if _, err := env.service.ConsumeBatch(context.Background(), batch.ID); !errors.Is(err, ErrBatchUnavailable) {
		t.Errorf("second error = %v, want ErrBatchUnavailable", err)
	}
}

func TestConsumeKeepsOtherBatches(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	item, batch := milkWithBatch(t, env, "batch-1", 1)
	env.addBatch(t, "batch-2", item.ID, "loc-fridge", "2026-03-01", 4)

	if _, err := env.service.ConsumeBatch(context.Background(), batch.ID); err != nil {
		t.Fatalf("ConsumeBatch: %v", err)
	}

	got, err := env.service.Get(context.Background(), item.ID)
	if err != nil {
		t.Fatalf("Get: %v", err)
	}
	if got.Quantity != 4 || got.BatchCount != 1 || got.EarliestExpiration != "2026-03-01" {
		t.Errorf("item = quantity %d, batches %d, earliest %q; want 4/1/2026-03-01",
			got.Quantity, got.BatchCount, got.EarliestExpiration)
	}
}
