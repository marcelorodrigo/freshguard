package httpapi

import (
	"context"
	"net/http"
	"testing"

	"github.com/marcelorodrigo/freshguard/internal/inventory"
)

func (e *testEnv) insertLocation(t *testing.T, locationID, name, parentID string) {
	t.Helper()

	var parent any
	if parentID != "" {
		parent = parentID
	}

	_, err := e.db.Writer().ExecContext(context.Background(), `INSERT INTO locations
		(id, name, description, expiration_notify_days, parent_id, created_at, updated_at)
		VALUES (?, ?, NULL, 0, ?, ?, ?)`,
		locationID, name, parent, formatTimestamp(testNow), formatTimestamp(testNow))
	if err != nil {
		t.Fatalf("insert location %s: %v", name, err)
	}
}

func createBatchHTTP(t *testing.T, env *testEnv, cookie *http.Cookie, itemID string, body map[string]any) batchResponse {
	t.Helper()

	rec := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/items/" + itemID + "/batches",
		body:    body,
		cookie:  cookie,
		headers: itemOrigin,
	})
	if rec.Code != http.StatusCreated {
		t.Fatalf("POST /items/%s/batches = %d, want 201: %s", itemID, rec.Code, rec.Body.String())
	}
	return decodeBody[batchResponse](t, rec)
}

func TestBatchLifecycleAndComputedQuantity(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)
	item := createItemHTTP(t, env, cookie, map[string]any{"name": "Whole milk"})
	env.insertLocation(t, "loc-fridge", "Fridge", "")

	first := createBatchHTTP(t, env, cookie, item.ID, map[string]any{
		"location_id": "loc-fridge", "expires_at": "2026-02-10", "quantity": 2,
	})
	if first.Location.ID != "loc-fridge" || first.Location.Name != "Fridge" {
		t.Errorf("location = %+v, want the Fridge ref", first.Location)
	}
	if first.ItemID != item.ID || first.Quantity != 2 || first.ExpiresAt != "2026-02-10" {
		t.Errorf("batch = %+v, want 2 units inside the milk", first)
	}

	second := createBatchHTTP(t, env, cookie, item.ID, map[string]any{
		"location_id": "loc-fridge", "expires_at": "2026-02-01", "quantity": 3,
	})

	detail := getItemHTTP(t, env, cookie, item.ID)
	if detail.Quantity != 5 || detail.EarliestExpiration != "2026-02-01" || detail.BatchCount != 2 {
		t.Errorf("detail = quantity %d, earliest %q, batches %d; want 5, 2026-02-01, 2",
			detail.Quantity, detail.EarliestExpiration, detail.BatchCount)
	}
	if len(detail.Batches) != 2 || detail.Batches[0].ID != second.ID || detail.Batches[1].ID != first.ID {
		t.Errorf("batches = %+v, want expiry ascending", detail.Batches)
	}

	rec := env.send(t, request{
		method:  http.MethodPatch,
		path:    "/api/v1/batches/" + first.ID,
		body:    map[string]any{"quantity": 7},
		cookie:  cookie,
		headers: itemOrigin,
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("PATCH /batches = %d, want 200: %s", rec.Code, rec.Body.String())
	}
	if patched := decodeBody[batchResponse](t, rec); patched.Quantity != 7 {
		t.Errorf("patched quantity = %d, want 7", patched.Quantity)
	}

	detail = getItemHTTP(t, env, cookie, item.ID)
	if detail.Quantity != 10 {
		t.Errorf("quantity after update = %d, want 10", detail.Quantity)
	}

	rec = env.send(t, request{
		method:  http.MethodDelete,
		path:    "/api/v1/batches/" + first.ID,
		cookie:  cookie,
		headers: itemOrigin,
	})
	if rec.Code != http.StatusNoContent {
		t.Fatalf("DELETE /batches = %d, want 204: %s", rec.Code, rec.Body.String())
	}

	detail = getItemHTTP(t, env, cookie, item.ID)
	if detail.Quantity != 3 || detail.BatchCount != 1 || detail.EarliestExpiration != "2026-02-01" {
		t.Errorf("detail after delete = quantity %d, batches %d, earliest %q; want 3, 1, 2026-02-01",
			detail.Quantity, detail.BatchCount, detail.EarliestExpiration)
	}

	rec = env.send(t, request{
		method:  http.MethodDelete,
		path:    "/api/v1/batches/" + first.ID,
		cookie:  cookie,
		headers: itemOrigin,
	})
	if rec.Code != http.StatusNotFound {
		t.Fatalf("deleting a gone batch = %d, want 404", rec.Code)
	}
	if code := decodeError(t, rec).Code; code != CodeNotFound {
		t.Errorf("code = %q, want %q", code, CodeNotFound)
	}
}

