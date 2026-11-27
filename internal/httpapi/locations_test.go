package httpapi

import (
	"net/http"
	"strings"
	"testing"
)

func createLocationHTTP(t *testing.T, env *testEnv, cookie *http.Cookie, body map[string]any) locationResponse {
	t.Helper()

	rec := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/locations",
		body:    body,
		cookie:  cookie,
		headers: itemOrigin,
	})
	if rec.Code != http.StatusCreated {
		t.Fatalf("POST /locations = %d, want 201: %s", rec.Code, rec.Body.String())
	}
	return decodeBody[locationResponse](t, rec)
}

func listLocationsHTTP(t *testing.T, env *testEnv, cookie *http.Cookie) locationsResponse {
	t.Helper()

	rec := env.send(t, request{
		method: http.MethodGet,
		path:   "/api/v1/locations",
		cookie: cookie,
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("GET /locations = %d, want 200: %s", rec.Code, rec.Body.String())
	}
	return decodeBody[locationsResponse](t, rec)
}

func TestCreateLocationHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	root := createLocationHTTP(t, env, cookie, map[string]any{
		"name":                   " Kitchen ",
		"description":            " Main shelf ",
		"expiration_notify_days": 3,
		"parent_id":              nil,
	})
	if root.Name != "Kitchen" || root.Description != "Main shelf" || root.ExpirationNotifyDays != 3 {
		t.Errorf("root = %+v, want the trimmed values", root)
	}
	if root.Parent != nil {
		t.Errorf("root parent = %+v, want null", root.Parent)
	}

	child := createLocationHTTP(t, env, cookie, map[string]any{
		"name":      "Fridge",
		"parent_id": root.ID,
	})
	if child.Parent == nil || child.Parent.ID != root.ID || child.Parent.Name != "Kitchen" {
		t.Errorf("child parent = %+v, want the kitchen ref", child.Parent)
	}

	unauthorized := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/locations",
		body:   map[string]any{"name": "Fridge"},
	})
	if unauthorized.Code != http.StatusUnauthorized {
		t.Errorf("POST /locations without a session = %d, want 401", unauthorized.Code)
	}
}

func TestUpdateAndDeleteLocationHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)
	root := createLocationHTTP(t, env, cookie, map[string]any{"name": "Kitchen"})
	child := createLocationHTTP(t, env, cookie, map[string]any{
		"name":      "Fridge",
		"parent_id": root.ID,
	})

	patch := env.send(t, request{
		method: http.MethodPatch,
		path:   "/api/v1/locations/" + child.ID,
		body: map[string]any{
			"name":      "Fridge door",
			"parent_id": nil,
		},
		cookie:  cookie,
		headers: itemOrigin,
	})
	if patch.Code != http.StatusOK {
		t.Fatalf("PATCH /locations/{id} = %d, want 200: %s", patch.Code, patch.Body.String())
	}
	patched := decodeBody[locationResponse](t, patch)
	if patched.Name != "Fridge door" || patched.Parent != nil {
		t.Errorf("patched = %+v, want the new name and no parent", patched)
	}

	deleted := env.send(t, request{
		method:  http.MethodDelete,
		path:    "/api/v1/locations/" + child.ID,
		cookie:  cookie,
		headers: itemOrigin,
	})
	if deleted.Code != http.StatusNoContent {
		t.Fatalf("DELETE /locations/{id} = %d, want 204: %s", deleted.Code, deleted.Body.String())
	}

	list := listLocationsHTTP(t, env, cookie)
	if len(list.Data) != 1 || list.Data[0].ID != root.ID {
		t.Errorf("locations = %+v, want only the root", list.Data)
	}
}

func TestUnknownLocationHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	for _, method := range []string{http.MethodPatch, http.MethodDelete} {
		missing := env.send(t, request{
			method:  method,
			path:    "/api/v1/locations/loc-missing",
			body:    map[string]any{"name": "Missing"},
			cookie:  cookie,
			headers: itemOrigin,
		})
		if missing.Code != http.StatusNotFound {
			t.Errorf("%s missing location = %d, want 404", method, missing.Code)
			continue
		}
		if code := decodeError(t, missing).Code; code != CodeNotFound {
			t.Errorf("%s code = %q, want %q", method, code, CodeNotFound)
		}
	}
}

