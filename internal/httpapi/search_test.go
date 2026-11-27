package httpapi

import (
	"net/http"
	"testing"
)

type searchItemBody struct {
	ID          string `json:"id"`
	Name        string `json:"name"`
	Description string `json:"description"`
	Batches     []struct {
		ID        string      `json:"id"`
		Location  locationRef `json:"location"`
		ExpiresAt string      `json:"expires_at"`
		Quantity  int         `json:"quantity"`
	} `json:"batches"`
}

type searchBody struct {
	Data []searchItemBody `json:"data"`
}

type consumeBody struct {
	Item struct {
		ID   string `json:"id"`
		Name string `json:"name"`
	} `json:"item"`
	Batch *struct {
		ID       string `json:"id"`
		Quantity int    `json:"quantity"`
	} `json:"batch"`
	Deleted bool `json:"deleted"`
}

func searchHTTP(t *testing.T, env *testEnv, cookie *http.Cookie, term string) searchBody {
	t.Helper()

	rec := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/search?q=" + term,
		cookie: cookie,
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("GET /search?q=%s = %d, want 200: %s", term, rec.Code, rec.Body.String())
	}
	return decodeBody[searchBody](t, rec)
}

func TestSearchRequiresSession(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	rec := env.send(t, request{method: http.MethodGet, path: "/api/v1/search?q=milk"})

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("GET /search without a session = %d, want 401", rec.Code)
	}
}

func TestSearchReturnsItemsWithBatchesInTierOrder(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)
	env.insertLocation(t, "loc-fridge", "Fridge", "")

	exact := createItemHTTP(t, env, cookie, map[string]any{
		"name": "Zeta Product", "barcode": "ABC123",
	})
	prefix := createItemHTTP(t, env, cookie, map[string]any{
		"name": "Alpha Product", "barcode": "ABC123456",
	})
	batch := createBatchHTTP(t, env, cookie, exact.ID, map[string]any{
		"location_id": "loc-fridge", "expires_at": "2026-02-01", "quantity": 2,
	})
	createBatchHTTP(t, env, cookie, prefix.ID, map[string]any{
		"location_id": "loc-fridge", "expires_at": "2026-03-01", "quantity": 1,
	})

	body := searchHTTP(t, env, cookie, "ABC123")
	if len(body.Data) != 2 {
		t.Fatalf("results = %d, want 2: %+v", len(body.Data), body.Data)
	}
	if body.Data[0].ID != exact.ID || body.Data[1].ID != prefix.ID {
		t.Errorf("order = %s, %s; want the exact barcode first", body.Data[0].ID, body.Data[1].ID)
	}

	first := body.Data[0]
	if first.Name != "Zeta Product" || len(first.Batches) != 1 {
		t.Fatalf("first result = %+v, want the name and its batch", first)
	}
	got := first.Batches[0]
	if got.ID != batch.ID || got.Location.ID != "loc-fridge" || got.Location.Name != "Fridge" {
		t.Errorf("batch = %+v, want the Fridge batch", got)
	}
	if got.ExpiresAt != "2026-02-01" || got.Quantity != 2 {
		t.Errorf("batch = %+v, want 2 units expiring 2026-02-01", got)
	}
}

func TestSearchShortTermReturnsAnEmptyList(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	for _, term := range []string{"m", "%20", ""} {
		body := searchHTTP(t, env, cookie, term)
		if len(body.Data) != 0 {
			t.Errorf("search(%q) = %+v, want no data", term, body.Data)
		}
	}
}

func TestConsumeRequiresSession(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	rec := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/batches/batch-1/consume",
		headers: itemOrigin,
	})

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("POST /consume without a session = %d, want 401", rec.Code)
	}
}

func TestConsumeDecrementsThenDeletesTheBatch(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)
	item, batch := milkWithOneBatch(t, env, cookie)

	rec := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/batches/" + batch.ID + "/consume",
		cookie:  cookie,
		headers: itemOrigin,
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("consume = %d, want 200: %s", rec.Code, rec.Body.String())
	}

	body := decodeBody[consumeBody](t, rec)
	if body.Item.ID != item.ID || body.Item.Name != "Whole milk" {
		t.Errorf("item = %+v, want the milk", body.Item)
	}
	if body.Deleted || body.Batch == nil || body.Batch.ID != batch.ID || body.Batch.Quantity != 1 {
		t.Errorf("result = %+v, want the batch kept at 1", body)
	}

	rec = env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/batches/" + batch.ID + "/consume",
		cookie:  cookie,
		headers: itemOrigin,
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("second consume = %d, want 200: %s", rec.Code, rec.Body.String())
	}

	body = decodeBody[consumeBody](t, rec)
	if !body.Deleted || body.Batch != nil {
		t.Errorf("result = %+v, want the batch deleted and null", body)
	}

	detail := getItemHTTP(t, env, cookie, item.ID)
	if detail.Quantity != 0 || detail.BatchCount != 0 {
		t.Errorf("item after consuming both units = %d/%d, want 0/0", detail.Quantity, detail.BatchCount)
	}
}

func TestConsumeMissingBatchIsConflict(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	rec := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/batches/missing/consume",
		cookie:  cookie,
		headers: itemOrigin,
	})
	if rec.Code != http.StatusConflict {
		t.Fatalf("consume a missing batch = %d, want 409: %s", rec.Code, rec.Body.String())
	}
	if code := decodeError(t, rec).Code; code != CodeBatchUnavailable {
		t.Errorf("code = %q, want %q", code, CodeBatchUnavailable)
	}
}
