package inventory

import (
	"context"
	"fmt"
	"testing"
)

// stockedItem creates an item and gives it a batch so search can return it.
func stockedItem(t *testing.T, env *testEnv, batchID string, input ItemInput) Item {
	t.Helper()

	item := createItem(t, env, input)
	env.addBatch(t, batchID, item.ID, "loc-fridge", "2026-02-01", 1)
	return item
}

// searchedIDs runs the search and returns the item ids in tier order.
func searchedIDs(t *testing.T, env *testEnv, term string) []string {
	t.Helper()

	results, err := env.service.Search(context.Background(), term)
	if err != nil {
		t.Fatalf("Search(%q): %v", term, err)
	}

	ids := make([]string, len(results))
	for i, result := range results {
		ids[i] = result.ID
	}
	return ids
}

func sameIDs(got, want []string) bool {
	if len(got) != len(want) {
		return false
	}
	for i := range got {
		if got[i] != want[i] {
			return false
		}
	}
	return true
}

func TestSearchRanksTheFiveTiers(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addLocation(t, "loc-fridge")

	exact := stockedItem(t, env, "batch-1", ItemInput{Name: "Zeta Product", Barcode: "ABC123"})
	barcodePrefix := stockedItem(t, env, "batch-2", ItemInput{Name: "Alpha Product", Barcode: "ABC123456"})
	namePrefix := stockedItem(t, env, "batch-3", ItemInput{Name: "ABC123 Product"})
	descriptionPrefix := stockedItem(t, env, "batch-4", ItemInput{
		Name: "Something Else", Description: "ABC123 inside",
	})
	substring := stockedItem(t, env, "batch-5", ItemInput{Name: "Contains ABC123"})

	got := searchedIDs(t, env, "ABC123")
	want := []string{exact.ID, barcodePrefix.ID, namePrefix.ID, descriptionPrefix.ID, substring.ID}

	if !sameIDs(got, want) {
		t.Errorf("search order = %v, want %v", got, want)
	}
}

func TestSearchOrdersWithinATierByNameThenID(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addLocation(t, "loc-fridge")

	second := stockedItem(t, env, "batch-1", ItemInput{Name: "Milk B"})
	first := stockedItem(t, env, "batch-2", ItemInput{Name: "Milk A"})

	got := searchedIDs(t, env, "Milk")
	want := []string{first.ID, second.ID}

	if !sameIDs(got, want) {
		t.Errorf("search order = %v, want %v", got, want)
	}
}

func TestSearchReturnsAnItemOnceAcrossTiers(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addLocation(t, "loc-fridge")

	item := stockedItem(t, env, "batch-1", ItemInput{
		Name: "Milk Chocolate", Barcode: "MILK123", Description: "Milk solids",
	})

	got := searchedIDs(t, env, "Milk")
	if !sameIDs(got, []string{item.ID}) {
		t.Errorf("search order = %v, want the item once", got)
	}
}

func TestSearchSkipsSubstringFallbackWhenPrefixesFillTheLimit(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addLocation(t, "loc-fridge")

	for index := 1; index <= SearchLimit; index++ {
		stockedItem(t, env, "batch-"+fmt.Sprintf("%02d", index), ItemInput{
			Name: "Alpha Product " + fmt.Sprintf("%02d", index),
		})
	}
	substring := stockedItem(t, env, "batch-substring", ItemInput{Name: "XAlpha Product"})

	results, err := env.service.Search(context.Background(), "Alpha")
	if err != nil {
		t.Fatalf("Search: %v", err)
	}
	if len(results) != SearchLimit {
		t.Fatalf("results = %d, want %d", len(results), SearchLimit)
	}
	for _, result := range results {
		if result.ID == substring.ID {
			t.Error("the substring fallback contributed a result while the prefix tiers were full")
		}
	}
}

