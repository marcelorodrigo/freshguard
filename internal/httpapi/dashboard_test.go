package httpapi

import (
	"net/http"
	"testing"

	"github.com/marcelorodrigo/freshguard/internal/inventory"
)

type expiredBatchBody struct {
	ID        string      `json:"id"`
	ItemID    string      `json:"item_id"`
	Item      itemRefBody `json:"item"`
	Location  itemRefBody `json:"location"`
	ExpiresAt string      `json:"expires_at"`
	Quantity  int         `json:"quantity"`
}

type itemRefBody struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

type expiredBatchesBody struct {
	Data []expiredBatchBody `json:"data"`
}

func seedExpiredBatches(t *testing.T, env *testEnv) inventory.Location {
	t.Helper()

	ctx := t.Context()
	location, err := env.inventory.CreateLocation(ctx, inventory.LocationInput{Name: "Fridge"})
	if err != nil {
		t.Fatalf("CreateLocation: %v", err)
	}

	dates := []string{
		"2026-01-18", "2026-01-19", "2026-01-20", "2026-01-21",
		"2026-01-22", "2026-01-23", "2026-01-24", "2026-01-25",
	}
	for i, date := range dates {
		item, err := env.inventory.CreateItem(ctx, inventory.ItemInput{Name: "Item " + date})
		if err != nil {
			t.Fatalf("CreateItem(%s): %v", date, err)
		}
		if _, err := env.inventory.CreateBatch(ctx, item.ID, inventory.BatchInput{
			LocationID: location.ID, ExpiresAt: date, Quantity: i + 1,
		}); err != nil {
			t.Fatalf("CreateBatch(%s): %v", date, err)
		}
	}
	return location
}

func TestRecentlyExpiredHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)
	location := seedExpiredBatches(t, env)

	rec := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/dashboard/recently-expired", cookie: cookie,
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200: %s", rec.Code, rec.Body.String())
	}

	body := decodeBody[expiredBatchesBody](t, rec)
	if len(body.Data) != 5 {
		t.Fatalf("rows = %d, want the five oldest", len(body.Data))
	}
	if body.Data[0].ExpiresAt != "2026-01-18" || body.Data[4].ExpiresAt != "2026-01-22" {
		t.Errorf("dates = %s..%s, want 2026-01-18..2026-01-22",
			body.Data[0].ExpiresAt, body.Data[4].ExpiresAt)
	}
	if body.Data[0].Item.Name != "Item 2026-01-18" || body.Data[0].Item.ID == "" {
		t.Errorf("item = %+v, want the joined item", body.Data[0].Item)
	}
	if body.Data[0].Location.Name != "Fridge" || body.Data[0].Location.ID != location.ID {
		t.Errorf("location = %+v, want the joined location", body.Data[0].Location)
	}
	if body.Data[0].Quantity != 1 || body.Data[0].ItemID == "" || body.Data[0].ID == "" {
		t.Errorf("row = %+v, want its quantity and ids", body.Data[0])
	}
}

func TestRecentlyExpiredHTTPRequiresSession(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	rec := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/dashboard/recently-expired",
	})
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("status = %d, want 401", rec.Code)
	}
}

func TestRecentlyExpiredHTTPEmptyIsAnArray(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	rec := env.send(t, request{
		method: http.MethodGet, path: "/api/v1/dashboard/recently-expired", cookie: cookie,
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", rec.Code)
	}
	if body := rec.Body.String(); body != `{"data":[]}` {
		t.Errorf("body = %s, want an empty data array", body)
	}
}