func getItemHTTP(t *testing.T, env *testEnv, cookie *http.Cookie, itemID string) itemDetailResponse {
	t.Helper()

	rec := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/items/" + itemID,
		cookie: cookie,
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("GET /items/%s = %d, want 200: %s", itemID, rec.Code, rec.Body.String())
	}
	return decodeBody[itemDetailResponse](t, rec)
}

func TestBatchValidationEnvelope(t *testing.T) {
	t.Parallel()

	cases := []struct {
		name  string
		body  map[string]any
		field string
		rule  string
	}{
		{
			name:  "missing location",
			body:  map[string]any{"expires_at": "2026-02-01", "quantity": 1},
			field: "location_id",
			rule:  "required",
		},
		{
			name:  "unknown location",
			body:  map[string]any{"location_id": "loc-missing", "expires_at": "2026-02-01", "quantity": 1},
			field: "location_id",
			rule:  "exists",
		},
		{
			name:  "missing expiration",
			body:  map[string]any{"location_id": "loc-fridge", "quantity": 1},
			field: "expires_at",
			rule:  "required",
		},
		{
			name:  "malformed expiration",
			body:  map[string]any{"location_id": "loc-fridge", "expires_at": "01/02/2026", "quantity": 1},
			field: "expires_at",
			rule:  "date",
		},
		{
			name:  "zero quantity",
			body:  map[string]any{"location_id": "loc-fridge", "expires_at": "2026-02-01", "quantity": 0},
			field: "quantity",
			rule:  "min",
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			t.Parallel()

			env := newTestEnv(t)
			cookie, _ := env.setup(t)
			item := createItemHTTP(t, env, cookie, map[string]any{"name": "Milk"})
			env.insertLocation(t, "loc-fridge", "Fridge", "")

			rec := env.send(t, request{
				method:  http.MethodPost,
				path:    "/api/v1/items/" + item.ID + "/batches",
				body:    tc.body,
				cookie:  cookie,
				headers: itemOrigin,
			})
			if rec.Code != http.StatusUnprocessableEntity {
				t.Fatalf("status = %d, want 422: %s", rec.Code, rec.Body.String())
			}

			body := decodeError(t, rec)
			if body.Code != CodeInvalidInput {
				t.Errorf("code = %q, want %q", body.Code, CodeInvalidInput)
			}
			if body.Fields[tc.field] != tc.rule {
				t.Errorf("fields = %v, want %s:%s", body.Fields, tc.field, tc.rule)
			}
		})
	}
}

func TestBatchUnknownParentResources(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)
	env.insertLocation(t, "loc-fridge", "Fridge", "")

	rec := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/items/item-missing/batches",
		body:    map[string]any{"location_id": "loc-fridge", "expires_at": "2026-02-01", "quantity": 1},
		cookie:  cookie,
		headers: itemOrigin,
	})
	if rec.Code != http.StatusNotFound {
		t.Fatalf("POST on a missing item = %d, want 404: %s", rec.Code, rec.Body.String())
	}

	rec = env.send(t, request{
		method:  http.MethodPatch,
		path:    "/api/v1/batches/batch-missing",
		body:    map[string]any{"quantity": 1},
		cookie:  cookie,
		headers: itemOrigin,
	})
	if rec.Code != http.StatusNotFound {
		t.Fatalf("PATCH on a missing batch = %d, want 404: %s", rec.Code, rec.Body.String())
	}

	rec = env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/items/item-missing/batches",
		body:   map[string]any{"location_id": "loc-fridge", "expires_at": "2026-02-01", "quantity": 1},
	})
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("POST without a session = %d, want 401", rec.Code)
	}
}

func TestListLocationsHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)
	env.insertLocation(t, "loc-kitchen", "Kitchen", "")
	env.insertLocation(t, "loc-fridge", "Fridge", "loc-kitchen")

	rec := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/locations",
		cookie: cookie,
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("GET /locations = %d, want 200: %s", rec.Code, rec.Body.String())
	}

	body := decodeBody[locationsResponse](t, rec)
	if len(body.Data) != 2 {
		t.Fatalf("locations = %+v, want two", body.Data)
	}
	if body.Data[0].Name != "Fridge" || body.Data[0].Parent == nil || body.Data[0].Parent.ID != "loc-kitchen" {
		t.Errorf("first location = %+v, want Fridge inside Kitchen", body.Data[0])
	}
	if body.Data[1].Parent != nil {
		t.Errorf("kitchen parent = %+v, want null for a root location", body.Data[1].Parent)
	}
	if body.Meta.Total != 2 || body.Meta.PerPage != inventory.DefaultPerPage {
		t.Errorf("meta = %+v, want two rows on the default page", body.Meta)
	}
}