func TestLocationValidationEnvelope(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)

	blank := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/locations",
		body:    map[string]any{"name": "  "},
		cookie:  cookie,
		headers: itemOrigin,
	})
	if blank.Code != http.StatusUnprocessableEntity {
		t.Fatalf("blank name = %d, want 422: %s", blank.Code, blank.Body.String())
	}
	if body := decodeError(t, blank); body.Fields["name"] != "required" {
		t.Errorf("fields = %+v, want name:required", body.Fields)
	}

	unknownParent := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/locations",
		body: map[string]any{
			"name":      "Fridge",
			"parent_id": "loc-missing",
		},
		cookie:  cookie,
		headers: itemOrigin,
	})
	if unknownParent.Code != http.StatusUnprocessableEntity {
		t.Fatalf("unknown parent = %d, want 422: %s", unknownParent.Code, unknownParent.Body.String())
	}
	if body := decodeError(t, unknownParent); body.Fields["parent_id"] != "exists" {
		t.Errorf("fields = %+v, want parent_id:exists", body.Fields)
	}

	root := createLocationHTTP(t, env, cookie, map[string]any{"name": "Kitchen"})
	child := createLocationHTTP(t, env, cookie, map[string]any{
		"name":      "Fridge",
		"parent_id": root.ID,
	})

	cycle := env.send(t, request{
		method:  http.MethodPatch,
		path:    "/api/v1/locations/" + root.ID,
		body:    map[string]any{"parent_id": child.ID},
		cookie:  cookie,
		headers: itemOrigin,
	})
	if cycle.Code != http.StatusUnprocessableEntity {
		t.Fatalf("cycle = %d, want 422: %s", cycle.Code, cycle.Body.String())
	}
	body := decodeError(t, cycle)
	if body.Code != CodeInvalidInput || body.Fields["parent_id"] != "cycle" {
		t.Errorf("cycle body = %+v, want invalid_input parent_id:cycle", body)
	}
}

func TestLocationDeleteBlockedByBatchesHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)
	milkWithOneBatch(t, env, cookie)

	blocked := env.send(t, request{
		method:  http.MethodDelete,
		path:    "/api/v1/locations/loc-fridge",
		cookie:  cookie,
		headers: itemOrigin,
	})
	if blocked.Code != http.StatusConflict {
		t.Fatalf("DELETE populated location = %d, want 409: %s", blocked.Code, blocked.Body.String())
	}

	body := decodeError(t, blocked)
	if body.Code != CodeLocationHasBatches {
		t.Errorf("code = %q, want %q", body.Code, CodeLocationHasBatches)
	}
	if body.Fields["ids"] != "loc-fridge" {
		t.Errorf("fields = %+v, want ids:loc-fridge", body.Fields)
	}

	list := listLocationsHTTP(t, env, cookie)
	if len(list.Data) != 1 || list.Data[0].BatchCount != 1 {
		t.Errorf("locations = %+v, want the fridge with its batch", list.Data)
	}
}

func TestBulkDeleteLocationsHTTP(t *testing.T) {
	t.Parallel()

	env := newTestEnv(t)
	cookie, _ := env.setup(t)
	empty := createLocationHTTP(t, env, cookie, map[string]any{"name": "Empty"})
	populated := createLocationHTTP(t, env, cookie, map[string]any{"name": "Fridge"})

	item := createItemHTTP(t, env, cookie, map[string]any{"name": "Milk"})
	createBatchHTTP(t, env, cookie, item.ID, map[string]any{
		"location_id": populated.ID, "expires_at": "2026-02-10", "quantity": 2,
	})

	blocked := env.send(t, request{
		method:  http.MethodPost,
		path:    "/api/v1/locations/bulk-delete",
		body:    map[string]any{"ids": []string{empty.ID, populated.ID}},
		cookie:  cookie,
		headers: itemOrigin,
	})
	if blocked.Code != http.StatusConflict {
		t.Fatalf("bulk delete = %d, want 409: %s", blocked.Code, blocked.Body.String())
	}
	body := decodeError(t, blocked)
	if body.Code != CodeLocationHasBatches || body.Fields["ids"] != populated.ID {
		t.Errorf("body = %+v, want location_has_batches ids:%s", body, populated.ID)
	}

	list := listLocationsHTTP(t, env, cookie)
	if list.Meta.Total != 2 {
		t.Errorf("total = %d, want both locations kept", list.Meta.Total)
	}

	deleted := env.send(t, request{
		method: http.MethodPost,
		path:   "/api/v1/locations/bulk-delete",
		body: map[string]any{
			"ids": []string{empty.ID, empty.ID, "loc-missing"},
		},
		cookie:  cookie,
		headers: itemOrigin,
	})
	if deleted.Code != http.StatusOK {
		t.Fatalf("bulk delete without batches = %d, want 200: %s", deleted.Code, deleted.Body.String())
	}
	if count := decodeBody[map[string]int](t, deleted)["deleted"]; count != 1 {
		t.Errorf("deleted = %d, want 1: duplicates and missing ids are skipped", count)
	}

	list = listLocationsHTTP(t, env, cookie)
	if list.Meta.Total != 1 || !strings.EqualFold(list.Data[0].Name, "Fridge") {
		t.Errorf("locations = %+v, want only the populated fridge", list.Data)
	}
}