func TestSearchReturnsADeterministicPage(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addLocation(t, "loc-fridge")

	for index := 1; index <= 15; index++ {
		stockedItem(t, env, "batch-"+fmt.Sprintf("%02d", index), ItemInput{
			Name: "Test Product " + fmt.Sprintf("%02d", index),
		})
	}

	first, err := env.service.Search(context.Background(), "Test Product")
	if err != nil {
		t.Fatalf("Search: %v", err)
	}
	second, err := env.service.Search(context.Background(), "Test Product")
	if err != nil {
		t.Fatalf("Search again: %v", err)
	}

	if len(first) != SearchLimit {
		t.Fatalf("results = %d, want %d", len(first), SearchLimit)
	}
	if len(second) != len(first) || first[0].ID != second[0].ID || first[SearchLimit-1].ID != second[SearchLimit-1].ID {
		t.Errorf("two identical searches disagree: %v vs %v", first, second)
	}
	if first[0].Name != "Test Product 01" {
		t.Errorf("first result = %q, want Test Product 01", first[0].Name)
	}
}

func TestSearchExcludesItemsWithoutStock(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addLocation(t, "loc-fridge")

	stocked := stockedItem(t, env, "batch-1", ItemInput{Name: "Milk"})
	createItem(t, env, ItemInput{Name: "Milk Powder"})

	got := searchedIDs(t, env, "Milk")
	if !sameIDs(got, []string{stocked.ID}) {
		t.Errorf("search order = %v, want only the stocked item", got)
	}
}

func TestSearchRequiresTwoTrimmedCharacters(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addLocation(t, "loc-fridge")
	item := stockedItem(t, env, "batch-1", ItemInput{Name: "Milk"})

	for _, term := range []string{"", "m", "  m  ", "\u00e9"} {
		if got := searchedIDs(t, env, term); len(got) != 0 {
			t.Errorf("search(%q) = %v, want no results", term, got)
		}
	}

	if got := searchedIDs(t, env, "  Milk  "); !sameIDs(got, []string{item.ID}) {
		t.Errorf("search(\"  Milk  \") = %v, want the trimmed match", got)
	}
}

func TestSearchMatchesASCIIcaseInsensitively(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addLocation(t, "loc-fridge")
	item := stockedItem(t, env, "batch-1", ItemInput{Name: "Milk Chocolate"})

	if got := searchedIDs(t, env, "milk"); !sameIDs(got, []string{item.ID}) {
		t.Errorf("search(\"milk\") = %v, want the Milk item", got)
	}
}

func TestSearchTreatsWildcardsLiterally(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addLocation(t, "loc-fridge")

	underscore := stockedItem(t, env, "batch-1", ItemInput{Name: "Item_One"})
	stockedItem(t, env, "batch-2", ItemInput{Name: "ItemXOne"})
	percent := stockedItem(t, env, "batch-3", ItemInput{Name: "100% Juice"})
	stockedItem(t, env, "batch-4", ItemInput{Name: "1000 Juice"})
	backslash := stockedItem(t, env, "batch-5", ItemInput{Name: `a\b`})
	stockedItem(t, env, "batch-6", ItemInput{Name: "aXb"})

	cases := []struct {
		term string
		want string
	}{
		{term: "Item_", want: underscore.ID},
		{term: "100%", want: percent.ID},
		{term: `a\b`, want: backslash.ID},
	}

	for _, tc := range cases {
		if got := searchedIDs(t, env, tc.term); !sameIDs(got, []string{tc.want}) {
			t.Errorf("search(%q) = %v, want only %s", tc.term, got, tc.want)
		}
	}
}

func TestSearchReturnsBatchesWithStockOrderedByExpiry(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	env.addLocation(t, "loc-fridge")

	item := createItem(t, env, ItemInput{Name: "Milk"})
	env.addBatch(t, "batch-late", item.ID, "loc-fridge", "2026-03-01", 2)
	env.addBatch(t, "batch-early", item.ID, "loc-fridge", "2026-02-01", 1)

	results, err := env.service.Search(context.Background(), "Milk")
	if err != nil {
		t.Fatalf("Search: %v", err)
	}
	if len(results) != 1 {
		t.Fatalf("results = %d, want 1", len(results))
	}

	batches := results[0].Batches
	if len(batches) != 2 {
		t.Fatalf("batches = %d, want 2", len(batches))
	}
	if batches[0].ID != "batch-early" || batches[1].ID != "batch-late" {
		t.Errorf("batches are not ordered by expiry: %+v", batches)
	}
	if batches[0].LocationName != "Fridge" || batches[0].Quantity != 1 {
		t.Errorf("batch = %+v, want the Fridge batch of 1", batches[0])
	}
}
