package httpapi

import (
	"net/http"
	"strings"
	"testing"
)

var itemOrigin = map[string]string{"Origin": "http://example.com"}

func createItemHTTP(t *testing.T, env *testEnv, cookie *http.Cookie, body map[string]any) itemDetailResponse {
	t.Helper()

	rec := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/items",
		body:    body,
		cookie:  cookie,
		headers: itemOrigin,
	})
	if rec.Code != http.StatusCreated {
		t.Fatalf("POST /items = %d, want 201: %s", rec.Code, rec.Body.String())
	}
	return decodeBody[itemDetailResponse](t, rec)
}

func TestItemsRequireSession(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	rec := env.send(t, request{method: http.MethodGet, path: "/api/v1/items"})

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("GET /items without a session = %d, want 401", rec.Code)
	}
	if code := decodeError(t, rec).Code; code != CodeUnauthorized {
		t.Errorf("code = %q, want %q", code, CodeUnauthorized)
	}
}

func TestItemEndpoints(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	created := createItemHTTP(t, env, cookie, map[string]any{
		"name":        " Whole milk ",
		"barcode":     "7891000100103",
		"description": "1 L",
		"tags":        []string{"Dairy", "dairy", "Fridge"},
	})

	if created.Name != "Whole milk" {
		t.Errorf("name = %q, want trimmed", created.Name)
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
	if created.Batches == nil || len(created.Batches) != 0 {
		t.Errorf("batches = %v, want an empty array", created.Batches)
	}

	list := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/items?q=milk&sort=quantity&dir=desc&page=1&per_page=10",
		cookie: cookie,
	})
	if list.Code != http.StatusOK {
		t.Fatalf("GET /items = %d, want 200: %s", list.Code, list.Body.String())
	}
	listBody := decodeBody[itemsResponse](t, list)
	if len(listBody.Data) != 1 || listBody.Data[0].ID != created.ID {
		t.Fatalf("list = %+v, want the created item", listBody.Data)
	}
	if listBody.Meta.Total != 1 || listBody.Meta.Pages != 1 || listBody.Meta.PerPage != 10 {
		t.Errorf("meta = %+v, want one page of ten", listBody.Meta)
	}

	tags := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/tags",
		cookie: cookie,
	})
	if tags.Code != http.StatusOK {
		t.Fatalf("GET /tags = %d, want 200: %s", tags.Code, tags.Body.String())
	}
	tagsBody := decodeBody[tagsResponse](t, tags)
	if len(tagsBody.Data) != 2 || tagsBody.Data[0].Name != "Dairy" {
		t.Errorf("tags = %+v, want Dairy and Fridge", tagsBody.Data)
	}
	if tagsBody.Data[0].ItemCount != 1 {
		t.Errorf("Dairy item_count = %d, want 1", tagsBody.Data[0].ItemCount)
	}

	renamed := "Whole milk 3%"
	patch := env.send(t, request{
		method: http.MethodPatch,
		path:   "/api/v1/items/" + created.ID,
		body: map[string]any{
			"name": renamed,
			"tags": []string{"fridge"},
		},
		cookie:  cookie,
		headers: itemOrigin,
	})
	if patch.Code != http.StatusOK {
		t.Fatalf("PATCH /items/{id} = %d, want 200: %s", patch.Code, patch.Body.String())
	}
	patched := decodeBody[itemDetailResponse](t, patch)
	if patched.Name != renamed || strings.Join(patched.Tags, ",") != "Fridge" {
		t.Errorf("patched = %+v, want the new name and Fridge", patched)
	}

	detail := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/items/" + created.ID,
		cookie: cookie,
	})
	if detail.Code != http.StatusOK {
		t.Fatalf("GET /items/{id} = %d, want 200: %s", detail.Code, detail.Body.String())
	}

	deleted := env.send(t, request{
		method:  http.MethodDelete,
		path:    "/api/v1/items/" + created.ID,
		cookie:  cookie,
		headers: itemOrigin,
	})
	if deleted.Code != http.StatusNoContent {
		t.Fatalf("DELETE /items/{id} = %d, want 204: %s", deleted.Code, deleted.Body.String())
	}

	missing := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/items/" + created.ID,
		cookie: cookie,
	})
	if missing.Code != http.StatusNotFound {
		t.Fatalf("GET deleted item = %d, want 404", missing.Code)
	}
	if code := decodeError(t, missing).Code; code != CodeNotFound {
		t.Errorf("code = %q, want %q", code, CodeNotFound)
	}
}

func TestItemValidationEnvelope(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	rec := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/items",
		body:    map[string]any{"name": "  "},
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
	if body.Fields["name"] != "required" {
		t.Errorf("fields = %+v, want name:required", body.Fields)
	}
}

func TestItemsPaginationAndSearch(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	for i := 1; i <= 12; i++ {
		createItemHTTP(t, env, cookie, map[string]any{
			"name":        "Item " + twoDigitString(i),
			"description": "shelf " + twoDigitString(i),
			"tags":        []string{"pantry"},
		})
	}

	second := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/items?page=2",
		cookie: cookie,
	})
	if second.Code != http.StatusOK {
		t.Fatalf("GET page 2 = %d, want 200: %s", second.Code, second.Body.String())
	}
	page := decodeBody[itemsResponse](t, second)
	if len(page.Data) != 2 || page.Meta.Page != 2 || page.Meta.Pages != 2 {
		t.Errorf("page 2 = %d rows, meta %+v; want 2 rows and the second of two pages",
			len(page.Data), page.Meta)
	}

	search := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/items?q=shelf%2003",
		cookie: cookie,
	})
	if search.Code != http.StatusOK {
		t.Fatalf("GET search = %d, want 200: %s", search.Code, search.Body.String())
	}
	found := decodeBody[itemsResponse](t, search)
	if len(found.Data) != 1 || found.Data[0].Name != "Item 03" {
		t.Errorf("search = %+v, want Item 03", found.Data)
	}
}

func TestBulkDeleteItemsHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	first := createItemHTTP(t, env, cookie, map[string]any{"name": "Milk"})
	second := createItemHTTP(t, env, cookie, map[string]any{"name": "Bread"})
	createItemHTTP(t, env, cookie, map[string]any{"name": "Butter"})

	rec := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/items/bulk-delete",
		body: map[string]any{
			"ids": []string{first.ID, second.ID, "missing"},
		},
		cookie:  cookie,
		headers: itemOrigin,
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("bulk delete = %d, want 200: %s", rec.Code, rec.Body.String())
	}
	body := decodeBody[map[string]int](t, rec)
	if body["deleted"] != 2 {
		t.Errorf("deleted = %d, want 2", body["deleted"])
	}

	list := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/items",
		cookie: cookie,
	})
	remaining := decodeBody[itemsResponse](t, list)
	if len(remaining.Data) != 1 || remaining.Data[0].Name != "Butter" {
		t.Errorf("remaining = %+v, want Butter", remaining.Data)
	}
}

func twoDigitString(value int) string {
	return string([]rune{rune('0' + value/10), rune('0' + value%10)})
}
